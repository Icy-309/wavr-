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

  public function __construct(){
    $this->clients = [];
    $this->connMap = [];
    $this->pdo     = getDB();
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
    if($addr) unset($this->clients[$addr]);
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
