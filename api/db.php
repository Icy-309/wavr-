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

/**
 * @param bool $forceNew Bypass the cached connection and open a fresh one.
 *   Needed by the long-running signaling server: a normal PHP request is a
 *   fresh process every time so the static cache never goes stale, but the
 *   signaling server reuses one process (and one cached connection) for its
 *   entire uptime — if the DB drops an idle connection (pooler timeout,
 *   network blip), every future auth check would fail forever without a way
 *   to force a reconnect.
 *
 * Throws instead of die()-ing on failure — die() here would be fine for a
 * one-shot API request, but it would kill the entire signaling server
 * process on a transient DB hiccup. api/*.php callers don't need their own
 * try/catch: the global set_exception_handler above already turns an
 * uncaught throwable into a clean JSON error response.
 */
function getDB($forceNew = false){
  static $pdo = null;
  if($pdo && !$forceNew) return $pdo;

  $url = getenv('DATABASE_URL') ?: getenv('SUPABASE_DB_URL');
  if(!$url) throw new RuntimeException('DATABASE_URL not configured');

  // Parse postgres:// URL → PDO DSN
  // Supabase format: postgres://postgres:[pass]@db.[ref].supabase.co:5432/postgres
  $p = parse_url($url);
  if(!$p || empty($p['host'])) throw new RuntimeException('Invalid DATABASE_URL format');

  $host   = $p['host'];
  $port   = $p['port']  ?? 5432;
  $dbname = ltrim($p['path'] ?? '/postgres', '/');
  $user   = urldecode($p['user'] ?? 'postgres');
  $pass   = urldecode($p['pass'] ?? '');

  $sslmode = getenv("DB_SSLMODE") ?: "require"; // use prefer for local dev
  $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

  $opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Emulated (client-side, still properly quoted) prepares: one round trip per
    // query instead of two (prepare + execute). Matters a lot far from the DB.
    PDO::ATTR_EMULATE_PREPARES   => true,
  ];

  // Persistent connections: the TLS + auth handshake to Supabase's pooler
  // costs ~1s from far away, and signaling (api/signal.php) is now many
  // small requests, so reconnecting on every one made call setup sluggish.
  // A reused connection may have been dropped by the pooler while idle, so
  // ping it once and fall back to a fresh one. Set DB_PERSISTENT=0 to disable.
  if(!$forceNew && getenv('DB_PERSISTENT') !== '0'){
    try {
      $pdo = new PDO($dsn, $user, $pass, $opts + [PDO::ATTR_PERSISTENT => true]);
      $pdo->query('SELECT 1');
      return $pdo;
    } catch(\Throwable $e){
      $pdo = null;
    }
  }

  $pdo = new PDO($dsn, $user, $pass, $opts);
  return $pdo;
}

