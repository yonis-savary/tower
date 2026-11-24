// Tower service worker : shows the push notifications and opens Tower on a click. No offline cache.
// Served at the root, so that it covers the whole site.

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let message = {}
  try {
    message = event.data ? event.data.json() : {}
  } catch {
    message = { body: event.data ? event.data.text() : '' }
  }

  event.waitUntil(
    self.registration.showNotification(message.title || 'Tower', {
      body: message.body || '',
      icon: message.icon || '/icon-192.png',
      // The status bar icon : Android keeps only its transparency, so the logo-mark in white on nothing
      badge: '/badge-96.png',
      // A notification with the same tag replaces the previous one, and still alerts
      tag: message.tag,
      renotify: Boolean(message.tag),
      data: { url: message.url || '/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/', self.location.origin)

  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const opened = windows.find((client) => new URL(client.url).origin === target.origin)

      // A tab already open navigates inside the application, without reloading
      if (opened) {
        await opened.focus()
        opened.postMessage({ type: 'tower.navigate', url: target.pathname + target.search })
        return
      }

      await self.clients.openWindow(target.href)
    })(),
  )
})
