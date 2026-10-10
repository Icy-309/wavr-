// Wavr Trench Rooms — token-gated, multi-party P2P audio.
//
// Topology is a full mesh: every member holds one RTCPeerConnection per other
// member. To avoid offer glare, the member who JOINS sends offers to everyone
// already in the room; existing members only ever answer.
//
// Depends on js/signaling.js (initSignaling/sendSig) and js/webrtc.js
// (getIceServers). Everything lives inside this closure so it can't collide
// with webrtc.js's 1:1-call globals (pc, localStream, …).

const TrenchRoom = (() => {
  const peers = new Map()   // address -> { pc, iceQueue, remoteSet, audioEl, analyser, info }
  let roomId     = null
  let pendingPass = null
  let iceConfig  = null
  let mic        = null     // local MediaStream, or null when listen-only
  let micAnalyser = null
  let muted      = true
  let audioCtx   = null
  let ui         = {}
  let levelTimer = null
  let left       = false

  const token   = () => localStorage.getItem('wavr_token')
  const address = () => localStorage.getItem('wavr_address')

  async function requestPass(id){
    const r = await fetch('/api/rooms.php?action=join', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
      body: JSON.stringify({ room_id: id })
    })
    const d = await r.json()
    if(!r.ok) { const e = new Error(d.error || 'Could not join'); e.data = d; throw e }
    return d
  }

  // Must be called from a user gesture (tap on "Join"): it unlocks audio
  // playback and the mic permission prompt in one go.
  async function enter(id, callbacks){
    roomId = id
    ui     = callbacks || {}
    left   = false

    try { audioCtx = new (window.AudioContext || window.webkitAudioContext)() } catch(e){}

    // Gate check first — no point asking for the mic if they can't get in.
    const passData = await requestPass(id)   // throws with .data.gated on insufficient balance
    pendingPass = passData.pass
    if(ui.onBalance) ui.onBalance(passData.balance)

    iceConfig = await getIceServers()

    try {
      mic = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        video: false
      })
      mic.getAudioTracks().forEach(t => t.enabled = false)   // everyone enters muted
      micAnalyser = makeAnalyser(mic)
    } catch(e){
      mic = null
      if(ui.onListenOnly) ui.onListenOnly(e)
    }

    initSignaling(address(), token(), {
      onOpen:          () => join(),
      onRoomMessage:   handle,
      onSignalError:   (m) => ui.onError && ui.onError(m),
    })
    startLevels()
  }

  // Runs on every (re)connect: the server forgets room membership when the
  // socket drops, so a reconnect needs a fresh pass and a fresh mesh.
  async function join(){
    if(left) return
    closeAllPeers()
    try {
      const pass = pendingPass || (await requestPass(roomId)).pass
      pendingPass = null
      sendSig({ type: 'room_join', room_id: roomId, pass })
    } catch(e){
      if(ui.onError) ui.onError(e.message)
    }
  }

  async function handle(msg){
    switch(msg.type){
      case 'room_joined':
        if(ui.onJoined) ui.onJoined(msg.peers)
        for(const info of msg.peers){
          const p = addPeer(info)
          await sendOffer(info.address, p)
        }
        break

      case 'room_peer_joined':
        addPeer(msg.peer)               // they'll send us the offer
        if(ui.onPeerJoined) ui.onPeerJoined(msg.peer)
        break

      case 'room_peer_left':
        removePeer(msg.address)
        if(ui.onPeerLeft) ui.onPeerLeft(msg.address)
        break

      case 'room_offer': {
        const p = peers.get(msg.from) || addPeer({ address: msg.from })
        try {
          await p.pc.setRemoteDescription(msg.sdp)
          p.remoteSet = true
          await drain(p)
          const answer = await p.pc.createAnswer()
          await p.pc.setLocalDescription(answer)
          sendSig({ type: 'room_answer', to: msg.from, sdp: p.pc.localDescription })
        } catch(e){ console.warn('[Wavr room] answer failed', e) }
        break
      }

      case 'room_answer': {
        const p = peers.get(msg.from)
        if(!p) return
        try {
          await p.pc.setRemoteDescription(msg.sdp)
          p.remoteSet = true
          await drain(p)
        } catch(e){ console.warn('[Wavr room] setRemote(answer) failed', e) }
        break
      }

      case 'room_ice': {
        const p = peers.get(msg.from)
        if(!p || !msg.candidate) return
        if(p.remoteSet) p.pc.addIceCandidate(msg.candidate).catch(()=>{})
        else p.iceQueue.push(msg.candidate)
        break
      }

      case 'room_peer_muted': {
        const p = peers.get(msg.address)
        if(p) p.info.muted = msg.muted
        if(ui.onPeerMuted) ui.onPeerMuted(msg.address, msg.muted)
        break
      }

      case 'room_chat':
        if(ui.onChat) ui.onChat(msg)
        break

      case 'room_error':
        if(ui.onError) ui.onError(msg.message)
        break
    }
  }

  function addPeer(info){
    removePeer(info.address)
    const pc = new RTCPeerConnection(iceConfig)
    const p  = { pc, iceQueue: [], remoteSet: false, audioEl: null, analyser: null, info: { muted: true, ...info } }
    peers.set(info.address, p)

    if(mic) mic.getTracks().forEach(t => pc.addTrack(t, mic))
    else    pc.addTransceiver('audio', { direction: 'recvonly' })

    pc.onicecandidate = (e) => {
      if(e.candidate) sendSig({ type: 'room_ice', to: info.address, candidate: e.candidate })
    }

    pc.ontrack = (e) => {
      const stream = e.streams[0] || new MediaStream([e.track])
      if(!p.audioEl){
        p.audioEl = document.createElement('audio')
        p.audioEl.autoplay = true
        p.audioEl.playsInline = true
        document.body.appendChild(p.audioEl)
      }
      p.audioEl.srcObject = stream
      p.audioEl.play().catch(() => ui.onAudioBlocked && ui.onAudioBlocked())
      p.analyser = makeAnalyser(stream)
    }

    pc.onconnectionstatechange = () => {
      if(ui.onPeerState) ui.onPeerState(info.address, pc.connectionState)
    }
    return p
  }

  async function sendOffer(to, p){
    try {
      const offer = await p.pc.createOffer()
      await p.pc.setLocalDescription(offer)
      sendSig({ type: 'room_offer', to, sdp: p.pc.localDescription })
    } catch(e){ console.warn('[Wavr room] offer failed', e) }
  }

  async function drain(p){
    while(p.iceQueue.length){
      try { await p.pc.addIceCandidate(p.iceQueue.shift()) } catch(e){}
    }
  }

  function removePeer(addr){
    const p = peers.get(addr)
    if(!p) return
    try { p.pc.close() } catch(e){}
    if(p.audioEl){ p.audioEl.srcObject = null; p.audioEl.remove() }
    peers.delete(addr)
  }

  function closeAllPeers(){ [...peers.keys()].forEach(removePeer) }

  function makeAnalyser(stream){
    if(!audioCtx) return null
    try {
      const src = audioCtx.createMediaStreamSource(stream)
      const an  = audioCtx.createAnalyser()
      an.fftSize = 512
      src.connect(an)            // deliberately not connected to destination — the <audio> element plays it
      return an
    } catch(e){ return null }
  }

  function level(an){
    if(!an) return 0
    const buf = new Uint8Array(an.fftSize)
    an.getByteTimeDomainData(buf)
    let sum = 0
    for(let i = 0; i < buf.length; i++){ const v = (buf[i] - 128) / 128; sum += v * v }
    return Math.sqrt(sum / buf.length)
  }

  // Drives the "who's talking" rings in the UI.
  function startLevels(){
    clearInterval(levelTimer)
    levelTimer = setInterval(() => {
      if(!ui.onLevels) return
      const levels = { [address()]: (mic && !muted) ? level(micAnalyser) : 0 }
      peers.forEach((p, a) => { levels[a] = p.info.muted ? 0 : level(p.analyser) })
      ui.onLevels(levels)
    }, 120)
  }

  function setMuted(m){
    if(!mic) return false
    muted = m
    mic.getAudioTracks().forEach(t => t.enabled = !m)
    sendSig({ type: 'room_mute', muted: m })
    return true
  }

  function chat(text){
    text = (text || '').trim()
    if(text) sendSig({ type: 'room_chat', text: text.slice(0, 500) })
  }

  function leave(){
    left = true
    sendSig({ type: 'room_leave' })
    closeAllPeers()
    clearInterval(levelTimer)
    if(mic){ mic.getTracks().forEach(t => t.stop()); mic = null }
    if(audioCtx){ audioCtx.close().catch(()=>{}); audioCtx = null }
  }

  function resumeAudio(){
    if(audioCtx && audioCtx.state === 'suspended') audioCtx.resume().catch(()=>{})
    peers.forEach(p => p.audioEl && p.audioEl.play().catch(()=>{}))
  }

  return {
    enter, leave, setMuted, chat, resumeAudio, requestPass,
    hasMic:  () => !!mic,
    isMuted: () => muted,
  }
})()
