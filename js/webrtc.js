// Wavr WebRTC — P2P audio

let pc            = null
let localStream   = null
let peerTarget    = null
let iceQueue      = []
let remoteDescSet = false
let disconnectTimer = null   // grace period before treating disconnected as ended
let statsInterval   = null

// The definitive proof of whether audio is actually flowing (as opposed to
// the connection merely being "connected" at the transport level) — logs
// actual RTP byte counts for the outbound (mic) and inbound (remote) audio
// every 3s. If outbound stays at 0, our mic audio never reaches the peer;
// if inbound stays at 0, nothing is arriving from them, regardless of what
// connectionState says.
function startStatsLogging(){
  stopStatsLogging()
  statsInterval = setInterval(async () => {
    if(!pc) return
    try {
      const stats = await pc.getStats()
      let out = null, inn = null
      stats.forEach(r => {
        if(r.type === 'outbound-rtp' && r.kind === 'audio') out = r
        if(r.type === 'inbound-rtp'  && r.kind === 'audio') inn = r
      })
      const msg = `audio stats: sent=${out ? out.bytesSent : 'n/a'}B recv=${inn ? inn.bytesReceived : 'n/a'}B`
      if(signalingCallbacks.onDebug) signalingCallbacks.onDebug(msg)
    } catch(e){}
  }, 3000)
}

function stopStatsLogging(){
  if(statsInterval){ clearInterval(statsInterval); statsInterval = null }
}

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
  const tracks = localStream.getAudioTracks()
  if(signalingCallbacks.onDebug){
    if(tracks.length === 0){
      signalingCallbacks.onDebug('getUserMedia: NO AUDIO TRACKS RETURNED')
    } else {
      tracks.forEach(t => signalingCallbacks.onDebug(
        `local mic track: enabled=${t.enabled} muted=${t.muted} readyState=${t.readyState} label="${t.label}"`
      ))
    }
  }
  return localStream
}

async function createPC(targetAddress){
  peerTarget    = targetAddress
  iceQueue      = []
  remoteDescSet = false
  const iceServers = await getIceServers()
  pc            = new RTCPeerConnection(iceServers)

  let candidateCounts = { host: 0, srflx: 0, relay: 0, other: 0 }

  pc.onicecandidate = (e) => {
    if(e.candidate){
      sendIce(targetAddress, e.candidate)
      const type = e.candidate.type || 'other'
      candidateCounts[type] = (candidateCounts[type] || 0) + 1
      if(signalingCallbacks.onDebug) signalingCallbacks.onDebug(`ICE candidate gathered: ${type} (host:${candidateCounts.host} srflx:${candidateCounts.srflx} relay:${candidateCounts.relay})`)
    }
  }

  pc.oniceconnectionstatechange = () => {
    if(signalingCallbacks.onDebug) signalingCallbacks.onDebug('iceConnectionState: ' + pc.iceConnectionState)
  }

  pc.onicegatheringstatechange = () => {
    if(signalingCallbacks.onDebug) signalingCallbacks.onDebug('iceGatheringState: ' + pc.iceGatheringState)
  }

  pc.ontrack = (e) => {
    if(signalingCallbacks.onDebug){
      signalingCallbacks.onDebug(`ontrack fired: kind=${e.track.kind} readyState=${e.track.readyState} muted=${e.track.muted} streams=${e.streams.length}`)
    }
    const audio = document.getElementById('remote-audio')
    if(!audio) return
    audio.srcObject = e.streams[0]
    // <audio autoplay> can be silently blocked by the browser's autoplay
    // policy even after the connection succeeds — nothing else here would
    // ever surface that, so explicitly attempt playback and fall back to
    // a one-tap unlock instead of just staying silent with no indication
    // anything is wrong.
    const playAttempt = audio.play()
    if(playAttempt && typeof playAttempt.catch === 'function'){
      playAttempt.catch(err => {
        console.warn('[Wavr] Remote audio autoplay blocked:', err)
        if(signalingCallbacks.onAudioBlocked) signalingCallbacks.onAudioBlocked()
        const unlock = () => {
          audio.play().catch(()=>{})
          document.removeEventListener('click', unlock)
          document.removeEventListener('touchend', unlock)
        }
        document.addEventListener('click', unlock, { once: true })
        document.addEventListener('touchend', unlock, { once: true })
      })
    }
  }

  pc.onconnectionstatechange = () => {
    const state = pc.connectionState
    console.log('[Wavr] PC state:', state)
    if(signalingCallbacks.onDebug) signalingCallbacks.onDebug('connectionState: ' + state)

    if(state === 'connected'){
      // Clear any pending disconnect grace timer
      if(disconnectTimer){ clearTimeout(disconnectTimer); disconnectTimer = null }
      if(signalingCallbacks.onConnected) signalingCallbacks.onConnected()
      startStatsLogging()
    }

    if(state === 'closed' || state === 'failed' || state === 'disconnected'){
      stopStatsLogging()
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
  stopStatsLogging()
  if(pc){ pc.close(); pc = null }
  if(localStream){ localStream.getTracks().forEach(t => t.stop()); localStream = null }
  remoteDescSet = false
  iceQueue = []
}
