// Wavr shared UI kit — tab bar, bottom sheets, toasts, formatting.
// Pages using it need a <div id="toasts"></div> and css/style.css.

const UI = (() => {
  const ICON = {
    ok:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>',
    err:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" d="M12 8v5m0 3.5v.01"/><circle cx="12" cy="12" r="9"/></svg>',
    phone: '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.948V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 7V5z"/></svg>',
    flame: '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21c-4 0-7-2.7-7-6.6 0-2.8 1.7-4.9 3.4-6.6.4 1.7 1.3 2.8 2.4 3.3C10.6 7.6 12 4.6 14.6 3c-.2 2.4.9 4.2 2.4 5.8C18.4 10.3 19 12 19 14.4 19 18.3 16 21 12 21z"/></svg>',
    user:  '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>',
    copy:  '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/></svg>',
    out:   '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5M20 12H9M12 21H6a2 2 0 01-2-2V5a2 2 0 012-2h6"/></svg>',
    ext:   '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>',
  }

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]))
  }

  function haptic(ms = 10){ try { navigator.vibrate && navigator.vibrate(ms) } catch(e){} }

  function toast(type, msg){
    const c = document.getElementById('toasts')
    if(!c) return
    const t = document.createElement('div')
    t.className = 'toast ' + type
    t.innerHTML = `<span class="t-ico">${ICON[type] || ICON.ok}</span><span>${esc(msg)}</span>`
    c.appendChild(t)
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 260) }, 2800)
  }

  function relTime(when){
    const d = new Date(typeof when === 'string' && !/[zZ+]/.test(when.slice(-6)) ? when.replace(' ', 'T') : when)
    const s = Math.round((Date.now() - d.getTime()) / 1000)
    if(isNaN(s)) return ''
    if(s < 60)     return 'just now'
    if(s < 3600)   return Math.floor(s / 60) + 'm ago'
    if(s < 86400)  return Math.floor(s / 3600) + 'h ago'
    if(s < 604800) return Math.floor(s / 86400) + 'd ago'
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  }

  function compact(n){
    if(n == null || isNaN(n)) return '—'
    const a = Math.abs(n)
    if(a >= 1e9) return (n / 1e9).toFixed(a >= 1e10 ? 1 : 2).replace(/\.0+$/, '') + 'B'
    if(a >= 1e6) return (n / 1e6).toFixed(a >= 1e7 ? 1 : 2).replace(/\.0+$/, '') + 'M'
    if(a >= 1e3) return (n / 1e3).toFixed(a >= 1e4 ? 1 : 2).replace(/\.0+$/, '') + 'K'
    return (+n.toFixed(2)).toString()
  }

  // Memecoin-style price: $0.0₅3407 (subscript = number of zeros) — returns HTML.
  function price(n){
    if(n == null || isNaN(n)) return '—'
    if(n >= 1)      return '$' + n.toLocaleString('en-US', { maximumFractionDigits: 2 })
    if(n >= 0.001)  return '$' + n.toPrecision(4)
    const zeros = Math.ceil(-Math.log10(n)) - 1
    const digits = Math.round(n * Math.pow(10, zeros + 4)).toString().slice(0, 4)
    return `$0.0<sub>${zeros}</sub>${digits}`
  }

  function pct(n){
    if(n == null || isNaN(n)) return { text: '—', cls: '' }
    const v = +n
    return { text: (v > 0 ? '+' : '') + v.toFixed(Math.abs(v) >= 100 ? 0 : 1) + '%', cls: v > 0 ? 'up' : v < 0 ? 'down' : '' }
  }

  const short = a => a ? a.slice(0, 4) + '…' + a.slice(-4) : ''

  function tokenAva(image, symbol, size = 44){
    const ch = (symbol || '?').replace(/^\$/, '').charAt(0).toUpperCase()
    const letter = /[A-Z0-9]/.test(ch) ? ch : '?'   // also goes into an inline onerror string
    const fs = Math.round(size * 0.38)
    const img = image ? `<img src="${esc(image)}" alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode('${letter}'))">` : letter
    return `<div class="tok" style="width:${size}px;height:${size}px;font-size:${fs}px">${img}</div>`
  }

  // ── Bottom sheet ──
  function sheet(innerHtml, { onClose } = {}){
    const bg = document.createElement('div')
    bg.className = 'sheet-bg'
    bg.innerHTML = `<div class="sheet" role="dialog" aria-modal="true"><div class="whandle"></div>${innerHtml}</div>`
    document.body.appendChild(bg)
    requestAnimationFrame(() => bg.classList.add('on'))
    const close = () => {
      bg.classList.remove('on')
      setTimeout(() => bg.remove(), 320)
      document.removeEventListener('keydown', onKey)
      if(onClose) onClose()
    }
    const onKey = (e) => { if(e.key === 'Escape') close() }
    document.addEventListener('keydown', onKey)
    bg.addEventListener('click', (e) => { if(e.target === bg) close() })

    // Swipe the sheet down to dismiss
    const el = bg.querySelector('.sheet')
    let y0 = null
    el.addEventListener('touchstart', (e) => { if(el.scrollTop <= 0) y0 = e.touches[0].clientY }, { passive: true })
    el.addEventListener('touchmove',  (e) => {
      if(y0 == null) return
      const dy = Math.max(0, e.touches[0].clientY - y0)
      el.style.transition = 'none'; el.style.transform = `translateY(${dy}px)`
    }, { passive: true })
    el.addEventListener('touchend', (e) => {
      if(y0 == null) return
      const dy = e.changedTouches[0].clientY - y0
      el.style.transition = ''; el.style.transform = ''
      y0 = null
      if(dy > 90) close()
    })
    return { el, close }
  }

  function confirm({ title, body = '', ok = 'Confirm', danger = false }){
    return new Promise(resolve => {
      let answered = false
      const s = sheet(`
        <h3>${esc(title)}</h3>
        ${body ? `<p class="sub">${esc(body)}</p>` : ''}
        <div class="sheet-actions">
          <button class="btn btn-ghost" data-a="no">Cancel</button>
          <button class="btn ${danger ? 'btn-danger' : 'btn-fill'}" data-a="yes">${esc(ok)}</button>
        </div>`, { onClose: () => { if(!answered) resolve(false) } })
      s.el.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
        answered = true; resolve(b.dataset.a === 'yes'); s.close()
      })
    })
  }

  async function copy(text, label = 'Copied'){
    try { await navigator.clipboard.writeText(text); toast('ok', label); haptic() }
    catch(e){ toast('err', 'Copy failed') }
  }

  // ── Tab bar ──
  function tabBar(active){
    const nav = document.createElement('nav')
    nav.className = 'tabbar'
    nav.setAttribute('aria-label', 'Main')
    nav.innerHTML = `
      <a class="tab press ${active === 'calls'  ? 'active' : ''}" href="app.html">${ICON.phone}<span>Calls</span></a>
      <a class="tab press ${active === 'trench' ? 'active' : ''}" href="rooms.html">${ICON.flame}<span>Trench</span><i class="tab-live" id="tab-live"></i></a>
      <button class="tab press" id="tab-me" type="button">${ICON.user}<span>Me</span></button>`
    document.body.appendChild(nav)
    nav.querySelector('#tab-me').onclick = openMe
  }

  function setTrenchLive(on){
    const dot = document.getElementById('tab-live')
    if(dot) dot.classList.toggle('on', !!on)
  }

  function openMe(){
    const u = localStorage.getItem('wavr_username') || ''
    const a = localStorage.getItem('wavr_address') || ''
    const s = sheet(`
      <h3>@${esc(u)}</h3>
      <p class="sub">Your wallet is your identity. No email, no phone number.</p>
      <button class="sheet-row press" data-a="copy">${ICON.copy}<span class="grow">Copy wallet address<small>${esc(a)}</small></span></button>
      <a class="sheet-row press" style="text-decoration:none" href="https://solscan.io/account/${esc(a)}" target="_blank" rel="noopener">${ICON.ext}<span class="grow">View on Solscan</span></a>
      <button class="sheet-row press" data-a="out" style="color:var(--down)">${ICON.out}<span class="grow">Disconnect wallet</span></button>`)
    s.el.querySelector('[data-a="copy"]').onclick = () => copy(a, 'Wallet address copied')
    s.el.querySelector('[data-a="out"]').onclick = async () => {
      s.close()
      if(await confirm({ title: 'Disconnect wallet?', body: 'You\'ll need to sign in with your wallet again to make calls or join rooms.', ok: 'Disconnect', danger: true })){
        localStorage.clear(); sessionStorage.clear()
        window.location.href = 'index.html'
      }
    }
  }

  return { esc, haptic, toast, relTime, compact, price, pct, short, tokenAva, sheet, confirm, copy, tabBar, setTrenchLive, ICON }
})()
