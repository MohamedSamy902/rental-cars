import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  Button,
  PageHeader,
  SkeletonBlock,
  StatusPill,
  ErrorState,
  IconCar,
  IconClipboard,
  IconUsers,
  IconWrench,
  IconCalendar,
  IconPlus,
} from '@/shared/ui'
import './pages.css'

type Dash = {
  occupancy_pct: number
  fleet_total: number
  fleet_status: Record<string, number>
  kpis: Record<string, number>
  tasks: { id: number; title: string; subtitle: string; status: string; at: string }[]
  recent_contracts: {
    id: number
    number: string
    status: string
    frozen_daily_rate: number
    starts_at: string
    planned_ends_at: string
    customer?: { full_name: string }
    vehicle?: { brand: string; model: string; plate: string }
  }[]
}

const statusLabel: Record<string, string> = {
  pending: 'معلّق',
  approved: 'معتمد',
  active: 'نشط',
  completed: 'مكتمل',
  available: 'متاح',
  reserved: 'محجوز',
  rented: 'مؤجّر',
  maintenance: 'صيانة',
  out_of_service: 'خارج الخدمة',
}

const RING = 2 * Math.PI * 54

function OccupancyRing({ pct }: { pct: number }) {
  const clamped = Math.max(0, Math.min(100, pct))
  const offset = RING - (RING * clamped) / 100
  return (
    <div className="rpm-ring" aria-hidden>
      <svg viewBox="0 0 128 128" className="rpm-ring__svg">
        <circle className="rpm-ring__track" cx="64" cy="64" r="54" />
        <circle
          className="rpm-ring__value"
          cx="64"
          cy="64"
          r="54"
          style={{ strokeDasharray: RING, strokeDashoffset: offset }}
        />
      </svg>
      <div className="rpm-ring__center">
        <strong>{clamped}%</strong>
        <span>إشغال</span>
      </div>
    </div>
  )
}

