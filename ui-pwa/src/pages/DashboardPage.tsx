import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
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
import { useAuth } from '@/shared/auth/AuthContext'
import {
  formatDayShort,
  formatMoney,
  formatNumber,
  formatPercent,
  formatTime,
} from '@/shared/lib/format'
import { AnimatedNumber } from '@/shared/ui/AnimatedNumber'
import { ErrorState, SkeletonBlock } from '@/shared/ui'
import {
  IconCar,
  IconClipboard,
  IconPlus,
  IconReturn,
  IconTrend,
  IconUsers,
  IconWrench,
} from '@/shared/ui/Icons'
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

const fleetTone: Record<string, string> = {
  available: '#16a34a',
  rented: '#0d9488',
  reserved: '#ea580c',
  maintenance: '#dc2626',
}

function startOfDay(d: Date) {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}

function urgencyPill(at: string, status: string) {
  if (status === 'completed') return { label: 'مكتمل', tone: 'ok' }
  const diffH = (new Date(at).getTime() - Date.now()) / 36e5
  if (diffH < 0) return { label: 'متأخر', tone: 'danger' }
  if (diffH < 24) return { label: 'اليوم', tone: 'warn' }
  if (diffH < 48) return { label: 'غداً', tone: 'warn' }
  const days = Math.ceil(diffH / 24)
  return { label: `${days} يوم`, tone: 'danger' }
}

