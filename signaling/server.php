<?php
/**
 * Wavr Signaling Server — Ratchet WebSocket
 * Run: php signaling/server.php
 * Requires: composer require cboden/ratchet
 * Deploy on VPS with: supervisor or screen
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../api/db.php';

// cboden/ratchet is unmaintained and predates PHP 8.4's nullable-type
// deprecations — silence those so real errors aren't buried in noise.
// Must come after requiring db.php, which sets error_reporting(E_ALL)
// itself (for the API scripts' own purposes) and would otherwise clobber this.
error_reporting(E_ALL & ~E_DEPRECATED);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\Server as SocketServer;

class WavrSignaling implements MessageComponentInterface {
  protected $clients; // address => connection
  protected $connMap; // resourceId => address
  protected $pdo;

  // ── Trench Rooms state ──
  // Audio is a full WebRTC mesh between room members (every member sends to
  // every other), so rooms are capped — past ~10 speakers each phone's
  // upload bandwidth becomes the bottleneck. Scaling further needs an SFU.
  const ROOM_MAX = 10;
  protected $rooms      = []; // roomId => [address => true]
  protected $memberRoom = []; // address => roomId
  protected $profiles   = []; // address => ['username'=>..,'avatar'=>..,'muted'=>bool]
  protected $lastChat   = []; // address => microtime of last chat message

  public function __construct(){
    $this->clients = [];
    $this->connMap = [];
    $this->pdo     = getDB();
    // Nobody can be in a room right after a restart.
    try { $this->pdo->exec("UPDATE rooms SET live_count=0 WHERE live_count<>0"); }
    catch(\Throwable $e){ echo "[Wavr] Could not reset room counts: ".$e->getMessage()."\n"; }
    echo "[Wavr] Signaling server started\n";
  }

  public function onOpen(ConnectionInterface $conn){
    $query = $conn->httpRequest->getUri()->getQuery();
    parse_str($query, $params);
    $address = trim($params['address'] ?? '');
    $token   = trim($params['token']   ?? '');

    // Reject immediately if either is missing
    if(!$address || !$token){
      echo "[Wavr] Rejected (no address/token)\n";
      $conn->send(json_encode(['type'=>'error','message'=>'Missing credentials']));
      $conn->close();
      return;
    }

    // Verify token matches the claimed address and hasn't expired.
    // This connection is reused for the server's entire uptime, so if it's
    // gone stale (pooler idle timeout, network blip) every auth check would
    // fail forever with no way to recover — reconnect once and retry before
    // giving up, instead of just logging and rejecting.
    try {
      $row = $this->authQuery($token, $address);
    } catch(\Throwable $e) {
      echo "[Wavr] DB error on auth (".$e->getMessage()."), reconnecting...\n";
      try {
        $this->pdo = getDB(true);
        $row = $this->authQuery($token, $address);
        echo "[Wavr] Reconnected OK\n";
      } catch(\Throwable $e2) {
        echo "[Wavr] DB reconnect failed: ".$e2->getMessage()."\n";
        $conn->send(json_encode(['type'=>'error','message'=>'Auth failed']));
        $conn->close();
        return;
      }
    }

    if(!$row){
      echo "[Wavr] Rejected (invalid token): $address\n";
      $conn->send(json_encode(['type'=>'error','message'=>'Unauthorized']));
      $conn->close();
      return;
    }

    // If this address is already connected (e.g. duplicate tab), close the old one
    if(isset($this->clients[$address])){
      echo "[Wavr] Replacing existing connection for: $address\n";
      $old = $this->clients[$address];
      unset($this->connMap[$old->resourceId]);
      $old->close();
      $this->leaveRoom($address);
    }

    $this->clients[$address]              = $conn;
    $this->connMap[$conn->resourceId]     = $address;
    echo "[Wavr] Authenticated: $address\n";
  }

  public function onMessage(ConnectionInterface $from, $data){
    $msg = json_decode($data, true);
    if(!$msg || !isset($msg['type'])) return;

    $fromAddr = $this->connMap[$from->resourceId] ?? null;

    // Only process messages from authenticated connections
    if(!$fromAddr){
      $from->send(json_encode(['type'=>'error','message'=>'Not authenticated']));
      return;
    }

    $to = $msg['to'] ?? null;

    // Diagnostic: confirming whether offer/answer/ice actually flow between
    // both sides was otherwise invisible — the only prior logging was
    // connect/disconnect, nothing about call-relevant message relay itself.
    if(in_array($msg['type'], ['offer','answer','ice','decline','end'])){
      $delivered = $to && isset($this->clients[$to]);
      echo "[Wavr] Relay {$msg['type']}: $fromAddr -> $to (" . ($delivered ? 'delivered' : 'TARGET NOT CONNECTED') . ")\n";
    }

    switch($msg['type']){
      case 'offer':
        $this->relay($to, [
          'type'          => 'incoming_call',
          'from'          => $fromAddr,
          'from_username' => $msg['from_username'] ?? null,
          'from_avatar'   => $msg['from_avatar']   ?? null,
          'offer'         => $msg['offer']
        ]);
        break;
      case 'answer':
        $this->relay($to, ['type'=>'call_answered','answer'=>$msg['answer'],'from'=>$fromAddr]);
        break;
      case 'ice':
        $this->relay($to, ['type'=>'ice_candidate','candidate'=>$msg['candidate'],'from'=>$fromAddr]);
        break;
      case 'decline':
        $this->relay($to, ['type'=>'call_declined','from'=>$fromAddr]);
        break;
      case 'ping':
        // App-level heartbeat from the client (see js/signaling.js) — keeps
        // the connection from sitting idle long enough for the Cloudflare
        // tunnel to silently close it. No relay needed, just reply.
        $from->send(json_encode(['type'=>'pong']));
        break;
      case 'end':
        $this->relay($to, ['type'=>'call_ended','from'=>$fromAddr]);
        break;

      // ── Trench Rooms ──
      case 'room_join':
        $this->joinRoom($from, $fromAddr, $msg['room_id'] ?? '', $msg['pass'] ?? '');
        break;
      case 'room_leave':
        $this->leaveRoom($fromAddr);
        break;
      case 'room_offer':
      case 'room_answer':
      case 'room_ice':
        // Only relay between two members of the same room.
        $roomId = $this->memberRoom[$fromAddr] ?? null;
        if($roomId && $to && isset($this->rooms[$roomId][$to])){
          $out = ['type'=>$msg['type'], 'from'=>$fromAddr];
          if($msg['type'] === 'room_ice') $out['candidate'] = $msg['candidate'] ?? null;
          else                            $out['sdp']       = $msg['sdp'] ?? null;
          $this->relay($to, $out);
        }
        break;
      case 'room_chat':
        $roomId = $this->memberRoom[$fromAddr] ?? null;
        $text   = trim(mb_substr((string)($msg['text'] ?? ''), 0, 500));
        if(!$roomId || $text === '') break;
        $now = microtime(true);
        if($now - ($this->lastChat[$fromAddr] ?? 0) < 0.7) break; // simple flood guard
        $this->lastChat[$fromAddr] = $now;
        $this->broadcastRoom($roomId, [
          'type'     => 'room_chat',
          'from'     => $fromAddr,
          'username' => $this->profiles[$fromAddr]['username'] ?? null,
          'text'     => $text,
          'ts'       => (int)($now * 1000),
        ]);
        break;
      case 'room_mute':
        $roomId = $this->memberRoom[$fromAddr] ?? null;
        if(!$roomId) break;
        $muted = !empty($msg['muted']);
        $this->profiles[$fromAddr]['muted'] = $muted;
        $this->broadcastRoom($roomId, ['type'=>'room_peer_muted','address'=>$fromAddr,'muted'=>$muted], $fromAddr);
        break;
    }
  }

  protected function joinRoom(ConnectionInterface $conn, $addr, $roomId, $pass){
    if(!preg_match('/^[0-9a-f-]{36}$/', $roomId) || !$pass){
      $conn->send(json_encode(['type'=>'room_error','message'=>'Invalid room or pass']));
      return;
    }
    if(count($this->rooms[$roomId] ?? []) >= self::ROOM_MAX && !isset($this->rooms[$roomId][$addr])){
      $conn->send(json_encode(['type'=>'room_error','message'=>'Room is full']));
      return;
    }

    // Passes are single-use and short-lived; api/rooms.php only issues one
    // after checking the wallet's on-chain balance against the room's gate.
    try {
      $row = $this->withDB(function($pdo) use ($pass, $addr, $roomId){
        $s = $pdo->prepare("DELETE FROM room_passes
                            WHERE token=? AND wallet_address=? AND room_id=? AND expires_at > NOW()
                            RETURNING room_id");
        $s->execute([$pass, $addr, $roomId]);
        if(!$s->fetch()) return null;
        $u = $pdo->prepare("SELECT username, avatar_url FROM users WHERE wallet_address=?");
        $u->execute([$addr]);
        return $u->fetch() ?: ['username'=>null, 'avatar_url'=>null];
      });
    } catch(\Throwable $e){
      echo "[Wavr] Room join DB error: ".$e->getMessage()."\n";
      $conn->send(json_encode(['type'=>'room_error','message'=>'Server error']));
      return;
    }
    if(!$row){
      $conn->send(json_encode(['type'=>'room_error','message'=>'Pass invalid or expired — rejoin']));
      return;
    }

    $this->leaveRoom($addr);
    $this->profiles[$addr] = ['username'=>$row['username'], 'avatar'=>$row['avatar_url'], 'muted'=>true];

    $peers = [];
    foreach(array_keys($this->rooms[$roomId] ?? []) as $a) $peers[] = $this->peerInfo($a);

    $this->rooms[$roomId][$addr] = true;
    $this->memberRoom[$addr]     = $roomId;

    $conn->send(json_encode(['type'=>'room_joined','room_id'=>$roomId,'peers'=>$peers]));
    $this->broadcastRoom($roomId, ['type'=>'room_peer_joined','peer'=>$this->peerInfo($addr)], $addr);
    $this->updateLiveCount($roomId);
    echo "[Wavr] Room join: $addr -> $roomId (".count($this->rooms[$roomId])." in room)\n";
  }

  protected function leaveRoom($addr){
    $roomId = $this->memberRoom[$addr] ?? null;
    if(!$roomId) return;
    unset($this->memberRoom[$addr], $this->rooms[$roomId][$addr], $this->lastChat[$addr]);
    if(empty($this->rooms[$roomId])) unset($this->rooms[$roomId]);
    else $this->broadcastRoom($roomId, ['type'=>'room_peer_left','address'=>$addr]);
    $this->updateLiveCount($roomId);
    echo "[Wavr] Room leave: $addr <- $roomId\n";
  }

  protected function peerInfo($addr){
    $p = $this->profiles[$addr] ?? [];
    return ['address'=>$addr, 'username'=>$p['username'] ?? null, 'avatar'=>$p['avatar'] ?? null, 'muted'=>$p['muted'] ?? true];
  }

  protected function broadcastRoom($roomId, $payload, $except = null){
    foreach(array_keys($this->rooms[$roomId] ?? []) as $a){
      if($a !== $except) $this->relay($a, $payload);
    }
  }

  protected function updateLiveCount($roomId){
    $n = count($this->rooms[$roomId] ?? []);
    try {
      $this->withDB(function($pdo) use ($roomId, $n){
        $pdo->prepare("UPDATE rooms SET live_count=?, last_active=NOW() WHERE id=?")->execute([$n, $roomId]);
      });
    } catch(\Throwable $e){
      echo "[Wavr] live_count update failed: ".$e->getMessage()."\n";
    }
  }

  // Runs a DB operation, reconnecting once on failure — same stale-connection
  // reasoning as the auth path in onOpen().
  protected function withDB(callable $fn){
    try { return $fn($this->pdo); }
    catch(\Throwable $e){
      $this->pdo = getDB(true);
      return $fn($this->pdo);
    }
  }

  protected function relay($address, $payload){
    if($address && isset($this->clients[$address])){
      $this->clients[$address]->send(json_encode($payload));
    }
    // If target is offline, silently drop — client handles timeout
  }

  // Periodic keepalive (wired up below via a loop timer) so an idle
  // connection gets caught and refreshed proactively, rather than only
  // discovering it's dead the next time someone tries to log in.
  public function pingDB(){
    try {
      $this->pdo->query('SELECT 1');
    } catch(\Throwable $e){
      echo "[Wavr] DB keepalive ping failed, reconnecting...\n";
      try { $this->pdo = getDB(true); echo "[Wavr] Reconnected OK\n"; }
      catch(\Throwable $e2){ echo "[Wavr] Reconnect failed: ".$e2->getMessage()."\n"; }
    }
  }

  protected function authQuery($token, $address){
    $stmt = $this->pdo->prepare(
      "SELECT wallet_address FROM sessions
       WHERE token = ? AND wallet_address = ? AND expires_at > NOW()
       LIMIT 1"
    );
    $stmt->execute([$token, $address]);
    return $stmt->fetch();
  }

  public function onClose(ConnectionInterface $conn){
    $addr = $this->connMap[$conn->resourceId] ?? null;
    if($addr){
      unset($this->clients[$addr]);
      $this->leaveRoom($addr);
    }
    unset($this->connMap[$conn->resourceId]);
    echo "[Wavr] Disconnected: ".($addr ?? 'unknown')."\n";
  }

  public function onError(ConnectionInterface $conn, \Exception $e){
    echo "[Wavr] Error: ".$e->getMessage()."\n";
    $conn->close();
  }
}

$port = isset($argv[1]) ? (int)$argv[1] : 8080;
echo "[Wavr] Starting on port $port...\n";

// Built manually (rather than via IoServer::factory(), which always creates
// its own internal loop with no way to get a reference to it) so the DB
// keepalive timer below runs on the same loop actually driving the server.
$loop = Loop::get();
$signaling = new WavrSignaling();
$loop->addPeriodicTimer(60, fn() => $signaling->pingDB());

$socket = new SocketServer('0.0.0.0:' . $port, $loop);
$server = new IoServer(new HttpServer(new WsServer($signaling)), $socket, $loop);
$server->run();
