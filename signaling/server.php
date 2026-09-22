<?php
/**
 * Wavr Signaling Server — Ratchet WebSocket
 * Run: php signaling/server.php
 * Requires: composer require cboden/ratchet
 * Deploy on VPS with: supervisor or screen
 */

// cboden/ratchet is unmaintained and predates PHP 8.4's nullable-type deprecations —
// silence those so real errors aren't buried in noise.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../api/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

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

    // Verify token matches the claimed address and hasn't expired
    try {
      $stmt = $this->pdo->prepare(
        "SELECT wallet_address FROM sessions
         WHERE token = ? AND wallet_address = ? AND expires_at > NOW()
         LIMIT 1"
      );
      $stmt->execute([$token, $address]);
      $row = $stmt->fetch();
    } catch(\Exception $e) {
      echo "[Wavr] DB error on auth: ".$e->getMessage()."\n";
      $conn->send(json_encode(['type'=>'error','message'=>'Auth failed']));
      $conn->close();
      return;
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

  // Re-ping DB to keep connection alive (call periodically via timer if needed)
  protected function pingDB(){
    try {
      $this->pdo->query('SELECT 1');
    } catch(\Exception $e){
      echo "[Wavr] DB ping failed, reconnecting...\n";
      try { $this->pdo = getDB(); } catch(\Exception $e2){}
    }
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
$server = IoServer::factory(
  new HttpServer(new WsServer(new WavrSignaling())),
  $port
);
$server->run();
