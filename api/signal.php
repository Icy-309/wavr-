<?php
/**
 * POST /api/signal.php  {messages: [{type, to?, ...}, ...]}
 *
 * The send half of signaling (the receive half is Supabase Realtime, see
 * realtime_lib.php). Clients batch messages in order; each is validated and
 * published with the sender stamped from the session, never trusted from
 * the client. Message types and output shapes match what the old Ratchet
 * server emitted, so js/webrtc.js and js/room.js handle them unchanged.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/realtime_lib.php';

const ADDR_RE      = '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/';
const MAX_BATCH    = 40;
const MAX_MSG_JSON = 24000;   // an SDP is a few KB; anything far bigger is abuse

$me = validateSession();
if(!$me){ http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$msgs = is_array($body['messages'] ?? null) ? array_slice($body['messages'], 0, MAX_BATCH) : [];
$pdo  = getDB();
$errors = [];

// Lazily looked up once per request: most batches are ICE candidates for one peer.
$myProfile = null;
$myRoom    = false;
$room = function() use (&$myRoom, $pdo, $me){
  if($myRoom === false) $myRoom = currentRoom($pdo, $me);
  return $myRoom;
};

foreach($msgs as $m){
  if(!is_array($m) || !is_string($m['type'] ?? null)) continue;
  if(strlen(json_encode($m)) > MAX_MSG_JSON){ $errors[] = 'Message too large'; continue; }
  $type = $m['type'];
  $to   = is_string($m['to'] ?? null) && preg_match(ADDR_RE, $m['to']) ? $m['to'] : null;

  switch($type){
    // ── 1:1 calls ──
    case 'offer':
      if(!$to) break;
      $myProfile = $myProfile ?? profileOf($pdo, $me);
      rtInbox($pdo, $to, [
        'type'=>'incoming_call', 'from'=>$me,
        'from_username'=>$myProfile['username'], 'from_avatar'=>$myProfile['avatar'],
        'offer'=>$m['offer'] ?? null,
      ]);
      break;
    case 'answer':  if($to) rtInbox($pdo, $to, ['type'=>'call_answered', 'answer'=>$m['answer'] ?? null, 'from'=>$me]); break;
    case 'ice':     if($to) rtInbox($pdo, $to, ['type'=>'ice_candidate', 'candidate'=>$m['candidate'] ?? null, 'from'=>$me]); break;
    case 'decline': if($to) rtInbox($pdo, $to, ['type'=>'call_declined', 'from'=>$me]); break;
    case 'end':     if($to) rtInbox($pdo, $to, ['type'=>'call_ended', 'from'=>$me]); break;

    // ── Trench Rooms: peer-to-peer negotiation, only between members of the same room ──
    case 'room_offer':
    case 'room_answer':
    case 'room_ice':
      $r = $room();
      if(!$r || !$to || currentRoom($pdo, $to) !== $r) break;
      $out = ['type'=>$type, 'from'=>$me];
      if($type === 'room_ice') $out['candidate'] = $m['candidate'] ?? null;
      else                     $out['sdp']       = $m['sdp'] ?? null;
      rtInbox($pdo, $to, $out);
      break;

    // ── Trench Rooms: broadcast to the room ──
    case 'room_chat':
      $r = $room();
      $text = trim(mb_substr((string)($m['text'] ?? ''), 0, 500));
      if(!$r || $text === '') break;
      // Flood guard: at most one message per 0.7s per member.
      $s = $pdo->prepare("UPDATE room_members SET last_chat_at = clock_timestamp()
                          WHERE wallet_address=? AND (last_chat_at IS NULL OR last_chat_at < clock_timestamp() - interval '700 milliseconds')");
      $s->execute([$me]);
      if(!$s->rowCount()){ $errors[] = 'Slow down'; break; }
      $myProfile = $myProfile ?? profileOf($pdo, $me);
      rtRoom($pdo, $r, ['type'=>'room_chat', 'from'=>$me, 'username'=>$myProfile['username'], 'text'=>$text, 'ts'=>(int)(microtime(true)*1000)]);
      break;
    case 'room_mute':
      $r = $room();
      if(!$r) break;
      $muted = !empty($m['muted']);
      $pdo->prepare("UPDATE room_members SET muted=? WHERE wallet_address=?")->execute([$muted ? 'true' : 'false', $me]);
      rtRoom($pdo, $r, ['type'=>'room_peer_muted', 'address'=>$me, 'muted'=>$muted]);
      break;
    case 'room_heartbeat':
      $s = $pdo->prepare("UPDATE room_members SET last_seen=NOW() WHERE wallet_address=? RETURNING room_id");
      $s->execute([$me]);
      $r = $s->fetchColumn();
      if($r) pruneRoom($pdo, $r);
      else   $errors[] = 'Not in a room';   // client rejoins on this
      break;
    case 'room_leave':
      leaveRoom($pdo, $me);
      $myRoom = null;
      break;
  }
}

rtFlush($pdo);   // publish the whole batch in one query
echo json_encode($errors ? ['ok'=>true, 'errors'=>$errors] : ['ok'=>true]);
