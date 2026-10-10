<?php
/**
 * Trench Rooms — token-gated live audio rooms.
 *
 * GET  /api/rooms.php                    list rooms (live first); ?mint= filters to one token
 * GET  /api/rooms.php?action=get&id=     one room + live market stats
 * GET  /api/rooms.php?action=token&mint= token info for the create form
 * POST /api/rooms.php?action=create      {mint, title, min_balance}
 * POST /api/rooms.php?action=join        {room_id} → verifies on-chain balance, returns a pass
 *                                        the signaling server consumes on room_join
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/solana.php';

const MAX_ROOMS_PER_CREATOR = 5;
const PASS_TTL_SECONDS      = 120;

$pdo    = getDB();
$action = $_GET['action'] ?? '';

function fail($code, $msg, $extra = []){
  http_response_code($code);
  echo json_encode(array_merge(['error'=>$msg], $extra));
  exit;
}

function roomRow($r){
  return [
    'id'           => $r['id'],
    'mint'         => $r['mint'],
    'title'        => $r['title'],
    'token_symbol' => $r['token_symbol'],
    'token_name'   => $r['token_name'],
    'token_image'  => $r['token_image'],
    'min_balance'  => (float)$r['min_balance'],
    'creator'      => $r['creator_address'],
    'creator_username' => $r['creator_username'] ?? null,
    'live_count'   => (int)$r['live_count'],
    'created_at'   => $r['created_at'],
  ];
}

if($_SERVER['REQUEST_METHOD']==='GET'){
  if(!validateSession()) fail(401, 'Unauthorized');

  if($action === 'get'){
    $id = $_GET['id'] ?? '';
    if(!preg_match('/^[0-9a-f-]{36}$/', $id)) fail(400, 'Invalid room id');
    $s = $pdo->prepare("SELECT r.*, u.username AS creator_username FROM rooms r
                        LEFT JOIN users u ON u.wallet_address=r.creator_address WHERE r.id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    if(!$r) fail(404, 'Room not found');
    echo json_encode(['room'=>roomRow($r), 'market'=>getTokenMarket($r['mint'])]);
    exit;
  }

  if($action === 'token'){
    $mint = trim($_GET['mint'] ?? '');
    if(!preg_match(WAVR_ADDR_RE, $mint)) fail(400, 'Invalid token address');
    $market = getTokenMarket($mint);
    if(!$market){
      try { if(!isTokenMint($mint)) fail(404, 'Not a Solana token mint'); }
      catch(\Throwable $e){ fail(502, 'Could not reach Solana RPC'); }
    }
    echo json_encode(['mint'=>$mint, 'market'=>$market]);
    exit;
  }

  $mint = trim($_GET['mint'] ?? '');
  if($mint !== ''){
    if(!preg_match(WAVR_ADDR_RE, $mint)) fail(400, 'Invalid token address');
    $s = $pdo->prepare("SELECT r.*, u.username AS creator_username FROM rooms r
                        LEFT JOIN users u ON u.wallet_address=r.creator_address
                        WHERE r.mint=? ORDER BY r.live_count DESC, r.last_active DESC LIMIT 50");
    $s->execute([$mint]);
  } else {
    $s = $pdo->query("SELECT r.*, u.username AS creator_username FROM rooms r
                      LEFT JOIN users u ON u.wallet_address=r.creator_address
                      ORDER BY r.live_count DESC, r.last_active DESC LIMIT 50");
  }
  $rooms = array_map('roomRow', $s->fetchAll());
  // Live price / 24h change for every token on screen, in one upstream call.
  $markets = getTokenMarkets(array_column($rooms, 'mint'));
  echo json_encode(['rooms'=>$rooms, 'markets'=>(object)$markets]);
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $addr = validateSession();
  if(!$addr) fail(401, 'Unauthorized');
  $body = json_decode(file_get_contents('php://input'), true) ?: [];

  if($action === 'create'){
    $mint  = trim($body['mint'] ?? '');
    $title = trim(preg_replace('/\s+/', ' ', $body['title'] ?? ''));
    $min   = (float)($body['min_balance'] ?? 0);

    if(!preg_match(WAVR_ADDR_RE, $mint)) fail(400, 'Invalid token address');
    if(mb_strlen($title) < 3 || mb_strlen($title) > 60) fail(400, 'Title must be 3–60 characters');
    if(!is_finite($min) || $min < 0 || $min > 1e15) fail(400, 'Invalid minimum balance');

    $c = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE creator_address=?");
    $c->execute([$addr]);
    if((int)$c->fetchColumn() >= MAX_ROOMS_PER_CREATOR) fail(429, 'You can open at most '.MAX_ROOMS_PER_CREATOR.' rooms');

    $market = getTokenMarket($mint);
    try {
      if(!$market && !isTokenMint($mint)) fail(404, 'Not a Solana token mint');
      // Creators must actually hold the bag — stops spam rooms for random tokens
      // and means the host passes their own gate.
      $bal = getTokenBalance($addr, $mint);
    } catch(\Throwable $e){
      fail(502, 'Could not reach Solana RPC');
    }
    if($bal <= 0 || $bal < $min){
      fail(403, 'You must hold this token (at least the room minimum) to open a room', ['balance'=>$bal]);
    }

    $s = $pdo->prepare("INSERT INTO rooms (mint,title,token_symbol,token_name,token_image,min_balance,creator_address)
                        VALUES (?,?,?,?,?,?,?) RETURNING id");
    $s->execute([$mint, $title, $market['symbol'] ?? null, $market['name'] ?? null,
                 $market['image'] ?? null, $min, $addr]);
    echo json_encode(['success'=>true, 'id'=>$s->fetchColumn()]);
    exit;
  }

  if($action === 'join'){
    $id = $body['room_id'] ?? '';
    if(!preg_match('/^[0-9a-f-]{36}$/', $id)) fail(400, 'Invalid room id');
    $s = $pdo->prepare("SELECT * FROM rooms WHERE id=?");
    $s->execute([$id]);
    $room = $s->fetch();
    if(!$room) fail(404, 'Room not found');

    try { $bal = getTokenBalance($addr, $room['mint']); }
    catch(\Throwable $e){ fail(502, 'Could not verify your balance — Solana RPC unreachable'); }

    $min = (float)$room['min_balance'];
    // A 0-minimum room still requires holding some of the token.
    if($bal <= 0 || $bal < $min){
      fail(403, 'Not enough tokens to enter', [
        'gated'    => true,
        'required' => $min,
        'balance'  => $bal,
        'symbol'   => $room['token_symbol'],
        'mint'     => $room['mint'],
      ]);
    }

    $pdo->prepare("DELETE FROM room_passes WHERE expires_at < NOW()")->execute();
    $pass = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO room_passes (token,room_id,wallet_address,expires_at) VALUES (?,?,?,?)")
        ->execute([$pass, $id, $addr, date('Y-m-d H:i:s', time() + PASS_TTL_SECONDS)]);
    echo json_encode(['pass'=>$pass, 'balance'=>$bal]);
    exit;
  }
}

fail(404, 'Not found');
