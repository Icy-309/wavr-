<?php
/**
 * GET /api/realtime.php → Supabase Realtime connection details for the client:
 * project URL, public (anon/publishable) key, and a 1-hour JWT that lets this
 * wallet listen on its own private channels. Clients refresh it before expiry.
 *
 * Env: SUPABASE_URL, SUPABASE_ANON_KEY, SUPABASE_JWT_SECRET
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/realtime_lib.php';

$wallet = validateSession();
if(!$wallet){ http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$url = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$key = getenv('SUPABASE_ANON_KEY') ?: '';
if(!$url || !$key){ http_response_code(500); echo json_encode(['error'=>'Realtime not configured (SUPABASE_URL / SUPABASE_ANON_KEY)']); exit; }

[$jwt, $exp] = mintRealtimeJwt($wallet);
echo json_encode(['url'=>$url, 'anon_key'=>$key, 'jwt'=>$jwt, 'expires_at'=>$exp]);
