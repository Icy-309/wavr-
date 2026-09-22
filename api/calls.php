<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$pdo = getDB();

if($_SERVER['REQUEST_METHOD']==='GET'){
  $addr = validateSession();
  if(!$addr){echo json_encode(['error'=>'Unauthorized']);exit;}

  $stmt = $pdo->prepare("
    SELECT c.*,
      u1.username as caller_username,
      u2.username as callee_username
    FROM call_logs c
    LEFT JOIN users u1 ON u1.wallet_address=c.caller_address
    LEFT JOIN users u2 ON u2.wallet_address=c.callee_address
    WHERE c.caller_address=? OR c.callee_address=?
    ORDER BY c.started_at DESC LIMIT 30
  ");
  $stmt->execute([$addr,$addr]);
  echo json_encode(['calls'=>$stmt->fetchAll()]);
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $addr = validateSession();
  if(!$addr){echo json_encode(['error'=>'Unauthorized']);exit;}

  $body   = json_decode(file_get_contents('php://input'),true);
  $callee = trim($body['callee_address'] ?? '');
  $status = in_array($body['status']??'', ['completed','missed','declined']) ? $body['status'] : 'completed';
  $dur    = min(max(0, intval($body['duration'] ?? 0)), 86400); // cap at 24h

  if(!$callee){ echo json_encode(['error'=>'Missing callee']); exit; }

  // Validate callee is a plausible Solana address
  if(!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $callee)){
    echo json_encode(['error'=>'Invalid callee address']); exit;
  }

  $now   = date('Y-m-d H:i:s');
  $ended = date('Y-m-d H:i:s', time() + $dur);
  $pdo->prepare("INSERT INTO call_logs (caller_address,callee_address,status,started_at,ended_at) VALUES (?,?,?,?,?)")
      ->execute([$addr,$callee,$status,$now,$ended]);
  echo json_encode(['success'=>true]);
  exit;
}

echo json_encode(['error'=>'Not found']);
