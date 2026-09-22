<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if($_SERVER['REQUEST_METHOD']==='GET' && $action==='nonce'){
  $address = trim($_GET['address'] ?? '');
  if(!$address){ echo json_encode(['error'=>'Missing address']); exit; }

  // Validate address is plausible Solana base58 (32–44 chars)
  if(!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)){
    echo json_encode(['error'=>'Invalid wallet address']); exit;
  }

  $nonce   = bin2hex(random_bytes(16));
  $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
  $pdo     = getDB();

  // Clean up expired nonces + any existing nonce for this address
  $pdo->prepare("DELETE FROM sessions WHERE token LIKE 'nonce_%' AND expires_at < NOW()")->execute();
  $pdo->prepare("DELETE FROM sessions WHERE wallet_address=? AND token LIKE 'nonce_%'")->execute([$address]);
  $pdo->prepare("INSERT INTO sessions (wallet_address,token,expires_at) VALUES (?,?,?)")
      ->execute([$address, 'nonce_'.$nonce, $expires]);

  echo json_encode(['nonce'=>$nonce]);
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $body = json_decode(file_get_contents('php://input'), true);
  $wallet = trim($body['wallet_address'] ?? '');
  $sig    = trim($body['signature'] ?? '');
  $nonce  = trim($body['nonce'] ?? '');

  if(!$wallet||!$sig||!$nonce){echo json_encode(['error'=>'Missing fields']);exit;}

  // Validate wallet address is plausible base58 Solana address (32-44 chars)
  if(!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)){
    echo json_encode(['error'=>'Invalid wallet address']); exit;
  }

  // Verify nonce exists and not expired
  $pdo = getDB();
  $stmt = $pdo->prepare("SELECT id FROM sessions WHERE wallet_address=? AND token=? AND expires_at > NOW()");
  $stmt->execute([$wallet, 'nonce_'.$nonce]);
  if(!$stmt->fetch()){echo json_encode(['error'=>'Invalid or expired nonce']);exit;}

  // Verify Ed25519 signature — sodium is REQUIRED, no fallback
  if(!function_exists('sodium_crypto_sign_verify_detached')){
    echo json_encode(['error'=>'Server misconfigured: sodium PHP extension missing. Run: apt install php-sodium']);
    exit;
  }

  $message     = "Sign in to Wavr: $nonce";
  $sigBytes    = hex2bin($sig);

  if($sigBytes === false || strlen($sigBytes) !== 64){
    echo json_encode(['error'=>'Invalid signature format']); exit;
  }

  $pubKeyBytes = base58_decode($wallet);

  if(!$pubKeyBytes || strlen($pubKeyBytes) !== 32){
    echo json_encode(['error'=>'Invalid public key']); exit;
  }

  $valid = false;
  try {
    $valid = sodium_crypto_sign_verify_detached($sigBytes, $message, $pubKeyBytes);
  } catch(\Throwable $e){
    $valid = false;
  }

  if(!$valid){echo json_encode(['error'=>'Invalid signature']);exit;}

  // Clean up nonce
  $pdo->prepare("DELETE FROM sessions WHERE wallet_address=? AND token=?")->execute([$wallet,'nonce_'.$nonce]);

  // Create session token
  $token = bin2hex(random_bytes(32));
  $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
  $pdo->prepare("INSERT INTO sessions (wallet_address,token,expires_at) VALUES (?,?,?) ON CONFLICT(token) DO NOTHING")
      ->execute([$wallet,$token,$expires]);

  // Upsert user last_seen
  $pdo->prepare("INSERT INTO users (wallet_address,last_seen) VALUES (?,NOW()) ON CONFLICT(wallet_address) DO UPDATE SET last_seen=NOW()")
      ->execute([$wallet]);

  // Check if user has username
  $u = $pdo->prepare("SELECT username, avatar_url FROM users WHERE wallet_address=?");
  $u->execute([$wallet]);
  $user = $u->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    'token'      => $token,
    'username'   => $user['username']   ?? null,
    'avatar_url' => $user['avatar_url'] ?? null,
  ]);
  exit;
}

echo json_encode(['error'=>'Not found']);

// Base58 decode for Solana public keys — no GMP required
function base58_decode($input){
  $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $base     = 58;
  $decoded  = [0];
  $leadingZeros = 0;

  for($i=0; $i<strlen($input); $i++){
    $char = $input[$i];
    $pos  = strpos($alphabet, $char);
    if($pos === false) return false;
    if($pos === 0 && count($decoded)===1 && $decoded[0]===0) $leadingZeros++;

    // Multiply existing bytes by 58, add new digit
    $carry = $pos;
    for($j = count($decoded)-1; $j >= 0; $j--){
      $val        = $decoded[$j] * $base + $carry;
      $decoded[$j] = $val & 0xFF;
      $carry       = $val >> 8;
    }
    while($carry > 0){
      array_unshift($decoded, $carry & 0xFF);
      $carry >>= 8;
    }
  }

  $bytes = array_merge(array_fill(0, $leadingZeros, 0), $decoded);
  return implode('', array_map('chr', $bytes));
}