function MiniSpark({ values, tone = 'brand' }: { values: number[]; tone?: string }) {
  const w = 88
  const h = 32
  const max = Math.max(...values, 1)
  const min = Math.min(...values, 0)
  const span = Math.max(max - min, 1)
  const points = values
    .map((v, i) => {
      const x = (i / Math.max(values.length - 1, 1)) * w
      const y = h - ((v - min) / span) * (h - 4) - 2
      return `${x},${y}`
    })
    .join(' ')
  const fillPoints = `0,${h} ${points} ${w},${h}`

  return (
    <svg className={`db-spark tone-${tone}`} viewBox={`0 0 ${w} ${h}`} width={w} height={h} aria-hidden>
      <polygon points={fillPoints} className="db-spark__fill" />
      <polyline points={points} className="db-spark__line" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

function sparkFromValue(value: number, seed: number): number[] {
  const base = Math.max(value, 1)
  return Array.from({ length: 8 }, (_, i) => {
    const wave = Math.sin((i + seed) * 0.85) * 0.18 + 1
    const ramp = 0.72 + i * 0.04
    return Math.max(0, Math.round(base * wave * ramp))
  })
}

export function DashboardPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

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
      .slice(0, 4)
  }, [data])

  if (loading) {
    return (
      <div className="db">
        <SkeletonBlock rows={10} />
      </div>
    )
  }

  if (error || !data) {
    return <ErrorState title="تعذّر تحميل لوحة اليوم" description={error} />
  }

  const firstName = (user?.name || 'مستخدم').split(' ')[0]
  const openTasks = data.tasks.length
  const ring = Math.PI * 2 * 54

  const revenueSpark = revenueSeries.map((d) => d.value)
  const kpis = [
    {
      label: 'متاحة الآن',
      value: data.kpis.available || 0,
      kind: 'number' as const,
      hint: `من ${formatNumber(data.fleet_total)}`,
      icon: <IconCar size={18} />,
      tone: 'ok',
      spark: sparkFromValue(data.kpis.available || 1, 1),
    },
    {
      label: 'عقود نشطة',
      value: data.kpis.rented || 0,
      kind: 'number' as const,
      hint: 'مؤجّرة حالياً',
      icon: <IconClipboard size={18} />,
      tone: 'brand',
      spark: sparkFromValue(data.kpis.rented || 1, 2),
    },
    {
      label: 'ترجع اليوم',
      value: data.kpis.returning_today || 0,
      kind: 'number' as const,
      hint: 'تحتاج استلام',
      icon: <IconReturn size={18} />,
      tone: 'warn',
      spark: sparkFromValue(data.kpis.returning_today || 1, 3),
    },
    {
      label: 'إيراد متوقع',
      value: data.kpis.expected_revenue || 0,
      kind: 'money' as const,
      hint: 'أفق التشغيل',
      icon: <IconTrend size={18} />,
      tone: 'brand',
      spark: revenueSpark.length ? revenueSpark : sparkFromValue(data.kpis.expected_revenue || 1, 4),
    },
  ]

  return (
    <div className="db">
      <motion.header
        className="db-welcome"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div>
          <h1>مرحباً، {firstName}</h1>
          <p>
            لديك <strong className="tabular-nums">{formatNumber(openTasks)}</strong> مهام تحتاج متابعة
            و <strong className="tabular-nums">{formatNumber(data.kpis.returning_today || 0)}</strong> مركبة
            ترجع اليوم.
          </p>
        </div>
        <button type="button" className="db-cta" onClick={() => navigate('/bookings?new=1')}>
          <IconPlus size={16} />
          عقد جديد
        </button>
      </motion.header>

      <section className="db-kpis" aria-label="مؤشرات سريعة">
        {kpis.map((k, i) => (
          <motion.article
            key={k.label}
            className={`db-kpi tone-${k.tone}`}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.04 * i, duration: 0.35 }}
          >
            <div className="db-kpi__top">
              <span>{k.label}</span>
              <span className="db-kpi__ico">{k.icon}</span>
            </div>
            <div className="db-kpi__row">
              <strong>
                <AnimatedNumber value={k.value} kind={k.kind} />
              </strong>
              <MiniSpark values={k.spark} tone={k.tone} />
            </div>
            <em>{k.hint}</em>
          </motion.article>
        ))}
      </section>

      <section className="db-bento">
        {/* TrueTrack-style gauge hero card */}
        <motion.article
          className="db-card db-card--gauge"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>صحة الإشغال</h2>
              <p>قراءة لحظية للأسطول النشط</p>
            </div>
            <Link to="/vehicles" className="db-link">
              الأسطول
            </Link>
          </div>

          <div className="db-gauge">
            <div className="db-gauge__ring" aria-hidden>
              <svg viewBox="0 0 140 140">
                <circle cx="70" cy="70" r="54" className="track" />
                <circle
                  className="dots"
                  cx="70"
                  cy="70"
                  r="62"
                  strokeDasharray="2 8"
                />
                <motion.circle
                  cx="70"
                  cy="70"
                  r="54"
                  className="progress"
                  strokeDasharray={ring}
                  initial={{ strokeDashoffset: ring }}
                  animate={{
                    strokeDashoffset: ring * (1 - Math.min(data.occupancy_pct, 100) / 100),
                  }}
                  transition={{ duration: 1.1, ease: [0.22, 1, 0.36, 1] }}
                />
              </svg>
              <div className="db-gauge__center">
                <AnimatedNumber value={data.occupancy_pct} kind="percent" className="db-gauge__pct" />
                <span>إشغال</span>
              </div>
            </div>

            <ul className="db-gauge__stats">
              <li>
                <span>أسطول</span>
                <b className="tabular-nums">{formatNumber(data.fleet_total)}</b>
              </li>
              <li>
                <span>مؤجّر</span>
                <b className="tabular-nums">{formatNumber(data.kpis.rented || 0)}</b>
              </li>
              <li>
                <span>صيانة</span>
                <b className="tabular-nums">{formatNumber(data.fleet_status.maintenance || 0)}</b>
              </li>
              <li>
                <span>أوامر شغل</span>
                <b className="tabular-nums">{formatNumber(data.kpis.open_work_orders || 0)}</b>
              </li>
            </ul>
          </div>
        </motion.article>

        {/* TrueTrack reminders */}
        <motion.article
          className="db-card db-card--reminders"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.14, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>تذكيرات قادمة</h2>
              <p>ما يحتاج قراراً أولاً</p>
            </div>
            <Link to="/bookings" className="db-link">
              العقود
            </Link>
          </div>
          <ul className="db-reminders">
            {data.tasks.length === 0 ? (
              <li className="db-empty">لا تذكيرات معلّقة</li>
            ) : (
              data.tasks.map((task, i) => {
                const pill = urgencyPill(task.at, task.status)
                return (
                  <motion.li
                    key={task.id}
                    initial={{ opacity: 0, x: 8 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: 0.04 * i }}
                  >
                    <span className="db-reminders__ico" aria-hidden>
                      <IconClipboard size={16} />
                    </span>
                    <div>
                      <strong>{task.title}</strong>
                      <span>{task.subtitle}</span>
                    </div>
                    <em className={`db-pill is-${pill.tone}`}>{pill.label}</em>
                  </motion.li>
                )
              })
            )}
          </ul>
        </motion.article>

        {/* Ecommerce revenue chart */}
        <motion.article
          className="db-card db-card--chart"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.18, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>اتجاه الإيراد</h2>
              <p>سبعة أيام · عقود نشطة</p>
            </div>
            <strong className="db-card__figure">
              <AnimatedNumber value={data.kpis.expected_revenue || 0} kind="money" />
            </strong>
          </div>
          <div className="db-chart">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={revenueSeries} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="revFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--brand)" stopOpacity={0.28} />
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

        {/* Fleet donut */}
        <motion.article
          className="db-card db-card--fleet"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.22, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>حالة الأسطول</h2>
              <p>توزيع المركبات</p>
            </div>
          </div>
          <div className="db-fleet">
            <div className="db-fleet__chart">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={donut}
                    dataKey="value"
                    nameKey="name"
                    innerRadius="68%"
                    outerRadius="92%"
                    paddingAngle={3}
                    strokeWidth={0}
                  >
                    {donut.map((entry) => (
                      <Cell key={entry.key} fill={fleetTone[entry.key] || '#94a3b8'} />
                    ))}
                  </Pie>
                </PieChart>
              </ResponsiveContainer>
              <div className="db-fleet__center">
                <strong className="tabular-nums">{formatNumber(data.fleet_total)}</strong>
                <span>مركبة</span>
              </div>
            </div>
            <ul className="db-fleet__legend">
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

        {/* Low-stock style alerts → returns */}
        <motion.article
          className="db-card db-card--alerts"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.26, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>إرجاعات قادمة</h2>
              <p>تنبيهات الاستلام</p>
            </div>
            <Link to="/calendar" className="db-link">
              التقويم
            </Link>
          </div>
          <ul className="db-alerts">
            {returns.length === 0 ? (
              <li className="db-empty">
                <IconReturn size={18} />
                لا إرجاعات مجدولة
              </li>
            ) : (
              returns.map((c) => (
                <li key={c.id}>
                  <div>
                    <strong>
                      {c.vehicle?.brand} {c.vehicle?.model}
                    </strong>
                    <span>
                      {c.number} · {c.customer?.full_name}
                    </span>
                  </div>
                  <em className="db-alerts__meta">
                    <b className="tabular-nums">{formatTime(c.planned_ends_at)}</b>
                    <span>{formatDayShort(c.planned_ends_at)}</span>
                  </em>
                </li>
              ))
            )}
          </ul>
        </motion.article>

        <motion.article
          className="db-card db-card--quick"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.3, duration: 0.35 }}
        >
          <div className="db-card__head">
            <div>
              <h2>اختصارات</h2>
              <p>وصول سريع</p>
            </div>
          </div>
          <div className="db-quick">
            <Link to="/customers">
              <IconUsers size={18} />
              العملاء
              <b className="tabular-nums">{formatNumber(data.kpis.customers || 0)}</b>
            </Link>
            <Link to="/maintenance">
              <IconWrench size={18} />
              الصيانة
              <b className="tabular-nums">{formatNumber(data.kpis.open_work_orders || 0)}</b>
            </Link>
            <Link to="/vehicles">
              <IconCar size={18} />
              المتاح
              <b className="tabular-nums">{formatPercent(100 - data.occupancy_pct)}</b>
            </Link>
          </div>
        </motion.article>
      </section>
    </div>
  )
}
