// Wavr — Phantom mobile deep-link wallet connect
//
// Why this exists: on mobile, window.phantom.solana (the injected provider)
// only exists inside Phantom's OWN in-app browser. That browser reliably
// injects the wallet API, but its embedded WebView does not reliably grant
// microphone access for WebRTC calls — testing confirmed the tab crashing
// almost immediately once getUserMedia() is invoked from inside it.
//
// The fix: on a normal mobile browser (Chrome, etc.) with no injected
// provider, "Connect Wallet" hands off to the real Phantom app via its
// documented deep-link protocol (https://docs.phantom.app/phantom-deeplinks)
// for connecting and signing, then returns control to the SAME browser tab
// — where calling actually works, since it's a full browser, not a
// dApp-viewer WebView.
//
// Protocol summary: an X25519 keypair is generated per session; Phantom's
// public key comes back via redirect and a shared secret is derived
// (nacl.box.before); every further request/response is authenticated-
// encrypted with that shared secret via nacl.box (open).after.

;(function(){
  const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'

  function b58encode(bytes){
    if(bytes.length === 0) return ''
    let zeros = 0
    while(zeros < bytes.length && bytes[zeros] === 0) zeros++

    const size = Math.ceil((bytes.length - zeros) * 138 / 100) + 1
    const b58 = new Uint8Array(size)
    let length = 0
    for(let i = zeros; i < bytes.length; i++){
      let carry = bytes[i]
      let j = 0
      for(let k = size - 1; (carry !== 0 || j < length) && k >= 0; k--, j++){
        carry += 256 * b58[k]
        b58[k] = carry % 58
        carry = Math.floor(carry / 58)
      }
      length = j
    }
    let it = size - length
    while(it < size && b58[it] === 0) it++

    let str = '1'.repeat(zeros)
    for(; it < size; it++) str += B58_ALPHABET[b58[it]]
    return str
  }

  function b58decode(str){
    if(str.length === 0) return new Uint8Array(0)
    let zeros = 0
    while(zeros < str.length && str[zeros] === '1') zeros++

    const size = Math.ceil(str.length * 733 / 1000) + 1  // log(58)/log(256)
    const b256 = new Uint8Array(size)
    let length = 0
    for(let i = zeros; i < str.length; i++){
      const value = B58_ALPHABET.indexOf(str[i])
      if(value === -1) throw new Error('Invalid base58 character: ' + str[i])
      let carry = value
      let j = 0
      for(let k = size - 1; (carry !== 0 || j < length) && k >= 0; k--, j++){
        carry += 58 * b256[k]
        b256[k] = carry & 0xff
        carry >>= 8
      }
      length = j
    }
    let it = size - length
    while(it < size && b256[it] === 0) it++

    const out = new Uint8Array(zeros + (size - it))
    out.set(b256.subarray(it), zeros)
    return out
  }

  window.WavrB58 = { encode: b58encode, decode: b58decode }

  // ── DEEP-LINK STATE (localStorage — must survive the round trip to the
  //    Phantom app and back) ──
  const SK  = 'wavr_pdl_secret_key'    // our ephemeral X25519 secret key (base58)
  const PK  = 'wavr_pdl_public_key'    // our ephemeral X25519 public key (base58)
  const SS  = 'wavr_pdl_shared_secret' // derived shared secret with Phantom (base58)
  const SESS= 'wavr_pdl_session'       // Phantom's session token for this connection
  const WAL = 'wavr_pdl_wallet'        // connected wallet address (base58)
  const NNC = 'wavr_pdl_pending_nonce' // our backend's auth nonce, held across the signMessage round trip

  function isMobile(){
    return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
  }

  function hasInjectedProvider(){
    return !!(window.phantom?.solana || (window.solana && window.solana.isPhantom))
  }

  // Call this to decide whether the deep-link path should be offered instead
  // of "not installed" for Phantom specifically.
  function shouldUseDeeplink(){
    return isMobile() && !hasInjectedProvider()
  }

  function startConnect(){
    const kp = nacl.box.keyPair()
    localStorage.setItem(SK, b58encode(kp.secretKey))
    localStorage.setItem(PK, b58encode(kp.publicKey))

    const redirectLink = location.origin + location.pathname.replace(/[^/]*$/, '') + 'index.html?phantom_action=connect'
    const params = new URLSearchParams({
      dapp_encryption_public_key: b58encode(kp.publicKey),
      cluster: 'mainnet-beta',
      app_url: location.origin,
      redirect_link: redirectLink
    })
    window.location.href = 'https://phantom.app/ul/v1/connect?' + params.toString()
  }

  function requestSignMessage(walletAddress, message, authNonce){
    const sharedSecret = b58decode(localStorage.getItem(SS))
    const session = localStorage.getItem(SESS)
    const dappPublicKey = localStorage.getItem(PK)

    localStorage.setItem(WAL, walletAddress)
    localStorage.setItem(NNC, authNonce)

    const payload = {
      session,
      message: b58encode(new TextEncoder().encode(message)),
      display: 'utf8'
    }
    const nonce = nacl.randomBytes(24)
    const encrypted = nacl.box.after(
      new TextEncoder().encode(JSON.stringify(payload)), nonce, sharedSecret
    )

    const redirectLink = location.origin + location.pathname.replace(/[^/]*$/, '') + 'index.html?phantom_action=signMessage'
    const params = new URLSearchParams({
      dapp_encryption_public_key: dappPublicKey,
      nonce: b58encode(nonce),
      redirect_link: redirectLink,
      payload: b58encode(encrypted)
    })
    window.location.href = 'https://phantom.app/ul/v1/signMessage?' + params.toString()
  }

  // Returns null if there's nothing to handle (no phantom_action param),
  // or { type: 'connect'|'signMessage', ...data } / { type: 'error', message }
  function handleReturnIfAny(){
    const url = new URL(window.location.href)
    const action = url.searchParams.get('phantom_action')
    if(!action) return null

    // Clean the URL so a reload/back-nav doesn't reprocess a stale response.
    const cleanUrl = location.pathname
    window.history.replaceState({}, document.title, cleanUrl)

    if(url.searchParams.get('errorCode')){
      return { type: 'error', message: url.searchParams.get('errorMessage') || 'Phantom request was rejected or cancelled' }
    }

    try {
      if(action === 'connect'){
        const phantomPublicKey = b58decode(url.searchParams.get('phantom_encryption_public_key'))
        const dappSecretKey    = b58decode(localStorage.getItem(SK))
        const sharedSecret     = nacl.box.before(phantomPublicKey, dappSecretKey)
        localStorage.setItem(SS, b58encode(sharedSecret))

        const nonce = b58decode(url.searchParams.get('nonce'))
        const data  = b58decode(url.searchParams.get('data'))
        const decrypted = nacl.box.open.after(data, nonce, sharedSecret)
        if(!decrypted) return { type: 'error', message: 'Could not decrypt Phantom response' }

        const connectData = JSON.parse(new TextDecoder().decode(decrypted))
        localStorage.setItem(SESS, connectData.session)
        return { type: 'connect', walletAddress: connectData.public_key }
      }

      if(action === 'signMessage'){
        const sharedSecret = b58decode(localStorage.getItem(SS))
        const nonce = b58decode(url.searchParams.get('nonce'))
        const data  = b58decode(url.searchParams.get('data'))
        const decrypted = nacl.box.open.after(data, nonce, sharedSecret)
        if(!decrypted) return { type: 'error', message: 'Could not decrypt Phantom response' }

        const signData = JSON.parse(new TextDecoder().decode(decrypted))
        const sigBytes = b58decode(signData.signature)
        const sigHex = Array.from(sigBytes).map(b => b.toString(16).padStart(2,'0')).join('')

        return {
          type: 'signMessage',
          signatureHex: sigHex,
          walletAddress: localStorage.getItem(WAL),
          nonce: localStorage.getItem(NNC)
        }
      }
    } catch(e){
      console.error('[Wavr] Phantom deeplink parse error:', e)
      return { type: 'error', message: e.message }
    }

    return null
  }

  // Call once the flow finishes (success or failure) so retries start clean
  // and the ephemeral session key/shared secret don't linger in localStorage.
  function clearState(){
    ;[SK, PK, SS, SESS, WAL, NNC].forEach(k => localStorage.removeItem(k))
  }

  window.WavrPhantomDeeplink = {
    isMobile, hasInjectedProvider, shouldUseDeeplink,
    startConnect, requestSignMessage, handleReturnIfAny, clearState
  }
})()
