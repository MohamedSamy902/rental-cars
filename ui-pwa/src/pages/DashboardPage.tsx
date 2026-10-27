import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Area,
  AreaChart,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
} from 'recharts'
import { api } from '@/shared/api/client'
import {
  formatDateTime,
  formatDayShort,
  formatMoney,
  formatNumber,
  formatTime,
} from '@/shared/lib/format'
import { AnimatedNumber } from '@/shared/ui/AnimatedNumber'
import { ErrorState, SkeletonBlock } from '@/shared/ui'
import { IconMore, IconPlus, IconReturn } from '@/shared/ui/Icons'
import './DashboardPage.css'

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

const fleetTone: Record<string, string> = {
  available: '#34d399',
  rented: '#2dd4bf',
  reserved: '#fbbf24',
  maintenance: '#f87171',
  out_of_service: '#94a3b8',
}

function startOfDay(d: Date) {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}

export function DashboardPage() {
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [menuId, setMenuId] = useState<number | null>(null)

  useEffect(() => {
    api<Dash>('/dashboard')
      .then(setData)
      .catch((e) => setError(e.message || 'تعذّر تحميل اللوحة'))
      .finally(() => setLoading(false))
  }, [])

  const revenueSeries = useMemo(() => {
    if (!data) return []
    const today = startOfDay(new Date())
    const activeRates = data.recent_contracts
      .filter((c) => c.status === 'active' || c.status === 'approved')
      .reduce((sum, c) => sum + (c.frozen_daily_rate || 0), 0)
    const base = activeRates || Math.round((data.kpis.expected_revenue || 0) / 7)

    return Array.from({ length: 7 }, (_, i) => {
      const day = new Date(today)
      day.setDate(today.getDate() + i)
      const ending = data.recent_contracts.filter(
        (c) => startOfDay(new Date(c.planned_ends_at)).getTime() === day.getTime(),
      )
      const endingRevenue = ending.reduce((s, c) => s + (c.frozen_daily_rate || 0), 0)
      return {
        label: formatDayShort(day.toISOString()),
        value: Math.max(0, Math.round(base * (0.94 + i * 0.015) - endingRevenue * 0.3)),
      }
    })
  }, [data])

  const donut = useMemo(() => {
    if (!data) return []
    return [
      { key: 'available', name: 'متاح', value: data.fleet_status.available || 0 },
      { key: 'rented', name: 'مؤجّر', value: data.fleet_status.rented || 0 },
      { key: 'reserved', name: 'محجوز', value: data.fleet_status.reserved || 0 },
      { key: 'maintenance', name: 'صيانة', value: data.fleet_status.maintenance || 0 },
    ].filter((x) => x.value > 0)
  }, [data])

  const returns = useMemo(() => {
    if (!data) return []
    return [...data.recent_contracts]
      .sort((a, b) => new Date(a.planned_ends_at).getTime() - new Date(b.planned_ends_at).getTime())
      .slice(0, 5)
  }, [data])

  if (loading) {
    return (
      <div className="dash">
        <SkeletonBlock rows={9} />
      </div>
    )
  }

  if (error || !data) {
    return <ErrorState title="تعذّر تحميل لوحة اليوم" description={error} />
  }

  const instruments = [
    { label: 'متاحة', value: data.kpis.available || 0, kind: 'number' as const },
    { label: 'مؤجرة', value: data.kpis.rented || 0, kind: 'number' as const },
    { label: 'ترجع اليوم', value: data.kpis.returning_today || 0, kind: 'number' as const },
    { label: 'إيراد متوقع', value: data.kpis.expected_revenue || 0, kind: 'money' as const },
    { label: 'أوامر شغل', value: data.kpis.open_work_orders || 0, kind: 'number' as const },
  ]

  return (
    <div className="dash">
      <motion.section
        className="dash-hero"
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
        aria-label="إشغال الأسطول"
      >
        <div className="dash-hero__photo" aria-hidden />
        <div className="dash-hero__grain" aria-hidden />

        <div className="dash-hero__content">
          <p className="dash-hero__brand">RPM</p>

          <div className="dash-hero__copy">
            <h1>إشغال الأسطول الآن</h1>
            <p>
              مركز واحد لكل مركبة وعقد ومهمة — {formatNumber(data.fleet_total)} مركبة تحت
              الإدارة اليوم.
            </p>
          </div>

          <div className="dash-hero__foot">
            <div className="dash-hero__metric">
              <AnimatedNumber value={data.occupancy_pct} kind="percent" className="dash-hero__pct" />
              <div className="dash-hero__metric-meta">
                <span>نسبة الإشغال</span>
                <em>
                  {formatNumber(data.kpis.rented || 0)} مؤجرة · {formatNumber(data.kpis.available || 0)}{' '}
                  متاحة
                </em>
              </div>
            </div>

            <Link to="/bookings" className="dash-cta">
              <IconPlus size={17} />
              عقد جديد
            </Link>
          </div>
        </div>
      </motion.section>

      <motion.section
        className="dash-strip"
        aria-label="مؤشرات سريعة"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.1, duration: 0.4 }}
      >
        {instruments.map((item) => (
          <div key={item.label} className="dash-strip__item">
            <span>{item.label}</span>
            <strong>
              <AnimatedNumber value={item.value} kind={item.kind} duration={0.65} />
            </strong>
          </div>
        ))}
      </motion.section>

      <section className="dash-grid">
        <motion.article
          className="dash-panel dash-panel--chart"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.14, duration: 0.4 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>توقّع الإيراد</h2>
              <p>سبعة أيام · من العقود النشطة ومواعيد الإرجاع</p>
            </div>
            <span className="dash-panel__figure tabular-nums">
              {formatMoney(data.kpis.expected_revenue || 0)}
            </span>
          </header>
          <div className="dash-chart">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={revenueSeries} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="revFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--brand)" stopOpacity={0.3} />
                    <stop offset="100%" stopColor="var(--brand)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <XAxis
                  dataKey="label"
                  tickLine={false}
                  axisLine={false}
                  tick={{ fill: 'var(--text-muted)', fontSize: 11 }}
                />
                <Tooltip
                  formatter={(value) => [formatMoney(Number(value ?? 0)), 'توقّع']}
                  contentStyle={{
                    borderRadius: 14,
                    border: '1px solid var(--border)',
                    fontFamily: 'var(--font-body)',
                  }}
                />
                <Area
                  type="monotone"
                  dataKey="value"
                  stroke="var(--brand)"
                  strokeWidth={2.5}
                  fill="url(#revFill)"
                  animationDuration={850}
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </motion.article>

        <motion.article
          className="dash-panel dash-panel--fleet"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.18, duration: 0.4 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>حالة الأسطول</h2>
              <p>توزيع لحظي</p>
            </div>
          </header>
          <div className="dash-fleet">
            <div className="dash-fleet__chart">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={donut}
                    dataKey="value"
                    nameKey="name"
                    innerRadius="70%"
                    outerRadius="92%"
                    paddingAngle={3}
                    strokeWidth={0}
                  >
                    {donut.map((entry) => (
                      <Cell key={entry.key} fill={fleetTone[entry.key]} />
                    ))}
                  </Pie>
                </PieChart>
              </ResponsiveContainer>
              <div className="dash-fleet__center">
                <strong className="tabular-nums">{formatNumber(data.fleet_total)}</strong>
                <span>مركبة</span>
              </div>
            </div>
            <ul className="dash-fleet__legend">
              {donut.map((item) => (
                <li key={item.key}>
                  <i style={{ background: fleetTone[item.key] }} />
                  <span>{item.name}</span>
                  <b className="tabular-nums">{formatNumber(item.value)}</b>
                </li>
              ))}
            </ul>
          </div>
        </motion.article>

        <motion.article
          className="dash-panel dash-panel--tasks"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.22, duration: 0.4 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>يحتاج قراراً الآن</h2>
              <p>مهام اليوم</p>
            </div>
            <Link to="/bookings" className="dash-link">
              العقود
            </Link>
          </header>
          <ul className="dash-tasks">
            {data.tasks.length === 0 ? (
              <li className="dash-empty">لا مهام معلّقة حالياً</li>
            ) : (
              data.tasks.map((task, i) => (
                <motion.li
                  key={task.id}
                  className="dash-task"
                  initial={{ opacity: 0, x: 10 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: 0.04 * i }}
                >
                  <div>
                    <strong>{task.title}</strong>
                    <span>{task.subtitle}</span>
                    <time dateTime={task.at}>{formatDateTime(task.at)}</time>
                  </div>
                  <em className={`dash-status is-${task.status}`}>
                    {statusLabel[task.status] || task.status}
                  </em>
                  <div className="dash-qa">
                    <button
                      type="button"
                      aria-label="إجراءات"
                      aria-expanded={menuId === task.id}
                      onClick={() => setMenuId(menuId === task.id ? null : task.id)}
                    >
                      <IconMore size={16} />
                    </button>
                    <AnimatePresence>
                      {menuId === task.id ? (
                        <motion.div
                          className="dash-qa__menu"
                          initial={{ opacity: 0, y: 4 }}
                          animate={{ opacity: 1, y: 0 }}
                          exit={{ opacity: 0, y: 2 }}
                        >
                          <Link to="/bookings" onClick={() => setMenuId(null)}>
                            فتح العقود
                          </Link>
                          <Link to="/vehicles" onClick={() => setMenuId(null)}>
                            الأسطول
                          </Link>
                        </motion.div>
                      ) : null}
                    </AnimatePresence>
                  </div>
                </motion.li>
              ))
            )}
          </ul>
        </motion.article>

        <motion.article
          className="dash-panel dash-panel--returns"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.26, duration: 0.4 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>مواعيد الإرجاع</h2>
              <p>الخط الزمني القادم</p>
            </div>
            <Link to="/calendar" className="dash-link">
              التقويم
            </Link>
          </header>
          <ol className="dash-returns">
            {returns.length === 0 ? (
              <li className="dash-empty">
                <IconReturn size={18} />
                لا إرجاعات مجدولة
              </li>
            ) : (
              returns.map((c, i) => (
                <motion.li
                  key={c.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.03 * i }}
                >
                  <time dateTime={c.planned_ends_at}>
                    <b className="tabular-nums">{formatTime(c.planned_ends_at)}</b>
                    <span>{formatDayShort(c.planned_ends_at)}</span>
                  </time>
                  <div>
                    <strong>
                      {c.vehicle?.brand} {c.vehicle?.model}
                    </strong>
                    <p>
                      {c.customer?.full_name} · {c.vehicle?.plate} · {c.number}
                    </p>
                  </div>
                  <aside>
                    <em className={`dash-status is-${c.status}`}>
                      {statusLabel[c.status] || c.status}
                    </em>
                    <span className="tabular-nums">{formatMoney(c.frozen_daily_rate)}/يوم</span>
                  </aside>
                </motion.li>
              ))
            )}
          </ol>
        </motion.article>
      </section>
    </div>
  )
}