export function DashboardPage() {
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api<Dash>('/dashboard')
      .then(setData)
      .catch((e) => setError(e.message || 'تعذّر تحميل اللوحة'))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <SkeletonBlock rows={8} />
  if (error || !data) {
    return <ErrorState title="تعذّر تحميل لوحة اليوم" description={error} />
  }

  const fleetBars = [
    { key: 'available', label: 'متاح', tone: 'available' },
    { key: 'rented', label: 'مؤجّر', tone: 'rented' },
    { key: 'reserved', label: 'محجوز', tone: 'reserved' },
    { key: 'maintenance', label: 'صيانة', tone: 'maintenance' },
  ]

  const rented = data.fleet_status.rented || 0
  const available = data.fleet_status.available || 0
  const other = Math.max(0, data.fleet_total - rented - available)
  const rentedPct = data.fleet_total ? (rented / data.fleet_total) * 100 : 0
  const availablePct = data.fleet_total ? (available / data.fleet_total) * 100 : 0
  const otherPct = data.fleet_total ? (other / data.fleet_total) * 100 : 0

  const metrics = [
    {
      key: 'available',
      label: 'متاحة الآن',
      value: String(data.kpis.available ?? 0),
      tone: 'success' as const,
      hint: 'جاهزة للتأجير',
    },
    {
      key: 'rented',
      label: 'مؤجرة',
      value: String(data.kpis.rented ?? 0),
      tone: 'brand' as const,
      hint: 'عقود نشطة',
    },
    {
      key: 'returning',
      label: 'ترجع اليوم',
      value: String(data.kpis.returning_today ?? 0),
      tone: 'warning' as const,
      hint: 'استلام متوقع',
    },
    {
      key: 'revenue',
      label: 'إيراد متوقع',
      value: formatMoney(data.kpis.expected_revenue || 0),
      tone: 'info' as const,
      hint: 'من العقود الجارية',
    },
    {
      key: 'wo',
      label: 'أوامر شغل',
      value: String(data.kpis.open_work_orders ?? 0),
      tone: 'danger' as const,
      hint: 'مفتوحة الآن',
    },
  ]

  const quickActions = [
    { to: '/bookings', label: 'عقد جديد', icon: <IconPlus size={16} />, primary: true },
    { to: '/vehicles', label: 'المركبات', icon: <IconCar size={16} /> },
    { to: '/customers', label: 'عميل جديد', icon: <IconUsers size={16} /> },
    { to: '/calendar', label: 'التقويم', icon: <IconCalendar size={16} /> },
    { to: '/maintenance', label: 'أمر شغل', icon: <IconWrench size={16} /> },
    { to: '/bookings', label: 'العقود', icon: <IconClipboard size={16} /> },
  ]

  return (
    <div className="rpm-stack">
      <PageHeader
        title="لوحة اليوم"
        subtitle="إشغال الأسطول · المهام · العقود الجارية"
        action={
          <Link to="/bookings">
            <Button>عقد جديد</Button>
          </Link>
        }
      />

      <section className="rpm-hero-occupancy" aria-label="بطل الإشغال">
        <div className="rpm-hero-occupancy__copy">
          <p className="rpm-kicker">تشغيل الأسطول الآن</p>
          <h2>{data.occupancy_pct}% إشغال</h2>
          <p>
            من أصل {data.fleet_total} مركبة — {available} متاحة · {rented} مؤجرة
            {other ? ` · ${other} أخرى` : ''}
          </p>
          <div className="rpm-hero-occupancy__bars" role="img" aria-label="توزيع الأسطول">
            <span className="is-rented" style={{ width: `${rentedPct}%` }} title="مؤجر" />
            <span className="is-available" style={{ width: `${availablePct}%` }} title="متاح" />
            <span className="is-other" style={{ width: `${otherPct}%` }} title="أخرى" />
          </div>
          <div className="rpm-hero-occupancy__legend">
            <span><i className="is-rented" /> مؤجر</span>
            <span><i className="is-available" /> متاح</span>
            <span><i className="is-other" /> أخرى</span>
          </div>
        </div>
        <OccupancyRing pct={data.occupancy_pct} />
      </section>

      <section className="rpm-metric-grid" aria-label="مؤشرات اليوم">
        {metrics.map((m) => (
          <article key={m.key} className={`rpm-metric tone-${m.tone}`}>
            <span className="rpm-metric__label">{m.label}</span>
            <strong className="rpm-metric__value">{m.value}</strong>
            <span className="rpm-metric__sub">{m.hint}</span>
          </article>
        ))}
      </section>

      <section className="rpm-quick-actions" aria-label="إجراءات سريعة">
        {quickActions.map((a) => (
          <Link
            key={a.label}
            to={a.to}
            className={`rpm-quick-action ${a.primary ? 'is-primary' : ''}`}
          >
            <span className="rpm-quick-action__icon">{a.icon}</span>
            {a.label}
          </Link>
        ))}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>حالة الأسطول</h3>
          <Link to="/vehicles" className="rpm-text-link">عرض الأسطول</Link>
        </div>
        <div className="rpm-status-bars">
          {fleetBars.map((bar) => {
            const value = data.fleet_status[bar.key] || 0
            const pct = data.fleet_total ? Math.round((value / data.fleet_total) * 100) : 0
            return (
              <div key={bar.key} className="rpm-status-bar">
                <div className="rpm-status-bar__meta">
                  <span>{bar.label}</span>
                  <strong>{value} · {pct}%</strong>
                </div>
                <div className="rpm-status-bar__track">
                  <span className={`fill-${bar.tone}`} style={{ width: `${pct}%` }} />
                </div>
              </div>
            )
          })}
        </div>
      </section>

      <div className="rpm-two-col">
        <section className="rpm-panel">
          <div className="rpm-panel__head">
            <h3>مهام اليوم</h3>
          </div>
          {data.tasks.length === 0 ? (
            <p className="muted">لا مهام مجدولة لهذا اليوم.</p>
          ) : (
            <ul className="rpm-task-list">
              {data.tasks.map((t) => (
                <li key={t.id}>
                  <div>
                    <strong>{t.title}</strong>
                    <span>{t.subtitle}</span>
                  </div>
                  <StatusPill status={t.status} label={statusLabel[t.status] || t.status} />
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="rpm-panel">
          <div className="rpm-panel__head">
            <h3>أحدث العقود</h3>
            <Link to="/bookings" className="rpm-text-link">عرض الكل</Link>
          </div>
          <div className="rpm-dense-list">
            {data.recent_contracts.map((c) => (
              <article key={c.id} className="rpm-dense-row">
                <div>
                  <strong>{c.number}</strong>
                  <p>{c.customer?.full_name}</p>
                  <p className="muted">
                    {c.vehicle?.brand} {c.vehicle?.model} · {c.vehicle?.plate}
                  </p>
                  <p className="muted">
                    {formatDateTime(c.starts_at)} → {formatDateTime(c.planned_ends_at)}
                  </p>
                </div>
                <div className="rpm-dense-row__side">
                  <StatusPill status={c.status} label={statusLabel[c.status] || c.status} />
                  <span>{formatMoney(c.frozen_daily_rate)} / يوم</span>
                </div>
              </article>
            ))}
          </div>
        </section>
      </div>
    </div>
  )
}
