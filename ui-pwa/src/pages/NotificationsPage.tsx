import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import { PageHeader, FilterBar, SkeletonBlock, EmptyState, Button, Badge, useToast } from '@/shared/ui'
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

const PUSH_BANNER_KEY = 'rpm.nf.pushBannerOpen'

function shortcut(type?: string | null, id?: number | null): { to: string; label: string } | null {
  if (!type) return null
  if (type === 'contract' && id) return { to: `/contracts/${id}`, label: 'فتح العقد' }
  if (type === 'vehicle' && id) return { to: `/vehicles/${id}`, label: 'ملف العربية' }
  if (type === 'customer') return { to: '/customers', label: 'العملاء' }
  if (type === 'owner') return { to: '/owners', label: 'الملاك' }
  if (type === 'payroll') return { to: '/hr', label: 'الموارد البشرية' }
  if (type === 'inventory') return { to: '/inventory', label: 'المخزون' }
  return null
}

function categoryMeta(type?: string | null): { label: string; tone: 'brand' | 'warning' | 'steel' | 'muted' | 'accent' | 'success' } | null {
  switch (type) {
    case 'contract':
      return { label: 'عقد', tone: 'brand' }
    case 'vehicle':
      return { label: 'عربية', tone: 'steel' }
    case 'customer':
      return { label: 'عميل', tone: 'accent' }
    case 'owner':
      return { label: 'مالك', tone: 'muted' }
    case 'payroll':
      return { label: 'الموظف', tone: 'warning' }
    case 'inventory':
      return { label: 'المخزون', tone: 'success' }
    case 'deposit':
      return { label: 'تأمين', tone: 'warning' }
    default:
      return type ? { label: 'نظام', tone: 'muted' } : null
  }
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

function readBannerPref(): boolean | null {
  try {
    const raw = localStorage.getItem(PUSH_BANNER_KEY)
    if (raw === '1') return true
    if (raw === '0') return false
  } catch {
    /* ignore */
  }
  return null
}

function writeBannerPref(open: boolean) {
  try {
    localStorage.setItem(PUSH_BANNER_KEY, open ? '1' : '0')
  } catch {
    /* ignore */
  }
}

/** الكل must stay first (RTL far-right) and remain the default active tab. */
const NotificationsPageTabs = ['all', 'unread'] as const

export function NotificationsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Note[]>([])
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(true)
  const [devices, setDevices] = useState(0)
  const [pushEnabled, setPushEnabled] = useState(false)
  const [busy, setBusy] = useState(false)
  const [bannerOpen, setBannerOpen] = useState(() => {
    const pref = readBannerPref()
    return pref === null ? true : pref
  })
  const [chip, setChip] = useQueryTab({ allowed: NotificationsPageTabs, defaultValue: 'all' })

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'unread',
        label: 'غير مقروء',
        attention: unread > 0 ? ('warning' as const) : null,
        attentionLabel: `${unread} إشعار غير مقروء`,
      },
    ],
    [unread],
  )

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
      if (readBannerPref() === null && res.enabled && res.devices > 0) {
        setBannerOpen(false)
      }
    } catch {
      setPushEnabled(false)
      setDevices(0)
    }
  }

  useEffect(() => {
    void load()
    void loadPushStatus()
  }, [])

  function toggleBanner() {
    setBannerOpen((prev) => {
      const next = !prev
      writeBannerPref(next)
      return next
    })
  }

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

  const visibleRows = useMemo(
    () => (chip === 'unread' ? rows.filter((n) => !n.read_at) : rows),
    [rows, chip],
  )

  const grouped = useMemo(() => {
    const map = new Map<string, Note[]>()
    for (const n of visibleRows) {
      const key = relativeDay(n.created_at)
      const list = map.get(key) ?? []
      list.push(n)
      map.set(key, list)
    }
    return [...map.entries()]
  }, [visibleRows])

  const supported = pushSupported()
  const pushStatusLabel = !supported
    ? 'غير مدعوم على هذا المتصفح'
    : !pushEnabled
      ? 'السيرفر غير جاهز'
      : devices > 0
        ? `مفعّل · ${devices} جهاز`
        : 'جاهز — لم يُفعَّل على هذا الجهاز'

  return (
    <div className="rpm-stack nf">
      <PageHeader
        title="الإشعارات"
        subtitle={unread > 0 ? `${unread} غير مقروء` : 'سجل زمني داخل النظام + وصول للموبايل'}
        action={unread > 0 ? <Button variant="secondary" onClick={() => void markAll()}>تعليم الكل كمقروء</Button> : undefined}
      />

      <section className={`nf-push ${bannerOpen ? 'is-open' : 'is-collapsed'}`.trim()} aria-label="إشعارات الموبايل">
        <button
          type="button"
          className="nf-push__bar"
          onClick={toggleBanner}
          aria-expanded={bannerOpen}
          aria-controls="nf-push-panel"
        >
          <span className="nf-push__lead">
            <span className={`nf-push__pulse ${pushEnabled && devices > 0 ? 'is-on' : ''}`.trim()} aria-hidden />
            <span className="nf-push__title">إشعارات الموبايل</span>
            <span className="nf-push__status">{pushStatusLabel}</span>
          </span>
          <span className="nf-push__chevron" aria-hidden>{bannerOpen ? '⌃' : '⌄'}</span>
        </button>

        {bannerOpen ? (
          <div id="nf-push-panel" className="nf-push__panel">
            <p className="nf-push__hint">
              {supported
                ? 'فعّل الإذن ليصلك تنبيه على شاشة الموبايل حتى لو التطبيق مقفول. أندرويد: Chrome/Edge · آيفون: iOS 16.4+ بعد «إضافة إلى الشاشة الرئيسية» وعلى HTTPS.'
                : 'هذا المتصفح لا يدعم Web Push.'}
            </p>
            <p className="nf-push__meta">
              السيرفر: {pushEnabled ? 'مفاتيح VAPID جاهزة' : 'غير مفعّل — نفّذ php artisan push:vapid --write'}
              {' · '}
              أجهزة هذا الحساب: {devices}
            </p>
            <div className="nf-push__actions">
              <Button size="sm" loading={busy} onClick={() => void onEnablePush()}>تفعيل على هذا الجهاز</Button>
              {devices > 0 ? (
                <>
                  <Button size="sm" variant="secondary" loading={busy} onClick={() => void onTestPush()}>إرسال تجربة</Button>
                  <Button size="sm" variant="secondary" loading={busy} onClick={() => void onDisablePush()}>إيقاف هذا الجهاز</Button>
                </>
              ) : null}
            </div>
          </div>
        ) : null}
      </section>

      <FilterBar
        hideSearch
        search=""
        onSearchChange={() => undefined}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="فلترة الإشعارات"
      />

      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && visibleRows.length === 0 ? (
        <EmptyState title={chip === 'unread' ? 'لا إشعارات غير مقروءة' : 'لا توجد إشعارات'} />
      ) : null}

      <div className="nf-feed" role="feed" aria-label="سجل الإشعارات">
        {grouped.map(([day, items]) => (
          <section key={day} className="nf-day">
            <header className="nf-day__head">
              <h3>{day}</h3>
              <span className="nf-day__count tabular-nums">{items.length}</span>
            </header>
            <ol>
              {items.map((n) => {
                const go = shortcut(n.link_type, n.link_id)
                const cat = categoryMeta(n.link_type)
                const unreadNote = !n.read_at
                return (
                  <li key={n.id}>
                    <article className={`nf-card ${unreadNote ? 'is-unread' : ''}`.trim()}>
                      <button type="button" className="nf-card__main" onClick={() => void openNote(n)}>
                        <span className="nf-card__dot" aria-hidden />
                        <span className="nf-card__body">
                          <span className="nf-card__top">
                            <strong className="nf-card__title">{n.title}</strong>
                            <time className="nf-card__time tabular-nums" dateTime={n.created_at}>
                              {formatDateTime(n.created_at)}
                            </time>
                          </span>
                          <p className="nf-card__text">{n.body}</p>
                          {cat ? (
                            <span className="nf-card__tags">
                              <Badge tone={cat.tone} dot={false}>{cat.label}</Badge>
                            </span>
                          ) : null}
                        </span>
                      </button>
                      <div className="nf-card__actions">
                        {go ? (
                          <Button size="sm" variant="secondary" onClick={() => void openNote(n)}>
                            {go.label}
                          </Button>
                        ) : null}
                        {unreadNote ? (
                          <Button size="sm" variant="ghost" onClick={() => void markRead(n.id)}>
                            مقروء
                          </Button>
                        ) : null}
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
