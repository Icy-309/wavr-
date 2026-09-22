<?php
/**
 * Returns ICE servers (STUN + TURN) for WebRTC calls.
 * The Metered API key stays server-side; the client only ever sees the
 * short-lived TURN username/credential pair Metered hands back.
 * Configure via env: METERED_API_KEY (required), METERED_APP_NAME (optional, default "wavr").
 * Falls back to STUN-only if TURN isn't configured or the Metered API is unreachable —
 * calls still work P2P, just without a relay fallback for hard NATs.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/session.php';

$fallback = [
  ['urls' => 'stun:stun.l.google.com:19302'],
  ['urls' => 'stun:stun1.l.google.com:19302'],
  ['urls' => 'stun:stun2.l.google.com:19302'],
];

if(!validateSession()){
  http_response_code(401);
  echo json_encode(['iceServers' => $fallback]);
  exit;
}

$apiKey = getenv('METERED_API_KEY');
if(!$apiKey){
  echo json_encode(['iceServers' => $fallback]);
  exit;
}

$appName = getenv('METERED_APP_NAME') ?: 'wavr';
$url = "https://{$appName}.metered.live/api/v1/turn/credentials?apiKey=" . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 5,
  CURLOPT_SSL_VERIFYPEER => true,
]);
$body = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if($curlErr || !$body){
  echo json_encode(['iceServers' => $fallback]);
  exit;
}

$servers = json_decode($body, true);
if(!is_array($servers) || !count($servers)){
  echo json_encode(['iceServers' => $fallback]);
  exit;
}

echo json_encode(['iceServers' => $servers]);
