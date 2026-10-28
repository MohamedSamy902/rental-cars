import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
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
  YAxis,
} from 'recharts'
import { api } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { useTheme, type ThemeMode } from '@/shared/theme/ThemeContext'
import {
  formatDayShort,
  formatMoney,
  formatNumber,
  formatPercent,
  formatTime,
} from '@/shared/lib/format'
import { AnimatedNumber } from '@/shared/ui/AnimatedNumber'
import { Badge, type BadgeTone } from '@/shared/ui/Badge'
import { Card, CardContent, CardHeader } from '@/shared/ui/Card'
import { ErrorState, SkeletonBlock } from '@/shared/ui'
import {
  IconCar,
  IconClipboard,
  IconMore,
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

/** SVG chart attributes can't resolve CSS variables, so charts read a per-theme palette */
const chartPalette: Record<ThemeMode, {
  line: string
  axis: string
  cursor: string
  dot: string
  dotRing: string
  slice: string
  fleet: Record<string, string>
  fallback: string
}> = {
  light: {
    line: '#18181b',
    axis: '#71717a',
    cursor: '#d4d4d8',
    dot: '#dc2626',
    dotRing: '#ffffff',
    slice: '#ffffff',
    fleet: { available: '#059669', rented: '#334155', reserved: '#71717a', maintenance: '#f59e0b' },
    fallback: '#a1a1aa',
  },
  dark: {
    line: '#fafafa',
    axis: '#a1a1aa',
    cursor: '#3f3f46',
    dot: '#ef4444',
    dotRing: '#141416',
    slice: '#141416',
    fleet: { available: '#34d399', rented: '#94a3b8', reserved: '#52525b', maintenance: '#fbbf24' },
    fallback: '#52525b',
  },
}

function startOfDay(d: Date) {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}

function urgencyPill(at: string, status: string): { label: string; tone: BadgeTone } {
  if (status === 'completed') return { label: 'مكتمل', tone: 'success' }
  const diffH = (new Date(at).getTime() - Date.now()) / 36e5
  if (diffH < 0) return { label: 'متأخر', tone: 'danger' }
  if (diffH < 24) return { label: 'اليوم', tone: 'warning' }
  if (diffH < 48) return { label: 'غداً', tone: 'warning' }
  const days = Math.ceil(diffH / 24)
  return { label: `${days} يوم`, tone: 'muted' }
}

function contractStatusPill(status: string): { label: string; tone: BadgeTone } {
  const map: Record<string, { label: string; tone: BadgeTone }> = {
    active: { label: 'نشط', tone: 'steel' },
    approved: { label: 'معتمد', tone: 'success' },
    pending: { label: 'معلّق', tone: 'warning' },
    draft: { label: 'مسودة', tone: 'muted' },
    completed: { label: 'مكتمل', tone: 'muted' },
    cancelled: { label: 'ملغى', tone: 'danger' },
  }
  return map[status] || { label: status, tone: 'muted' }
}

function MiniSpark({ values, tone = 'brand' }: { values: number[]; tone?: string }) {
  const w = 72
  const h = 28
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
      <polyline
        points={points}
        className="db-spark__line"
        fill="none"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
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

type RevenueRange = 'week' | 'month' | 'year'

function ChartTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean
  payload?: { value?: number }[]
  label?: string
}) {
  if (!active || !payload?.length) return null
  return (
    <div className="db-tooltip">
      <span>{label}</span>
      <strong>{formatMoney(Number(payload[0]?.value ?? 0))}</strong>
    </div>
  )
}

function CardMenuButton({ label }: { label: string }) {
  return (
    <button type="button" className="db-card-menu" aria-label={label}>
      <IconMore size={16} />
    </button>
  )
}

