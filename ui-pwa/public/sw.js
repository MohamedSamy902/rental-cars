/* RPM Web Push service worker — يُخدم من جذر الموقع */
self.addEventListener('install', (event) => {
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim())
})

self.addEventListener('push', (event) => {
  let data = {
    title: 'آر بي إم',
    body: 'لديك إشعار جديد',
    url: '/notifications',
    dir: 'rtl',
    lang: 'ar',
  }
  try {
    if (event.data) {
      data = { ...data, ...event.data.json() }
    }
  } catch (_) {
    try {
      data.body = event.data ? event.data.text() : data.body
    } catch (_) {
      /* ignore */
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || 'آر بي إم', {
      body: data.body || '',
      icon: '/favicon.svg',
      badge: '/favicon.svg',
      dir: data.dir || 'rtl',
      lang: data.lang || 'ar',
      data: { url: data.url || '/notifications' },
      renotify: true,
      tag: data.notification_id ? `rpm-${data.notification_id}` : 'rpm-general',
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = (event.notification.data && event.notification.data.url) || '/notifications'
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate?.(target)
          return client.focus()
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(target)
      }
      return undefined
    }),
  )
})
