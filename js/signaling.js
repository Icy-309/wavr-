// Wavr Signaling Client
let ws               = null
let signalingCallbacks = {}
let reconnectTimer   = null
let myAddress        = null
let retryCount       = 0
let outgoingQueue    = []   // buffer messages sent before WS is open
let heartbeatTimer   = null
const MAX_RETRIES    = 10
const HEARTBEAT_MS   = 25000  // keep well under Cloudflare's ~100s idle-connection timeout

function initSignaling(address, token, callbacks){
  myAddress          = address
  signalingCallbacks = callbacks || {}
  retryCount         = 0
  outgoingQueue      = []

  const proto = location.protocol === 'https:' ? 'wss' : 'ws'
  const SIGNAL_HOST = window.WAVR_SIGNAL_HOST || location.hostname
  const SIGNAL_PORT = window.WAVR_SIGNAL_PORT || 8080
  const url = `${proto}://${SIGNAL_HOST}:${SIGNAL_PORT}?address=${encodeURIComponent(address)}&token=${encodeURIComponent(token)}`

  connectWS(url)

  // Close cleanly on navigation so onclose.wasClean=true — no reconnect fires
  window.addEventListener('beforeunload', () => {
    if(ws && ws.readyState === WebSocket.OPEN) ws.close(1000, 'navigating')
  })
}

function connectWS(url){
  if(retryCount >= MAX_RETRIES){
    console.warn('[Wavr] Signaling: max retries reached')
    return
  }

  try {
    ws = new WebSocket(url)

    ws.onopen = () => {
      console.log('[Wavr] Signaling connected')
      retryCount = 0
      clearTimeout(reconnectTimer)
      // Drain any messages that were sent before the socket opened
      while(outgoingQueue.length){
        ws.send(outgoingQueue.shift())
      }
      // Keep the connection from ever sitting idle long enough for the
      // Cloudflare tunnel (or any reverse proxy) to silently close it —
      // that was causing repeated reconnect churn, and a call landing on
      // the signaling server mid-reconnect could lose its offer/ICE
      // messages in the gap, getting stuck on "Connecting".
      clearInterval(heartbeatTimer)
      heartbeatTimer = setInterval(() => {
        if(ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'ping' }))
      }, HEARTBEAT_MS)
    }

    ws.onmessage = (event) => {
      let msg
      try { msg = JSON.parse(event.data) } catch(e){ return }
      handleSignalingMessage(msg)
    }

    ws.onclose = (e) => {
      clearInterval(heartbeatTimer)
      if(e.wasClean) return   // navigating away — don't reconnect
      retryCount++
      const delay = Math.min(3000 * retryCount, 15000)
      console.log(`[Wavr] Signaling lost, retry ${retryCount}/${MAX_RETRIES} in ${delay}ms`)
      reconnectTimer = setTimeout(() => connectWS(url), delay)
    }

    ws.onerror = (e) => console.error('[Wavr] WS error:', e)

  } catch(e){
    console.error('[Wavr] Cannot connect to signaling:', e)
  }
}

function handleSignalingMessage(msg){
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
  }
}

function sendSig(msg){
  const data = JSON.stringify(msg)
  if(ws && ws.readyState === WebSocket.OPEN){
    ws.send(data)
  } else {
    // Queue it — WS might still be connecting (race with startCall)
    outgoingQueue.push(data)
  }
}

function signalingDecline(to){ sendSig({ type:'decline', to }) }

function sendOffer(to, offer){
  const myAvatar = localStorage.getItem('wavr_avatar') || ''
  sendSig({ type:'offer', to, offer,
    from_username: localStorage.getItem('wavr_username'),
    from_avatar:   myAvatar
  })
}

function sendAnswer(to, answer){ sendSig({ type:'answer', to, answer }) }
function sendIce(to, candidate){ sendSig({ type:'ice', to, candidate }) }
function sendCallEnded(to){ sendSig({ type:'end', to }) }
