// Wavr Signaling Client — Supabase Realtime edition.
//
// Receive: a private Realtime channel `inbox:<my wallet>` (plus `room:<id>`
//          while in a Trench Room). Only this wallet can read them; the
//          RLS policy lives in schema.sql.
// Send:    POST /api/signal.php, which checks the session and stamps the
//          real sender before publishing. Messages are batched and sent
//          strictly in order (an ICE candidate must never overtake its offer).
//
// Requires supabase-js (UMD build, global `supabase`) to be loaded first.
// Public API is unchanged from the old WebSocket client: initSignaling,
// sendSig, sendOffer/Answer/Ice, signalingDecline, sendCallEnded.

let sb                 = null
let inboxChannel       = null
let roomChannel        = null
let signalingCallbacks = {}
let myAddress          = null
let myToken            = null
let authTimer          = null
let sendQueue          = []
let sending            = false

async function fetchRealtimeConfig(){
  const r = await fetch('/api/realtime.php', { headers: { 'Authorization': 'Bearer ' + myToken } })
  const d = await r.json()
  if(!r.ok || d.error) throw new Error(d.error || 'Realtime config failed')
  return d
}

// The Realtime JWT lasts an hour; swap in a fresh one well before it expires
// so long calls/rooms don't get cut off.
function scheduleAuthRefresh(expiresAt){
  clearTimeout(authTimer)
  const ms = Math.max(30000, (expiresAt * 1000 - Date.now()) - 5 * 60 * 1000)
  authTimer = setTimeout(async () => {
    try {
      const cfg = await fetchRealtimeConfig()
      sb.realtime.setAuth(cfg.jwt)
      scheduleAuthRefresh(cfg.expires_at)
    } catch(e){
      console.warn('[Wavr] Realtime token refresh failed, retrying soon:', e)
      authTimer = setTimeout(() => scheduleAuthRefresh(Date.now() / 1000 + 5 * 60 + 30), 30000)
    }
  }, ms)
}

function debug(msg){ if(signalingCallbacks.onDebug) signalingCallbacks.onDebug(msg) }

async function initSignaling(address, token, callbacks){
  myAddress          = address
  myToken            = token
  signalingCallbacks = callbacks || {}

  try {
    const cfg = await fetchRealtimeConfig()
    sb = supabase.createClient(cfg.url, cfg.anon_key, {
      auth: { persistSession: false, autoRefreshToken: false, detectSessionInUrl: false },
    })
    sb.realtime.setAuth(cfg.jwt)
    scheduleAuthRefresh(cfg.expires_at)

    inboxChannel = sb.channel('inbox:' + address, { config: { private: true } })
      .on('broadcast', { event: 'sig' }, ({ payload }) => handleSignalingMessage(payload))
      .subscribe((status, err) => {
        if(status === 'SUBSCRIBED'){
          console.log('[Wavr] Signaling connected')
          debug('signaling: inbox subscribed')
          if(signalingCallbacks.onOpen) signalingCallbacks.onOpen()
        } else if(status === 'CHANNEL_ERROR' || status === 'TIMED_OUT'){
          // supabase-js keeps retrying on its own; just surface it.
          console.warn('[Wavr] Signaling channel ' + status, err || '')
          debug('signaling: ' + status + (err ? ' ' + err.message : ''))
        }
      })
  } catch(e){
    console.error('[Wavr] Cannot start signaling:', e)
    if(signalingCallbacks.onSignalError) signalingCallbacks.onSignalError(e.message)
  }
}

// Room events (chat, joins/leaves, mute) arrive on the room's own channel.
// Only callable after api/rooms.php?action=join has made us a member.
function subscribeRoom(roomId){
  return new Promise((resolve, reject) => {
    if(!sb) return reject(new Error('Signaling not ready'))
    unsubscribeRoom()
    let settled = false
    roomChannel = sb.channel('room:' + roomId, { config: { private: true } })
      .on('broadcast', { event: 'sig' }, ({ payload }) => handleSignalingMessage(payload))
      .subscribe((status, err) => {
        if(status === 'SUBSCRIBED' && !settled){ settled = true; resolve() }
        if((status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') && !settled){
          settled = true; reject(err || new Error('Could not open room channel (' + status + ')'))
        }
      })
  })
}

function unsubscribeRoom(){
  if(roomChannel && sb){ sb.removeChannel(roomChannel) }
  roomChannel = null
}

function handleSignalingMessage(msg){
  if(!msg || !msg.type) return
  switch(msg.type){
    case 'incoming_call':
      if(signalingCallbacks.onIncomingCall)
        signalingCallbacks.onIncomingCall(msg.from, msg.from_username, msg.offer, msg.from_avatar)
      break
    case 'call_answered':
      if(signalingCallbacks.onCallAnswered)
        signalingCallbacks.onCallAnswered(msg.answer)
      break
    case 'ice_candidate':
      if(signalingCallbacks.onIceCandidate)
        signalingCallbacks.onIceCandidate(msg.candidate)
      break
    case 'call_declined':
      if(signalingCallbacks.onCallDeclined)
        signalingCallbacks.onCallDeclined()
      break
    case 'call_ended':
      if(signalingCallbacks.onCallEnded)
        signalingCallbacks.onCallEnded()
      break
    default:
      // Trench Rooms traffic (room_offer, room_chat, room_peer_joined, …) — see js/room.js
      if(msg.type.startsWith('room_') && signalingCallbacks.onRoomMessage)
        signalingCallbacks.onRoomMessage(msg)
  }
}

// Queue + single in-flight request keeps messages in order; everything that
// piles up while a request is in flight goes out together in the next batch.
function sendSig(msg){
  sendQueue.push(msg)
  flushSends()
}

async function flushSends(){
  if(sending || !sendQueue.length) return
  sending = true
  const batch = sendQueue.splice(0, 40)
  try {
    const r = await fetch('/api/signal.php', {
      method: 'POST',
      // keepalive: a hang-up / leave sent right before navigating away still gets delivered
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + myToken },
      body: JSON.stringify({ messages: batch })
    })
    const d = await r.json().catch(() => ({}))
    if(!r.ok) throw new Error(d.error || ('HTTP ' + r.status))
    if(d.errors && signalingCallbacks.onSignalError) d.errors.forEach(e => signalingCallbacks.onSignalError(e))
  } catch(e){
    console.warn('[Wavr] Signal send failed:', e)
    debug('signal send failed: ' + e.message)
  } finally {
    sending = false
    if(sendQueue.length) flushSends()
  }
}

function signalingDecline(to){ sendSig({ type:'decline', to }) }
// Caller's username/avatar are looked up server-side from the session.
function sendOffer(to, offer){ sendSig({ type:'offer', to, offer }) }
function sendAnswer(to, answer){ sendSig({ type:'answer', to, answer }) }
function sendIce(to, candidate){ sendSig({ type:'ice', to, candidate }) }
function sendCallEnded(to){ sendSig({ type:'end', to }) }
