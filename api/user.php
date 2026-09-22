<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php'; // provides validateSession()

$pdo = getDB();

// GET ?check=username — availability check
if(isset($_GET['check'])){
  $u = strtolower(trim($_GET['check']));
  if(!preg_match('/^[a-z0-9_]{3,20}$/',$u)){echo json_encode(['available'=>false,'reason'=>'Invalid format']);exit;}
  $s = $pdo->prepare("SELECT id FROM users WHERE username=?");
  $s->execute([$u]);
  echo json_encode(['available'=>!$s->fetch()]);
  exit;
}

// GET ?username=username — lookup user
if(isset($_GET['username'])){
  $u = strtolower(trim($_GET['username']));
  $s = $pdo->prepare("SELECT username, wallet_address, avatar_url FROM users WHERE username=?");
  $s->execute([$u]);
  $user = $s->fetch();
  echo json_encode($user ? ['found'=>true,'user'=>$user] : ['found'=>false]);
  exit;
}

// POST — register username
if($_SERVER['REQUEST_METHOD']==='POST'){
  $walletAddr = validateSession();
  if(!$walletAddr){echo json_encode(['error'=>'Unauthorized']);exit;}

  $body = json_decode(file_get_contents('php://input'),true);
  $username = strtolower(trim($body['username']??''));

  if(!preg_match('/^[a-z0-9_]{3,20}$/',$username)){echo json_encode(['error'=>'Invalid username format']);exit;}

  // Check existing
  $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
  $check->execute([$username]);
  if($check->fetch()){echo json_encode(['error'=>'Username taken']);exit;}

  // Check if user already has one
  $existing = $pdo->prepare("SELECT username FROM users WHERE wallet_address=?");
  $existing->execute([$walletAddr]);
  $row = $existing->fetch();
  if($row && $row['username']){echo json_encode(['error'=>'Username already set']);exit;}

  $pdo->prepare("UPDATE users SET username=? WHERE wallet_address=?")->execute([$username,$walletAddr]);
  echo json_encode(['success'=>true,'username'=>$username]);
  exit;
}

echo json_encode(['error'=>'Not found']);
