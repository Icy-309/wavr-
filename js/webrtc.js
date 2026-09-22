// Wavr WebRTC — P2P audio

let pc            = null
let localStream   = null
let peerTarget    = null
let iceQueue      = []
let remoteDescSet = false
let disconnectTimer = null   // grace period before treating disconnected as ended

const FALLBACK_ICE_SERVERS = {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302'  },
    { urls: 'stun:stun1.l.google.com:19302' },
    { urls: 'stun:stun2.l.google.com:19302' },
  ]
}

let iceServersCache = null   // cached for the lifetime of this page load

// Fetches STUN+TURN config from our backend (api/turn.php), which holds the
// Metered API key server-side and hands back only short-lived TURN credentials.
// Falls back to STUN-only if that fails, so calls still work P2P.
async function getIceServers(){
  if(iceServersCache) return iceServersCache
  try {
    const token = localStorage.getItem('wavr_token')
    const r = await fetch('/api/turn.php', { headers: { 'Authorization': 'Bearer ' + token } })
    const d = await r.json()
    if(d.iceServers && d.iceServers.length){
      iceServersCache = { iceServers: d.iceServers }
      return iceServersCache
    }
  } catch(e){
    console.warn('[Wavr] TURN fetch failed, falling back to STUN only:', e)
  }
  iceServersCache = FALLBACK_ICE_SERVERS
  return iceServersCache
}

async function getAudio(){
  localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false })
  return localStream
}

async function createPC(targetAddress){
  peerTarget    = targetAddress
  iceQueue      = []
  remoteDescSet = false
  const iceServers = await getIceServers()
  pc            = new RTCPeerConnection(iceServers)

  pc.onicecandidate = (e) => {
    if(e.candidate) sendIce(targetAddress, e.candidate)
  }

  pc.ontrack = (e) => {
    const audio = document.getElementById('remote-audio')
    if(audio) audio.srcObject = e.streams[0]
  }

  pc.onconnectionstatechange = () => {
    const state = pc.connectionState
    console.log('[Wavr] PC state:', state)

    if(state === 'connected'){
      // Clear any pending disconnect grace timer
      if(disconnectTimer){ clearTimeout(disconnectTimer); disconnectTimer = null }
      if(signalingCallbacks.onConnected) signalingCallbacks.onConnected()
    }

    if(state === 'disconnected'){
      // 'disconnected' is temporary (network blip) — wait 6s before treating as ended
      disconnectTimer = setTimeout(() => {
        if(pc && pc.connectionState === 'disconnected'){
          if(signalingCallbacks.onCallEnded) signalingCallbacks.onCallEnded()
        }
      }, 6000)
    }

    if(state === 'failed'){
      // 'failed' is permanent — end immediately
      if(disconnectTimer){ clearTimeout(disconnectTimer); disconnectTimer = null }
      if(signalingCallbacks.onCallEnded) signalingCallbacks.onCallEnded()
    }
  }

  if(localStream){
    localStream.getTracks().forEach(t => pc.addTrack(t, localStream))
  }
}

async function drainIceQueue(){
  while(iceQueue.length){
    try { await pc.addIceCandidate(new RTCIceCandidate(iceQueue.shift())) } catch(e){}
  }
}

async function handleRemoteIce(candidate){
  if(remoteDescSet){
    try { await pc.addIceCandidate(new RTCIceCandidate(candidate)) } catch(e){}
  } else {
    iceQueue.push(candidate)
  }
}

async function startCall(targetAddress){
  try {
    await getAudio()
    await createPC(targetAddress)

    signalingCallbacks.onCallAnswered = async (answer) => {
      await pc.setRemoteDescription(new RTCSessionDescription(answer))
      remoteDescSet = true
      await drainIceQueue()
    }
    signalingCallbacks.onIceCandidate = handleRemoteIce

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)
    sendOffer(targetAddress, offer)   // sendSig queues if WS not open yet

  } catch(e){
    console.error('[Wavr] startCall:', e)
    alert(e.name === 'NotAllowedError' ? 'Microphone permission denied.' : 'Call failed: '+e.message)
    window.location.href = 'app.html'
  }
}

async function answerCall(fromAddress, offer){
  try {
    await getAudio()
    await createPC(fromAddress)
    signalingCallbacks.onIceCandidate = handleRemoteIce

    await pc.setRemoteDescription(new RTCSessionDescription(offer))
    remoteDescSet = true
    await drainIceQueue()

    const answer = await pc.createAnswer()
    await pc.setLocalDescription(answer)
    sendAnswer(fromAddress, answer)

  } catch(e){
    console.error('[Wavr] answerCall:', e)
    alert(e.name === 'NotAllowedError' ? 'Microphone permission denied.' : 'Answer failed: '+e.message)
    window.location.href = 'app.html'
  }
}

function toggleMute(muted){
  if(localStream) localStream.getAudioTracks().forEach(t => t.enabled = !muted)
}

function endCall(){
  if(peerTarget) sendCallEnded(peerTarget)
  if(disconnectTimer){ clearTimeout(disconnectTimer); disconnectTimer = null }
  if(pc){ pc.close(); pc = null }
  if(localStream){ localStream.getTracks().forEach(t => t.stop()); localStream = null }
  remoteDescSet = false
  iceQueue = []
}
