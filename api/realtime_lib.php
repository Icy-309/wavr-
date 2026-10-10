<?php
// realtime_lib.php — Supabase Realtime helpers (replaces the old Ratchet signaling server).
// Do NOT add top-level code here — this file is require_once'd by other endpoints.
//
// Model: every client LISTENS on private Realtime channels it is authorized
// for (its own `inbox:<wallet>`, plus `room:<id>` while it's a verified room
// member; see the RLS policy in schema.sql). Clients never broadcast
// directly: every message goes through api/signal.php, which checks the
// session, stamps the true sender, and publishes with realtime.send().
// So nobody can spoof a caller or talk in a room they didn't pass the gate for.

require_once __DIR__ . '/db.php';

// A member who hasn't heartbeated in this long is treated as gone
// (crashed tab, lost network). Clients heartbeat every 25s.
const ROOM_STALE_SECONDS = 70;
const ROOM_MAX           = 10;   // P2P mesh: every member uploads to every other member

function rtSend(PDO $pdo, $topic, array $payload){
  $pdo->prepare("SELECT realtime.send(CAST(? AS jsonb), 'sig', ?, true)")
      ->execute([json_encode($payload), $topic]);
}

function rtInbox(PDO $pdo, $wallet, array $payload){ rtSend($pdo, 'inbox:' . $wallet, $payload); }
function rtRoom(PDO $pdo, $roomId, array $payload){ rtSend($pdo, 'room:' . $roomId, $payload); }

function profileOf(PDO $pdo, $wallet){
  $s = $pdo->prepare("SELECT username, avatar_url FROM users WHERE wallet_address=?");
  $s->execute([$wallet]);
  $u = $s->fetch() ?: [];
  return ['username' => $u['username'] ?? null, 'avatar' => $u['avatar_url'] ?? null];
}

/** Room id the wallet is currently (freshly) in, or null. */
function currentRoom(PDO $pdo, $wallet){
  $s = $pdo->prepare("SELECT room_id FROM room_members
                      WHERE wallet_address=? AND last_seen > NOW() - make_interval(secs => ?)");
  $s->execute([$wallet, ROOM_STALE_SECONDS]);
  $id = $s->fetchColumn();
  return $id ?: null;
}

/** Fresh members of a room, with profiles. */
function roomPeers(PDO $pdo, $roomId, $exceptWallet = null){
  $s = $pdo->prepare("SELECT m.wallet_address, m.muted, u.username, u.avatar_url
                      FROM room_members m LEFT JOIN users u ON u.wallet_address = m.wallet_address
                      WHERE m.room_id=? AND m.last_seen > NOW() - make_interval(secs => ?)
                      ORDER BY m.joined_at");
  $s->execute([$roomId, ROOM_STALE_SECONDS]);
  $out = [];
  foreach($s->fetchAll() as $r){
    if($r['wallet_address'] === $exceptWallet) continue;
    $out[] = ['address'=>$r['wallet_address'], 'username'=>$r['username'], 'avatar'=>$r['avatar_url'], 'muted'=>(bool)$r['muted']];
  }
  return $out;
}

function leaveRoom(PDO $pdo, $wallet){
  $s = $pdo->prepare("DELETE FROM room_members WHERE wallet_address=? RETURNING room_id");
  $s->execute([$wallet]);
  $roomId = $s->fetchColumn();
  if($roomId) rtRoom($pdo, $roomId, ['type'=>'room_peer_left', 'address'=>$wallet]);
  return $roomId ?: null;
}

/** Drop members of $roomId whose heartbeat stopped, telling everyone else. */
function pruneRoom(PDO $pdo, $roomId){
  $s = $pdo->prepare("DELETE FROM room_members
                      WHERE room_id=? AND last_seen <= NOW() - make_interval(secs => ?)
                      RETURNING wallet_address");
  $s->execute([$roomId, ROOM_STALE_SECONDS]);
  foreach($s->fetchAll(PDO::FETCH_COLUMN) as $gone){
    rtRoom($pdo, $roomId, ['type'=>'room_peer_left', 'address'=>$gone]);
  }
}

// ── Realtime JWT ──
// Supabase Realtime authorizes private channels from a JWT. Our users sign
// in with their wallet (not Supabase Auth), so we mint the JWT ourselves,
// signed with the project's JWT secret, with sub = wallet address. The RLS
// policy reads auth.jwt()->>'sub' (never auth.uid(): a wallet isn't a uuid).

function b64url($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function mintRealtimeJwt($wallet, $ttl = 3600){
  $secret = getenv('SUPABASE_JWT_SECRET');
  if(!$secret) throw new RuntimeException('SUPABASE_JWT_SECRET not configured');
  $now = time();
  $h = b64url(json_encode(['alg'=>'HS256', 'typ'=>'JWT']));
  $p = b64url(json_encode([
    'sub'  => $wallet,
    'role' => 'authenticated',
    'aud'  => 'authenticated',
    'iat'  => $now,
    'exp'  => $now + $ttl,
  ]));
  $sig = b64url(hash_hmac('sha256', "$h.$p", $secret, true));
  return ["$h.$p.$sig", $now + $ttl];
}
