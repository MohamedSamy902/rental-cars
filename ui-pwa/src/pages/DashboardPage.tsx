import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import { Button, PageHeader, SkeletonBlock, StatusPill, ErrorState } from '@/shared/ui'
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

      <section className="rpm-metric-grid">
        <article className="rpm-metric">
          <span className="rpm-metric__label">إشغال الأسطول</span>
          <strong className="rpm-metric__value">{data.occupancy_pct}%</strong>
          <span className="rpm-metric__sub">من أصل {data.fleet_total} مركبة</span>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">متاحة الآن</span>
          <strong className="rpm-metric__value is-success">{data.kpis.available}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">مؤجرة</span>
          <strong className="rpm-metric__value">{data.kpis.rented}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">ترجع اليوم</span>
          <strong className="rpm-metric__value is-warning">{data.kpis.returning_today}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">إيراد متوقع</span>
          <strong className="rpm-metric__value">{formatMoney(data.kpis.expected_revenue || 0)}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">أوامر شغل مفتوحة</span>
          <strong className="rpm-metric__value">{data.kpis.open_work_orders}</strong>
        </article>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>حالة الأسطول</h3>
        </div>
        <div className="rpm-status-bars">
          {fleetBars.map((bar) => {
            const value = data.fleet_status[bar.key] || 0
            const pct = data.fleet_total ? Math.round((value / data.fleet_total) * 100) : 0
            return (
              <div key={bar.key} className="rpm-status-bar">
                <div className="rpm-status-bar__meta">
                  <span>{bar.label}</span>
                  <strong>{value}</strong>
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
