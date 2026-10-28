import { api } from '@/shared/api/client'

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i)
  return output
}

export function pushSupported(): boolean {
  return typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window
}

export async function registerPushWorker(): Promise<ServiceWorkerRegistration | null> {
  if (!pushSupported()) return null
  return navigator.serviceWorker.register('/sw.js', { scope: '/' })
}

export async function enableWebPush(): Promise<{ ok: true } | { ok: false; message: string }> {
  if (!pushSupported()) {
    return { ok: false, message: 'المتصفح لا يدعم إشعارات الدفع. استخدم Chrome/Edge على أندرويد أو Safari 16.4+ بعد إضافة التطبيق للشاشة الرئيسية.' }
  }
  if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
    return { ok: false, message: 'إشعارات الدفع تحتاج HTTPS.' }
  }

  const vapid = await api<{ enabled: boolean; publicKey?: string; message?: string }>('/push/vapid-public-key')
  if (!vapid.enabled || !vapid.publicKey) {
    return { ok: false, message: vapid.message || 'السيرفر غير مضبوط بمفاتيح VAPID.' }
  }

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    return { ok: false, message: 'تم رفض إذن الإشعارات من المتصفح.' }
  }

  const reg = await registerPushWorker()
  if (!reg) return { ok: false, message: 'تعذّر تسجيل Service Worker.' }
  await navigator.serviceWorker.ready

  const existing = await reg.pushManager.getSubscription()
  const sub = existing || await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(vapid.publicKey) as BufferSource,
  })

  const json = sub.toJSON()
  if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
    return { ok: false, message: 'تعذّر قراءة مفاتيح الاشتراك من المتصفح.' }
  }

  await api('/push/subscribe', {
    method: 'POST',
    json: {
      endpoint: json.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
      contentEncoding: 'aesgcm',
    },
  })

  return { ok: true }
}

export async function disableWebPush(): Promise<void> {
  if (!pushSupported()) return
  const reg = await navigator.serviceWorker.getRegistration('/')
  const sub = await reg?.pushManager.getSubscription()
  if (sub) {
    try {
      await api('/push/unsubscribe', { method: 'POST', json: { endpoint: sub.endpoint } })
    } catch {
      /* ignore */
    }
    await sub.unsubscribe()
  }
}

export async function sendTestPush(): Promise<string> {
  const res = await api<{ message: string }>('/push/test', { method: 'POST' })
  return res.message
}