export function DashboardPage() {
  const { user } = useAuth()
  const { theme } = useTheme()
  const palette = chartPalette[theme]
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [revenueRange, setRevenueRange] = useState<RevenueRange>('week')

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
    const dailyBase = activeRates || Math.round((data.kpis.expected_revenue || 0) / 7)

    if (revenueRange === 'week') {
      return Array.from({ length: 7 }, (_, i) => {
        const day = new Date(today)
        day.setDate(today.getDate() + i)
        const ending = data.recent_contracts.filter(
          (c) => startOfDay(new Date(c.planned_ends_at)).getTime() === day.getTime(),
        )
        const endingRevenue = ending.reduce((s, c) => s + (c.frozen_daily_rate || 0), 0)
        return {
          label: formatDayShort(day.toISOString()),
          value: Math.max(0, Math.round(dailyBase * (0.94 + i * 0.015) - endingRevenue * 0.3)),
        }
      })
    }

    if (revenueRange === 'month') {
      return Array.from({ length: 4 }, (_, i) => {
        const weekBase = dailyBase * 7
        const wave = 0.9 + i * 0.05 + Math.sin(i * 1.1) * 0.06
        return {
          label: `أسبوع ${i + 1}`,
          value: Math.max(0, Math.round(weekBase * wave)),
        }
      })
    }

    const monthNames = [
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ]
    const nowMonth = today.getMonth()
    return Array.from({ length: 12 }, (_, i) => {
      const monthIndex = (nowMonth - 11 + i + 12) % 12
      const monthBase = dailyBase * 30
      const season = 0.82 + Math.sin((i / 12) * Math.PI * 2) * 0.14 + i * 0.012
      return {
        label: monthNames[monthIndex],
        value: Math.max(0, Math.round(monthBase * season)),
      }
    })
  }, [data, revenueRange])

  const revenueTotal = useMemo(
    () => revenueSeries.reduce((sum, row) => sum + row.value, 0),
    [revenueSeries],
  )

  const revenueRangeLabel =
    revenueRange === 'week'
      ? 'أسبوعي · ٧ أيام'
      : revenueRange === 'month'
        ? 'شهري · ٤ أسابيع'
        : 'سنوي · ١٢ شهراً'

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
  const availableNow = data.kpis.available || 0
  const fleetTotal = data.fleet_total || 1

  const kpis = [
    {
      label: 'متاحة الآن',
      value: availableNow,
      kind: 'number' as const,
      badge: `${formatPercent(Math.round((availableNow / fleetTotal) * 100))} من الأسطول`,
      badgeTone: 'success' as BadgeTone,
      icon: <IconCar size={16} />,
      tone: 'ok',
      spark: sparkFromValue(availableNow || 1, 1),
      href: '/vehicles',
    },
    {
      label: 'عقود نشطة',
      value: data.kpis.rented || 0,
      kind: 'number' as const,
      badge: 'مؤجّرة حالياً',
      badgeTone: 'steel' as BadgeTone,
      icon: <IconClipboard size={16} />,
      tone: 'steel',
      spark: sparkFromValue(data.kpis.rented || 1, 2),
      href: '/bookings',
    },
    {
      label: 'ترجع اليوم',
      value: data.kpis.returning_today || 0,
      kind: 'number' as const,
      badge: 'تحتاج استلام',
      badgeTone: 'warning' as BadgeTone,
      icon: <IconReturn size={16} />,
      tone: 'warn',
      spark: sparkFromValue(data.kpis.returning_today || 1, 3),
      href: '/calendar',
    },
    {
      label: 'إيراد متوقع',
      value: data.kpis.expected_revenue || 0,
      kind: 'money' as const,
      badge: 'أفق التشغيل',
      badgeTone: 'brand' as BadgeTone,
      icon: <IconTrend size={16} />,
      tone: 'brand',
      spark: revenueSpark.length ? revenueSpark : sparkFromValue(data.kpis.expected_revenue || 1, 4),
      href: '/reports',
    },
  ]

  return (
    <div className="db">
      <motion.header
        className="db-hero"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="db-hero__copy">
          <p className="db-hero__eyebrow">لوحة التشغيل · RPM</p>
          <h1>مرحباً، {firstName}</h1>
          <p className="db-hero__sub">
            لديك <strong className="tabular-nums">{formatNumber(openTasks)}</strong> مهام تحتاج متابعة
            و <strong className="tabular-nums">{formatNumber(data.kpis.returning_today || 0)}</strong>{' '}
            مركبة ترجع اليوم.
          </p>
        </div>
      </motion.header>

      <section className="db-kpis" aria-label="مؤشرات سريعة">
        {kpis.map((k, i) => (
          <motion.div
            key={k.label}
            className="db-kpi-shell"
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.04 * i, duration: 0.3 }}
          >
            <Link to={k.href} className={`db-kpi tone-${k.tone}`}>
              <div className="db-kpi__top">
                <span className="db-kpi__label">{k.label}</span>
                <span className="db-kpi__ico" aria-hidden>
                  {k.icon}
                </span>
              </div>
              <div className="db-kpi__value">
                <AnimatedNumber value={k.value} kind={k.kind} />
              </div>
              <div className="db-kpi__foot">
                <Badge tone={k.badgeTone} dot>
                  {k.badge}
                </Badge>
                <MiniSpark values={k.spark} tone={k.tone} />
              </div>
            </Link>
          </motion.div>
        ))}
      </section>

      <section className="db-bento">
        <motion.div
          className="db-span-5"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1, duration: 0.35 }}
        >
          <Card>
            <CardHeader
              title="صحة الإشغال"
              description="قراءة لحظية للأسطول النشط"
              action={
                <>
                  <Link to="/vehicles">الأسطول</Link>
                  <CardMenuButton label="خيارات الإشغال" />
                </>
              }
            />
            <CardContent>
              <div className="db-gauge">
                <div className="db-gauge__ring" aria-hidden>
                  <svg viewBox="0 0 140 140">
                    <circle cx="70" cy="70" r="54" className="track" />
                    <circle className="dots" cx="70" cy="70" r="62" strokeDasharray="2 8" />
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
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          className="db-span-7"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.14, duration: 0.35 }}
        >
          <Card>
            <CardHeader
              title="عمليات قادمة"
              description="ما يحتاج قراراً أولاً"
              action={
                <>
                  <Link to="/bookings">العقود</Link>
                  <CardMenuButton label="خيارات العمليات" />
                </>
              }
            />
            <CardContent flush>
              <div className="db-table-wrap">
                <table className="db-table">
                  <thead>
                    <tr>
                      <th>المهمة</th>
                      <th>التفاصيل</th>
                      <th>الحالة</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.tasks.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="db-empty-cell">
                          لا تذكيرات معلّقة
                        </td>
                      </tr>
                    ) : (
                      data.tasks.map((task, i) => {
                        const pill = urgencyPill(task.at, task.status)
                        return (
                          <motion.tr
                            key={task.id}
                            initial={{ opacity: 0, x: 8 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: 0.03 * i }}
                          >
                            <td>
                              <div className="db-table__primary">
                                <span className="db-table__ico" aria-hidden>
                                  <IconClipboard size={14} />
                                </span>
                                <strong>{task.title}</strong>
                              </div>
                            </td>
                            <td>
                              <span className="db-table__muted">{task.subtitle}</span>
                            </td>
                            <td>
                              <Badge tone={pill.tone}>{pill.label}</Badge>
                            </td>
                          </motion.tr>
                        )
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          className="db-span-7"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.18, duration: 0.35 }}
        >
          <Card>
            <CardHeader
              title="اتجاه الإيراد"
              description={revenueRangeLabel}
              action={
                <strong className="db-card__figure">
                  <AnimatedNumber
                    value={revenueRange === 'week' ? data.kpis.expected_revenue || 0 : revenueTotal}
                    kind="money"
                  />
                </strong>
              }
            />
            <CardContent dense>
              <div className="db-range" role="tablist" aria-label="نطاق الإيراد">
                {(
                  [
                    { id: 'week', label: 'أسبوعي' },
                    { id: 'month', label: 'شهري' },
                    { id: 'year', label: 'سنوي' },
                  ] as const
                ).map((tab) => (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    aria-selected={revenueRange === tab.id}
                    className={`db-range__btn ${revenueRange === tab.id ? 'is-active' : ''}`}
                    onClick={() => setRevenueRange(tab.id)}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>
              <div className="db-chart">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={revenueSeries} margin={{ top: 6, right: 2, left: 0, bottom: 0 }}>
                    <XAxis
                      dataKey="label"
                      tickLine={false}
                      axisLine={false}
                      tick={{ fill: palette.axis, fontSize: 11 }}
                      interval={revenueRange === 'year' ? 1 : 0}
                      height={28}
                    />
                    <YAxis hide domain={['dataMin', 'dataMax']} />
                    <Tooltip
                      content={<ChartTooltip />}
                      cursor={{ stroke: palette.cursor, strokeWidth: 1 }}
                    />
                    <Area
                      type="monotone"
                      dataKey="value"
                      stroke={palette.line}
                      strokeWidth={2.5}
                      fill={palette.line}
                      fillOpacity={0.08}
                      animationDuration={850}
                      activeDot={{ r: 4, fill: palette.dot, stroke: palette.dotRing, strokeWidth: 2 }}
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          className="db-span-5"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.22, duration: 0.35 }}
        >
          <Card>
            <CardHeader
              title="حالة الأسطول"
              description="توزيع المركبات"
              action={<CardMenuButton label="خيارات الأسطول" />}
            />
            <CardContent>
              <div className="db-fleet">
                <div className="db-fleet__chart">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={donut}
                        dataKey="value"
                        nameKey="name"
                        innerRadius="64%"
                        outerRadius="90%"
                        paddingAngle={4}
                        stroke={palette.slice}
                        strokeWidth={3}
                      >
                        {donut.map((entry) => (
                          <Cell key={entry.key} fill={palette.fleet[entry.key] || palette.fallback} />
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
                      <i style={{ background: palette.fleet[item.key] || palette.fallback }} />
                      <span>{item.name}</span>
                      <b className="tabular-nums">{formatNumber(item.value)}</b>
                    </li>
                  ))}
                </ul>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          className="db-span-7"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.26, duration: 0.35 }}
        >
          <Card>
            <CardHeader
              title="إرجاعات قادمة"
              description="تنبيهات الاستلام"
              action={
                <>
                  <Link to="/calendar">التقويم</Link>
                  <CardMenuButton label="خيارات الإرجاعات" />
                </>
              }
            />
            <CardContent flush>
              <div className="db-table-wrap">
                <table className="db-table">
                  <thead>
                    <tr>
                      <th>المركبة</th>
                      <th>العقد / العميل</th>
                      <th>الحالة</th>
                      <th>الموعد</th>
                    </tr>
                  </thead>
                  <tbody>
                    {returns.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="db-empty-cell">
                          <IconReturn size={18} />
                          لا إرجاعات مجدولة
                        </td>
                      </tr>
                    ) : (
                      returns.map((c) => {
                        const st = contractStatusPill(c.status)
                        const overdue = new Date(c.planned_ends_at).getTime() < Date.now()
                        return (
                          <tr key={c.id}>
                            <td>
                              <strong>
                                {c.vehicle?.brand} {c.vehicle?.model}
                              </strong>
                              {c.vehicle?.plate ? (
                                <span className="db-table__muted tabular-nums">{c.vehicle.plate}</span>
                              ) : null}
                            </td>
                            <td>
                              <span className="db-table__muted">
                                {c.number} · {c.customer?.full_name}
                              </span>
                            </td>
                            <td>
                              <Badge tone={overdue ? 'danger' : st.tone}>
                                {overdue ? 'متأخر' : st.label}
                              </Badge>
                            </td>
                            <td className="db-table__time">
                              <b className={`tabular-nums ${overdue ? 'is-overdue' : ''}`}>
                                {formatTime(c.planned_ends_at)}
                              </b>
                              <span>{formatDayShort(c.planned_ends_at)}</span>
                            </td>
                          </tr>
                        )
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          className="db-span-5"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.3, duration: 0.35 }}
        >
          <Card>
            <CardHeader title="اختصارات" description="وصول سريع" />
            <CardContent dense>
              <div className="db-quick">
                <Link to="/customers">
                  <span className="db-quick__ico">
                    <IconUsers size={18} />
                  </span>
                  <span className="db-quick__label">العملاء</span>
                  <b className="tabular-nums">{formatNumber(data.kpis.customers || 0)}</b>
                </Link>
                <Link to="/maintenance">
                  <span className="db-quick__ico">
                    <IconWrench size={18} />
                  </span>
                  <span className="db-quick__label">الصيانة</span>
                  <b className="tabular-nums">{formatNumber(data.kpis.open_work_orders || 0)}</b>
                </Link>
                <Link to="/vehicles">
                  <span className="db-quick__ico">
                    <IconCar size={18} />
                  </span>
                  <span className="db-quick__label">المتاح</span>
                  <b className="tabular-nums">{formatPercent(100 - data.occupancy_pct)}</b>
                </Link>
              </div>
            </CardContent>
          </Card>
        </motion.div>
      </section>
    </div>
  )
}
