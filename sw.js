const CACHE  = 'wavr-v13'
const STATIC = [
  '/index.html',
  '/register.html',
  '/app.html',
  '/call.html',
  '/css/style.css',
  '/js/signaling.js',
  '/js/webrtc.js',
  '/js/install.js',
  '/js/phantom-deeplink.js',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
]

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC)))
  self.skipWaiting()
})

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  )
  self.clients.claim()
})

self.addEventListener('fetch', e => {
  const url = e.request.url

  // Never cache API calls or WebSocket upgrades
  if(url.includes('/api/') || url.includes('ws://') || url.includes('wss://')) return

  // Network-first for HTML pages so auth redirects always work
  if(e.request.headers.get('accept')?.includes('text/html')){
    e.respondWith(
      fetch(e.request).catch(() => caches.match(e.request))
    )
    return
  }

  // Cache-first for static assets (css, js, images)
  e.respondWith(
    caches.match(e.request).then(r => r || fetch(e.request))
  )
})
