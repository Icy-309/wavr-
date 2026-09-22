/**
 * Wavr — Add to Home Screen
 * Handles both Android (beforeinstallprompt API) and iOS (manual instructions)
 */

;(function(){
  const DISMISSED_KEY = 'wavr_install_dismissed'
  const INSTALLED_KEY = 'wavr_installed'

  // ── DETECTION ──────────────────────────────────────────
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream
  const isAndroid = /Android/.test(navigator.userAgent)
  const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent)

  // Already running as installed PWA?
  const isInstalled =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true

  // User already dismissed or installed?
  const wasDismissed = localStorage.getItem(DISMISSED_KEY)
  const wasInstalled = localStorage.getItem(INSTALLED_KEY)

  // Don't show anything if already installed
  if(isInstalled){
    localStorage.setItem(INSTALLED_KEY, '1')
    return
  }
  if(wasDismissed || wasInstalled) return

  // ── ANDROID — capture beforeinstallprompt ──────────────
  let androidPrompt = null

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    androidPrompt = e
    // Small delay so page renders first
    setTimeout(showAndroidBanner, 1200)
  })

  // If Chrome already triggered install, mark as installed
  window.addEventListener('appinstalled', () => {
    localStorage.setItem(INSTALLED_KEY, '1')
    removeBanners()
  })

  // ── iOS — show after short delay ──────────────────────
  // Only show on iOS Safari, not in-app browsers (FB, IG etc)
  if(isIOS && isSafari){
    setTimeout(showIOSBanner, 1500)
  }

  // ── BUILD ANDROID BANNER ──────────────────────────────
  function showAndroidBanner(){
    if(document.getElementById('wavr-install-android')) return

    const banner = document.createElement('div')
    banner.id = 'wavr-install-android'
    banner.innerHTML = `
      <div class="wib-inner">
        <div class="wib-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#F97316" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
          </svg>
        </div>
        <div class="wib-text">
          <div class="wib-title">Add Wavr to home screen</div>
          <div class="wib-sub">Install for the full app experience</div>
        </div>
        <button class="wib-install" id="wib-install-btn">Install</button>
        <button class="wib-close" id="wib-close-btn" aria-label="Dismiss">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    `
    injectStyles()
    document.body.appendChild(banner)
    requestAnimationFrame(() => banner.classList.add('wib-visible'))

    document.getElementById('wib-install-btn').addEventListener('click', async () => {
      if(!androidPrompt) return
      androidPrompt.prompt()
      const { outcome } = await androidPrompt.userChoice
      if(outcome === 'accepted'){
        localStorage.setItem(INSTALLED_KEY, '1')
      } else {
        localStorage.setItem(DISMISSED_KEY, '1')
      }
      removeBanners()
    })

    document.getElementById('wib-close-btn').addEventListener('click', () => {
      localStorage.setItem(DISMISSED_KEY, '1')
      removeBanners()
    })
  }

  // ── BUILD iOS BANNER ──────────────────────────────────
  function showIOSBanner(){
    if(document.getElementById('wavr-install-ios')) return

    const sheet = document.createElement('div')
    sheet.id = 'wavr-install-ios'
    sheet.innerHTML = `
      <div class="wios-sheet">
        <div class="wios-handle"></div>
        <div class="wios-header">
          <div class="wios-app">
            <div class="wios-appicon">W</div>
            <div>
              <div class="wios-appname">Wavr</div>
              <div class="wios-appsub">wavr.app</div>
            </div>
          </div>
          <button class="wios-close" id="wios-close" aria-label="Dismiss">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <div class="wios-title">Add to Home Screen</div>
        <div class="wios-desc">Install Wavr for fullscreen P2P calls without the browser bar.</div>

        <div class="wios-steps">
          <div class="wios-step">
            <div class="wios-step-num">1</div>
            <div class="wios-step-text">
              Tap the
              <span class="wios-share-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F97316" stroke-width="2.2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8M16 6l-4-4-4 4M12 2v13"/>
                </svg>
              </span>
              <strong>Share</strong> button at the bottom of Safari
            </div>
          </div>
          <div class="wios-step">
            <div class="wios-step-num">2</div>
            <div class="wios-step-text">
              Scroll down and tap <strong>"Add to Home Screen"</strong>
            </div>
          </div>
          <div class="wios-step">
            <div class="wios-step-num">3</div>
            <div class="wios-step-text">
              Tap <strong>"Add"</strong> in the top right corner
            </div>
          </div>
        </div>

        <div class="wios-arrow-wrap">
          <div class="wios-arrow-label">Share button is down here</div>
          <div class="wios-arrow">
            <svg width="20" height="28" viewBox="0 0 20 28" fill="none">
              <path d="M10 2 L10 22 M3 15 L10 24 L17 15" stroke="#F97316" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>
      </div>
    `

    injectStyles()
    document.body.appendChild(sheet)
    requestAnimationFrame(() => sheet.classList.add('wios-visible'))

    document.getElementById('wios-close').addEventListener('click', () => {
      localStorage.setItem(DISMISSED_KEY, '1')
      removeBanners()
    })

    // Swipe down to dismiss
    let startY = 0
    const inner = sheet.querySelector('.wios-sheet')
    inner.addEventListener('touchstart', e => { startY = e.touches[0].clientY }, { passive: true })
    inner.addEventListener('touchend', e => {
      if(e.changedTouches[0].clientY - startY > 60){
        localStorage.setItem(DISMISSED_KEY, '1')
        removeBanners()
      }
    }, { passive: true })
  }

  // ── CLEANUP ───────────────────────────────────────────
  function removeBanners(){
    const a = document.getElementById('wavr-install-android')
    const i = document.getElementById('wavr-install-ios')
    if(a){ a.style.animation = 'wibSlideOut .3s ease forwards'; setTimeout(() => a.remove(), 300) }
    if(i){ i.classList.remove('wios-visible'); setTimeout(() => i.remove(), 400) }
  }

  // ── INJECT STYLES ────────────────────────────────────
  function injectStyles(){
    if(document.getElementById('wavr-install-css')) return
    const s = document.createElement('style')
    s.id = 'wavr-install-css'
    s.textContent = `
      /* ── ANDROID BANNER ── */
      #wavr-install-android {
        position: fixed; bottom: calc(16px + env(safe-area-inset-bottom, 0px));
        left: 50%; transform: translateX(-50%) translateY(120px);
        width: calc(100% - 32px); max-width: 440px;
        background: #1a1a1a; border: 1px solid rgba(249,115,22,.3);
        border-radius: 16px; z-index: 88888;
        box-shadow: 0 8px 40px rgba(0,0,0,.7), 0 0 0 1px rgba(249,115,22,.1);
        opacity: 0;
        transition: transform .38s cubic-bezier(.34,1.5,.64,1), opacity .3s ease;
        -webkit-tap-highlight-color: transparent;
      }
      #wavr-install-android.wib-visible {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
      }
      @keyframes wibSlideOut {
        to { transform: translateX(-50%) translateY(120px); opacity: 0; }
      }
      .wib-inner {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 12px 14px 16px;
      }
      .wib-icon {
        width: 44px; height: 44px; border-radius: 12px;
        background: rgba(249,115,22,.1); border: 1px solid rgba(249,115,22,.2);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
      }
      .wib-text { flex: 1; min-width: 0; }
      .wib-title {
        font-family: 'Unbounded', sans-serif; font-size: .78rem;
        font-weight: 700; color: #fff; margin-bottom: 2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .wib-sub { font-size: .72rem; color: #6b6b6b; font-family: 'Plus Jakarta Sans', sans-serif; }
      .wib-install {
        background: #F97316; color: #000;
        border: none; border-radius: 8px;
        padding: 9px 16px; font-size: .8rem; font-weight: 700;
        font-family: 'Plus Jakarta Sans', sans-serif;
        cursor: pointer; flex-shrink: 0; white-space: nowrap;
        min-height: 36px; -webkit-tap-highlight-color: transparent;
      }
      .wib-install:active { transform: scale(.96); }
      .wib-close {
        background: none; border: none; color: #5a5a5a;
        cursor: pointer; padding: 6px; display: flex;
        align-items: center; justify-content: center;
        flex-shrink: 0; border-radius: 50%;
        -webkit-tap-highlight-color: transparent;
      }
      .wib-close:hover { color: #fff; }

      /* ── iOS SHEET ── */
      #wavr-install-ios {
        position: fixed; inset: 0;
        background: rgba(0,0,0,.75);
        z-index: 88888; display: flex;
        align-items: flex-end; justify-content: center;
        opacity: 0; transition: opacity .25s ease;
        -webkit-tap-highlight-color: transparent;
      }
      #wavr-install-ios.wios-visible { opacity: 1; }
      .wios-sheet {
        background: #161616; border: 1px solid rgba(255,255,255,.1);
        border-radius: 22px 22px 0 0;
        padding: 12px 22px calc(28px + env(safe-area-inset-bottom, 0px));
        width: 100%; max-width: 480px;
        transform: translateY(100%);
        transition: transform .38s cubic-bezier(.4,0,.2,1);
      }
      #wavr-install-ios.wios-visible .wios-sheet { transform: translateY(0); }
      .wios-handle {
        width: 36px; height: 4px; border-radius: 2px;
        background: rgba(255,255,255,.15);
        margin: 0 auto 20px;
      }
      .wios-header {
        display: flex; align-items: center;
        justify-content: space-between; margin-bottom: 18px;
      }
      .wios-app { display: flex; align-items: center; gap: 12px; }
      .wios-appicon {
        width: 46px; height: 46px; border-radius: 12px;
        background: #F97316; display: flex; align-items: center; justify-content: center;
        font-family: 'Unbounded', sans-serif; font-size: 1.2rem;
        font-weight: 800; color: #000; flex-shrink: 0;
      }
      .wios-appname {
        font-family: 'Unbounded', sans-serif; font-size: .9rem;
        font-weight: 700; color: #fff; margin-bottom: 2px;
      }
      .wios-appsub { font-size: .75rem; color: #5a5a5a; font-family: 'JetBrains Mono', monospace; }
      .wios-close {
        background: rgba(255,255,255,.07); border: none; border-radius: 50%;
        width: 30px; height: 30px; display: flex; align-items: center;
        justify-content: center; color: #8a8a8a; cursor: pointer;
        -webkit-tap-highlight-color: transparent;
      }
      .wios-close:active { background: rgba(255,255,255,.15); }
      .wios-title {
        font-family: 'Unbounded', sans-serif; font-size: 1.1rem;
        font-weight: 800; letter-spacing: -.03em; margin-bottom: 6px;
      }
      .wios-desc { font-size: .83rem; color: #8a8a8a; line-height: 1.5; margin-bottom: 22px; }
      .wios-steps { display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; }
      .wios-step { display: flex; align-items: flex-start; gap: 12px; }
      .wios-step-num {
        width: 24px; height: 24px; border-radius: 50%;
        background: rgba(249,115,22,.15); border: 1px solid rgba(249,115,22,.3);
        color: #F97316; font-size: .72rem; font-weight: 700;
        font-family: 'JetBrains Mono', monospace;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; margin-top: 1px;
      }
      .wios-step-text { font-size: .85rem; color: #c0c0c0; line-height: 1.5; }
      .wios-step-text strong { color: #fff; }
      .wios-share-icon {
        display: inline-flex; align-items: center;
        vertical-align: middle; margin: 0 2px;
      }
      .wios-arrow-wrap {
        display: flex; flex-direction: column; align-items: center;
        gap: 6px; padding-top: 4px;
        border-top: 1px solid rgba(255,255,255,.06);
        padding-top: 16px;
      }
      .wios-arrow-label { font-size: .72rem; color: #5a5a5a; font-family: 'JetBrains Mono', monospace; }
      .wios-arrow {
        animation: wiosArrow 1.4s ease-in-out infinite;
        color: #F97316;
      }
      @keyframes wiosArrow {
        0%,100% { transform: translateY(0); }
        50%      { transform: translateY(8px); }
      }
    `
    document.head.appendChild(s)
  }

})()
