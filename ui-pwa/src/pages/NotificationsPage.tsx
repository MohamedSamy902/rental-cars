import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, SkeletonBlock, EmptyState, Button, useToast } from '@/shared/ui'
import { disableWebPush, enableWebPush, pushSupported, sendTestPush } from '@/shared/push/webPush'
import './pages.css'
import './NotificationsPage.css'

type Note = {
  id: number
  title: string
  body: string
  created_at: string
  read_at?: string | null
  link_type?: string | null
  link_id?: number | null
}

function shortcut(type?: string | null, id?: number | null): { to: string; label: string } | null {
  if (!type) return null
  if (type === 'contract' && id) return { to: `/contracts/${id}`, label: 'فتح العقد' }
  if (type === 'vehicle' && id) return { to: `/vehicles/${id}`, label: 'ملف المركبة' }
  if (type === 'customer') return { to: '/customers', label: 'العملاء' }
  if (type === 'owner') return { to: '/owners', label: 'الملاك' }
  if (type === 'payroll') return { to: '/hr', label: 'الموارد البشرية' }
  return null
}

function relativeDay(iso: string) {
  const d = new Date(iso)
  const today = new Date()
  const start = (x: Date) => Date.UTC(x.getFullYear(), x.getMonth(), x.getDate())
  const diff = Math.round((start(today) - start(d)) / 86400000)
  if (diff === 0) return 'اليوم'
  if (diff === 1) return 'أمس'
  return formatDateTime(iso).split('،')[0] || formatDateTime(iso)
}

export function NotificationsPage() {
  const toast = useToast()
  const navigate = useNavigate()
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

  async function openNote(n: Note) {
    if (!n.read_at) await markRead(n.id)
    const go = shortcut(n.link_type, n.link_id)
    if (go) navigate(go.to)
  }

  const grouped = useMemo(() => {
    const map = new Map<string, Note[]>()
    for (const n of rows) {
      const key = relativeDay(n.created_at)
      const list = map.get(key) ?? []
      list.push(n)
      map.set(key, list)
    }
    return [...map.entries()]
  }, [rows])

  return (
    <div className="rpm-stack nf">
      <PageHeader
        title="الإشعارات"
        subtitle={unread > 0 ? `${unread} غير مقروء` : 'سجل زمني داخل النظام + وصول للموبايل'}
        action={unread > 0 ? <Button variant="secondary" onClick={() => void markAll()}>تعليم الكل كمقروء</Button> : undefined}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>إشعارات الموبايل (Push)</h3></div>
        <p className="muted">
          {pushSupported()
            ? 'فعّل الإذن ليصلك تنبيه على شاشة الموبايل حتى لو التطبيق مقفول. أندرويد: Chrome/Edge · آيفون: iOS 16.4+ بعد «إضافة إلى الشاشة الرئيسية» وعلى HTTPS.'
            : 'هذا المتصفح لا يدعم Web Push.'}
        </p>
        <p className="muted">
          حالة السيرفر: {pushEnabled ? 'مفاتيح VAPID جاهزة' : 'غير مفعّل — نفّذ php artisan push:vapid --write'}
          {' · '}
          أجهزة هذا الحساب: {devices}
        </p>
        <div className="rpm-actions-row">
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

      <div className="nf-feed" role="feed" aria-label="سجل الإشعارات">
        {grouped.map(([day, items]) => (
          <section key={day} className="nf-day">
            <h3>{day}</h3>
            <ol>
              {items.map((n) => {
                const go = shortcut(n.link_type, n.link_id)
                const unreadNote = !n.read_at
                return (
                  <li key={n.id}>
                    <article className={`nf-item ${unreadNote ? 'is-unread' : ''}`.trim()}>
                      <button type="button" className="nf-item__main" onClick={() => void openNote(n)}>
                        <span className="nf-item__dot" aria-hidden />
                        <span className="nf-item__body">
                          <strong>{n.title}</strong>
                          <p>{n.body}</p>
                          <time dateTime={n.created_at}>{formatDateTime(n.created_at)}</time>
                        </span>
                      </button>
                      <div className="nf-item__actions">
                        {go ? <Button size="sm" variant="secondary" onClick={() => void openNote(n)}>{go.label}</Button> : null}
                        {unreadNote ? <Button size="sm" variant="secondary" onClick={() => void markRead(n.id)}>مقروء</Button> : null}
                      </div>
                    </article>
                  </li>
                )
              })}
            </ol>
          </section>
        ))}
      </div>
    </div>
  )
}
