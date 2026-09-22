<?php
// session.php — exports validateSession() only.
// Do NOT add top-level code here — this file is require_once'd by other endpoints.

require_once __DIR__ . '/db.php';

/**
 * Returns wallet_address string if token is valid, or false.
 */
function validateSession(){
  $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  $token = trim(str_replace('Bearer', '', $auth));
  if(!$token) return false;

  $pdo  = getDB();
  $stmt = $pdo->prepare(
    "SELECT wallet_address FROM sessions WHERE token=? AND expires_at > NOW() LIMIT 1"
  );
  $stmt->execute([$token]);
  $row = $stmt->fetch();
  return $row ? $row['wallet_address'] : false;
}

// When called directly as an endpoint (GET /api/session.php), return validation JSON
if(basename($_SERVER['SCRIPT_FILENAME']) === 'session.php'){
  header('Content-Type: application/json');
  header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
  header('Access-Control-Allow-Headers: Authorization');
  if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){ http_response_code(204); exit; }

  $wallet = validateSession();
  echo json_encode($wallet ? ['valid'=>true,'wallet_address'=>$wallet] : ['valid'=>false]);
}
