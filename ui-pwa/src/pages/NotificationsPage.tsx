import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, SkeletonBlock, EmptyState, Button, useToast } from '@/shared/ui'
import { disableWebPush, enableWebPush, pushSupported, sendTestPush } from '@/shared/push/webPush'
import './pages.css'

type Note = { id: number; title: string; body: string; created_at: string; read_at?: string | null }

export function NotificationsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Note[]>([])
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(true)
  const [devices, setDevices] = useState(0)
  const [pushEnabled, setPushEnabled] = useState(false)
  const [busy, setBusy] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Note[]; unread?: number }>('/notifications')
      setRows(res.data)
      setUnread(res.unread ?? res.data.filter((n) => !n.read_at).length)
    } finally {
      setLoading(false)
    }
  }

  async function loadPushStatus() {
    try {
      const res = await api<{ enabled: boolean; devices: number }>('/push/status')
      setPushEnabled(res.enabled)
      setDevices(res.devices)
    } catch {
      setPushEnabled(false)
      setDevices(0)
    }
  }

  useEffect(() => {
    void load()
    void loadPushStatus()
  }, [])

  async function markRead(id: number) {
    await api(`/notifications/${id}/read`, { method: 'POST' })
    await load()
  }

  async function markAll() {
    const unreadRows = rows.filter((n) => !n.read_at)
    await Promise.all(unreadRows.map((n) => api(`/notifications/${n.id}/read`, { method: 'POST' })))
    await load()
  }

  async function onEnablePush() {
    setBusy(true)
    try {
      const result = await enableWebPush()
      if (!result.ok) {
        toast.error(result.message)
        return
      }
      toast.success('تم تفعيل إشعارات الموبايل على هذا الجهاز.')
      await loadPushStatus()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  async function onDisablePush() {
    setBusy(true)
    try {
      await disableWebPush()
      toast.success('تم إيقاف إشعارات هذا الجهاز.')
      await loadPushStatus()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  async function onTestPush() {
    setBusy(true)
    try {
      const message = await sendTestPush()
      toast.success(message)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الإشعارات"
        subtitle={unread > 0 ? `${unread} غير مقروء` : 'قائمة داخل النظام + إشعار يصل للموبايل'}
        action={unread > 0 ? <Button variant="secondary" onClick={() => void markAll()}>تعليم الكل كمقروء</Button> : undefined}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>إشعارات الموبايل (Push)</h3></div>
        <p className="muted" style={{ marginBottom: '0.75rem' }}>
          {pushSupported()
            ? 'فعّل الإذن ليصلك تنبيه على شاشة الموبايل حتى لو التطبيق مقفول. أندرويد: Chrome/Edge · آيفون: iOS 16.4+ بعد «إضافة إلى الشاشة الرئيسية» وعلى HTTPS.'
            : 'هذا المتصفح لا يدعم Web Push.'}
        </p>
        <p className="muted" style={{ marginBottom: '0.75rem' }}>
          حالة السيرفر: {pushEnabled ? 'مفاتيح VAPID جاهزة' : 'غير مفعّل — نفّذ php artisan push:vapid --write'}
          {' · '}
          أجهزة هذا الحساب: {devices}
        </p>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
          <Button loading={busy} onClick={() => void onEnablePush()}>تفعيل على هذا الجهاز</Button>
          {devices > 0 ? (
            <>
              <Button variant="secondary" loading={busy} onClick={() => void onTestPush()}>إرسال تجربة</Button>
              <Button variant="secondary" loading={busy} onClick={() => void onDisablePush()}>إيقاف هذا الجهاز</Button>
            </>
          ) : null}
        </div>
      </section>

      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد إشعارات" /> : null}
      <div className="rpm-card-list">
        {rows.map((n) => (
          <article key={n.id} className={`rpm-card ${n.read_at ? '' : 'is-unread'}`.trim()}>
            <div className="rpm-card__row">
              <strong>{n.title}</strong>
              {!n.read_at ? (
                <Button size="sm" variant="secondary" onClick={() => void markRead(n.id)}>تعليم كمقروء</Button>
              ) : (
                <span className="muted">مقروء</span>
              )}
            </div>
            <p>{n.body}</p>
            <p className="muted">{formatDateTime(n.created_at)}</p>
          </article>
        ))}
      </div>
    </div>
  )
}
