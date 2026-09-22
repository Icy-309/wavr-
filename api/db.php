<?php
// Every endpoint promises JSON. A stray PHP warning/notice (display_errors=On)
// or an uncaught Error would otherwise leak raw HTML into the response and
// break the client's response.json() with "Unexpected token" — so warnings
// go to the log instead of stdout, and any truly uncaught throwable still
// resolves to valid JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function($e){
  error_log('[Wavr] Uncaught: ' . $e);
  if(!headers_sent()){
    http_response_code(500);
    header('Content-Type: application/json');
  }
  echo json_encode(['error' => 'Server error']);
});

function getDB(){
  static $pdo = null;
  if($pdo) return $pdo;

  $url = getenv('DATABASE_URL') ?: getenv('SUPABASE_DB_URL');
  if(!$url){
    http_response_code(500);
    die(json_encode(['error'=>'DATABASE_URL not configured']));
  }

  // Parse postgres:// URL → PDO DSN
  // Supabase format: postgres://postgres:[pass]@db.[ref].supabase.co:5432/postgres
  $p = parse_url($url);
  if(!$p || empty($p['host'])){
    http_response_code(500);
    die(json_encode(['error'=>'Invalid DATABASE_URL format']));
  }

  $host   = $p['host'];
  $port   = $p['port']  ?? 5432;
  $dbname = ltrim($p['path'] ?? '/postgres', '/');
  $user   = urldecode($p['user'] ?? 'postgres');
  $pass   = urldecode($p['pass'] ?? '');

  $sslmode = getenv("DB_SSLMODE") ?: "require"; // use prefer for local dev
  $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

  try {
    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  } catch(PDOException $e){
    http_response_code(500);
    die(json_encode(['error'=>'DB connection failed: '.$e->getMessage()]));
  }

  return $pdo;
}

