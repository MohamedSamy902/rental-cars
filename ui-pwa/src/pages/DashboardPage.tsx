import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
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
import { Button, DropdownMenu, ErrorState, Sheet, SkeletonBlock, useToast, type MenuItem } from '@/shared/ui'
import {
  IconCalendar,
  IconCar,
  IconCash,
  IconClipboard,
  IconMore,
  IconPlus,
  IconRefresh,
  IconReturn,
  IconStore,
  IconTrend,
  IconUsers,
  IconWallet,
  IconWrench,
} from '@/shared/ui/Icons'
import { useNewContract } from '@/shared/contracts/newContractContext'
import './DashboardPage.css'

type ProfitCompare = {
  current_month: string
  previous_month: string
  fleet: {
    current: { revenue: number; maintenance: number; expenses: number; operating_profit: number }
    previous: { revenue: number; maintenance: number; expenses: number; operating_profit: number }
    delta: number
    delta_pct: number
  }
  vehicles: Array<{
    vehicle_id: number
    label: string
    current: { operating_profit: number }
    previous: { operating_profit: number }
    delta: number
    delta_pct: number
  }>
}

type FleetPrepItem = {
  id: number
  kind: string
  number?: string
  title?: string
  customer?: string | null
  vehicle?: { id: number; label: string; plate: string } | null
  at?: string | null
  href?: string | null
}

type DailyCashEntry = {
  id: string
  source: 'payment' | 'expense' | string
  direction: 'in' | 'out'
  amount: number
  title: string
  subtitle: string
  method?: string | null
  at?: string | null
  href: string
}

type DailyCash = {
  date: string
  totals: { in: number; out: number; net: number; count: number }
  entries: DailyCashEntry[]
}

type FleetPrepDay = {
  date: string
  label: string
  is_today: boolean
  is_tomorrow: boolean
  counts: { pickups: number; returns: number; maintenance: number; total: number }
  groups: {
    pickups: FleetPrepItem[]
    returns: FleetPrepItem[]
    maintenance: FleetPrepItem[]
  }
}

type ImportantTask = {
  id: string
  rule: string
  severity: 'critical' | 'warning' | 'info'
  title: string
  subtitle: string
  href: string
  due_on: string | null
}

type Dash = {
  occupancy_pct: number
  fleet_total: number
  fleet_status: Record<string, number>
  profitability_compare?: ProfitCompare
  kpis: Record<string, number>
  tasks: { id: number; title: string; subtitle: string; status: string; at: string }[]
  important_tasks?: ImportantTask[]
  fleet_prep?: {
    horizon_days: number
    days: FleetPrepDay[]
    totals: { pickups: number; returns: number; maintenance: number; total: number }
  }
  daily_cash?: DailyCash | null
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

function severityPill(severity: ImportantTask['severity'], dueOn: string | null): { label: string; tone: BadgeTone } {
  if (severity === 'critical') {
    if (dueOn && startOfDay(new Date(dueOn)).getTime() < startOfDay(new Date()).getTime()) {
      return { label: 'متأخر', tone: 'danger' }
    }
    return { label: 'عاجل', tone: 'danger' }
  }
  if (severity === 'warning') {
    if (!dueOn) return { label: 'يحتاج متابعة', tone: 'warning' }
    const diffH = (startOfDay(new Date(dueOn)).getTime() - startOfDay(new Date()).getTime()) / 36e5
    if (diffH <= 0) return { label: 'اليوم', tone: 'warning' }
    if (diffH < 48) return { label: 'غداً', tone: 'warning' }
    return { label: `${Math.ceil(diffH / 24)} يوم`, tone: 'warning' }
  }
  return { label: 'متابعة', tone: 'muted' }
}

function taskIcon(rule: string) {
  if (rule.startsWith('work_order') || rule.startsWith('maintenance')) return <IconWrench size={14} />
  if (rule.startsWith('license') || rule.startsWith('sim') || rule.startsWith('owner_agreement')) {
    return <IconCar size={14} />
  }
  if (rule.startsWith('violation')) return <IconClipboard size={14} />
  if (rule.startsWith('stock')) return <IconStore size={14} />
  if (rule.includes('return')) return <IconReturn size={14} />
  return <IconClipboard size={14} />
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

function CardMenu({ label, items }: { label: string; items: MenuItem[] }) {
  return <DropdownMenu label={label} items={items} triggerClassName="db-card-menu" trigger={<IconMore size={16} />} />
}

export function DashboardPage() {
  const { user } = useAuth()
  const { theme } = useTheme()
  const palette = chartPalette[theme]
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [revenueRange, setRevenueRange] = useState<RevenueRange>('week')
  const [refreshing, setRefreshing] = useState(false)
  const [momOpen, setMomOpen] = useState(false)
  const [mom, setMom] = useState<ProfitCompare | null>(null)
  const navigate = useNavigate()
  const toast = useToast()
  const { openNewContract } = useNewContract()

  const openMom = useCallback(() => {
    const embedded = data?.profitability_compare
    if (embedded) {
      setMom(embedded)
      setMomOpen(true)
      return
    }
    api<{ data: ProfitCompare }>('/reports/profitability-compare')
      .then((r) => {
        setMom(r.data)
        setMomOpen(true)
      })
      .catch((e) => toast.error(e.message || 'تعذّر تحميل مقارنة الربحية'))
  }, [data?.profitability_compare, toast])

  useEffect(() => {
    api<Dash>('/dashboard')
      .then(setData)
      .catch((e) => setError(e.message || 'تعذّر تحميل اللوحة'))
      .finally(() => setLoading(false))
  }, [])

  const refresh = useCallback(() => {
    setRefreshing(true)
    api<Dash>('/dashboard')
      .then((next) => {
        setData(next)
        toast.success('تم تحديث بيانات اللوحة')
      })
      .catch((e) => toast.error(e.message || 'تعذّر تحديث اللوحة'))
      .finally(() => setRefreshing(false))
  }, [toast])

  const menus = useMemo(() => {
    const go = (id: string, label: string, to: string, icon: ReactNode): MenuItem => ({
      id,
      label,
      icon,
      onSelect: () => navigate(to),
    })
    const tail: MenuItem[] = [
      { id: 'sep', separator: true },
      { id: 'refresh', label: 'تحديث البيانات', icon: <IconRefresh size={16} />, onSelect: refresh },
    ]
    return {
      occupancy: [
        go('fleet', 'عرض الأسطول', '/vehicles', <IconCar size={16} />),
        go('maintenance', 'الصيانة', '/maintenance', <IconWrench size={16} />),
        ...tail,
      ],
      operations: [
        go('bookings', 'كل العقود', '/bookings', <IconClipboard size={16} />),
        { id: 'new', label: 'عقد جديد', icon: <IconPlus size={16} />, onSelect: openNewContract },
        ...tail,
      ],
      fleet: [
        go('fleet', 'عرض الأسطول', '/vehicles', <IconCar size={16} />),
        go('showroom', 'وضع المعرض', '/showroom', <IconStore size={16} />),
        go('maintenance', 'الصيانة', '/maintenance', <IconWrench size={16} />),
        ...tail,
      ],
      returns: [
        go('calendar', 'فتح التقويم', '/calendar', <IconCalendar size={16} />),
        go('bookings', 'كل العقود', '/bookings', <IconClipboard size={16} />),
        ...tail,
      ],
    }
  }, [navigate, refresh, openNewContract])

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

  const displayName = (user?.name || '').trim() || 'مستخدم'
  const openTasks = (data.important_tasks ?? data.tasks ?? []).length
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
          <h1>مرحباً، {displayName}</h1>
          <p className="db-hero__sub">
            لديك <strong className="tabular-nums">{formatNumber(openTasks)}</strong> مهام تحتاج متابعة
            و <strong className="tabular-nums">{formatNumber(data.kpis.returning_today || 0)}</strong>{' '}
            عربية ترجع اليوم.
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

      <section className={`db-bento db-bento--charts ${refreshing ? 'is-refreshing' : ''}`.trim()} aria-label="الرسوم البيانية" aria-busy={refreshing}>
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
              description="توزيع العربيات"
              action={<CardMenu label="خيارات الأسطول" items={menus.fleet} />}
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
                    <span>عربية</span>
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

      </section>

      {data.fleet_prep ? (
        <section className="db-prep" aria-label="تجهيز العربيات اليومي">
          <header className="db-prep__head">
            <div>
              <h2>تجهيز العربيات اليومي</h2>
              <p>جدول زمني لليوم وغداً والأيام التالية</p>
            </div>
            <div className="db-prep__summary" aria-label="ملخص الحركات">
              <span className="db-prep__pill db-prep__pill--pickup">
                تسليم <b className="tabular-nums">{formatNumber(data.fleet_prep.totals.pickups)}</b>
              </span>
              <span className="db-prep__pill db-prep__pill--return">
                استلام <b className="tabular-nums">{formatNumber(data.fleet_prep.totals.returns)}</b>
              </span>
              <span className="db-prep__pill db-prep__pill--maint">
                صيانة <b className="tabular-nums">{formatNumber(data.fleet_prep.totals.maintenance)}</b>
              </span>
              <Link to="/calendar" className="db-prep__link">التقويم</Link>
            </div>
          </header>

          <div className="db-prep__timeline" role="list">
            {data.fleet_prep.days.map((day) => {
              const dayTitle = day.is_today ? 'اليوم' : day.is_tomorrow ? 'غداً' : day.label
              const               lanes: Array<{
                key: 'pickups' | 'returns' | 'maintenance'
                verb: string
                href: (item: FleetPrepItem) => string
              }> = [
                { key: 'pickups', verb: 'تسليم', href: (item) => `/contracts/${item.id}` },
                { key: 'returns', verb: 'استلام', href: (item) => `/contracts/${item.id}` },
                {
                  key: 'maintenance',
                  verb: 'صيانة',
                  href: (item) => item.href || (item.vehicle?.id ? `/vehicles/${item.vehicle.id}` : '/maintenance'),
                },
              ]
              return (
                <article
                  key={day.date}
                  role="listitem"
                  className={`db-prep-col ${day.is_today ? 'is-today' : ''} ${day.is_tomorrow ? 'is-tomorrow' : ''}`.trim()}
                >
                  <header className="db-prep-col__head">
                    <div className="db-prep-col__title">
                      <strong>{dayTitle}</strong>
                      <time dateTime={day.date}>{day.date}</time>
                    </div>
                    <div className="db-prep-col__badges">
                      <span className="db-prep-chip db-prep-chip--pickup" title="تسليم">
                        {formatNumber(day.counts.pickups)}
                      </span>
                      <span className="db-prep-chip db-prep-chip--return" title="استلام">
                        {formatNumber(day.counts.returns)}
                      </span>
                      <span className="db-prep-chip db-prep-chip--maint" title="صيانة">
                        {formatNumber(day.counts.maintenance)}
                      </span>
                    </div>
                  </header>

                  {day.counts.total === 0 ? (
                    <p className="db-prep-col__empty">لا حركات</p>
                  ) : (
                    <div className="db-prep-col__scroll">
                      {lanes.map((lane) => {
                        const items = day.groups[lane.key]
                        if (items.length === 0) return null
                        return (
                          <div key={lane.key} className={`db-prep-block db-prep-block--${lane.key}`}>
                            <div className="db-prep-block__label">
                              <Badge
                                tone={lane.key === 'pickups' ? 'success' : lane.key === 'returns' ? 'warning' : 'steel'}
                                dot={false}
                              >
                                {lane.verb}
                              </Badge>
                              <span className="tabular-nums">{formatNumber(items.length)}</span>
                            </div>
                            <ul className="db-prep-block__list">
                              {items.map((item) => {
                                const plate = item.vehicle?.plate || item.number || `#${item.id}`
                                const who = item.customer || item.vehicle?.label || item.title || '—'
                                const when = item.at ? formatTime(item.at) : null
                                return (
                                  <li key={`${lane.key}-${item.id}`}>
                                    <Link to={lane.href(item)} className="db-prep-row">
                                      <span className="db-prep-row__rail" aria-hidden />
                                      <span className="db-prep-row__body">
                                        <span className="db-prep-row__plate">{plate}</span>
                                        <span className="db-prep-row__who">{who}</span>
                                      </span>
                                      {when ? <span className="db-prep-row__when tabular-nums">{when}</span> : null}
                                    </Link>
                                  </li>
                                )
                              })}
                            </ul>
                          </div>
                        )
                      })}
                    </div>
                  )}
                </article>
              )
            })}
          </div>
        </section>
      ) : null}

      {data.daily_cash ? (
        <section className="db-cash" aria-label="حركة الفلوس اليوم">
          <header className="db-cash__head">
            <div>
              <h2>حركة الفلوس اليوم</h2>
              <p>كل المبالغ التي دخلت أو خرجت اليوم</p>
            </div>
            <div className="db-cash__summary" aria-label="ملخص اليوم">
              <span className="db-cash__pill db-cash__pill--in">
                دخل <b className="tabular-nums">{formatMoney(data.daily_cash.totals.in)}</b>
              </span>
              <span className="db-cash__pill db-cash__pill--out">
                خرج <b className="tabular-nums">{formatMoney(data.daily_cash.totals.out)}</b>
              </span>
              <span className={`db-cash__pill db-cash__pill--net ${data.daily_cash.totals.net >= 0 ? 'is-pos' : 'is-neg'}`}>
                صافي <b className="tabular-nums">{formatMoney(data.daily_cash.totals.net)}</b>
              </span>
              <Link to="/accounts" className="db-cash__link">الحسابات</Link>
              <Link to="/expenses" className="db-cash__link">المصروفات</Link>
            </div>
          </header>

          {data.daily_cash.entries.length === 0 ? (
            <div className="db-cash__empty" role="status">
              <IconWallet size={22} />
              <strong>لا حركات مالية اليوم</strong>
              <span>المقبوضات والمدفوعات والمصروفات المسجّلة اليوم تظهر هنا</span>
            </div>
          ) : (
            <ul className="db-cash__list">
              {data.daily_cash.entries.map((row, i) => (
                <motion.li
                  key={row.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.02 * i, type: 'spring', stiffness: 380, damping: 28 }}
                >
                  <Link to={row.href} className={`db-cash-row db-cash-row--${row.direction}`}>
                    <span className="db-cash-row__ico" aria-hidden>
                      {row.direction === 'in' ? <IconCash size={16} /> : <IconWallet size={16} />}
                    </span>
                    <span className="db-cash-row__body">
                      <strong>{row.title}</strong>
                      <span className="db-cash-row__sub">{row.subtitle}</span>
                    </span>
                    <span className="db-cash-row__meta">
                      <b className={`db-cash-row__amt tabular-nums db-cash-row__amt--${row.direction}`}>
                        {row.direction === 'in' ? '+' : '−'}
                        {formatMoney(row.amount)}
                      </b>
                      {row.at ? (
                        <time className="tabular-nums" dateTime={row.at}>
                          {formatTime(row.at)}
                        </time>
                      ) : null}
                    </span>
                  </Link>
                </motion.li>
              ))}
            </ul>
          )}
        </section>
      ) : null}

      <section className={`db-bento ${refreshing ? 'is-refreshing' : ''}`.trim()} aria-busy={refreshing}>
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
                  <button type="button" className="db-text-action" onClick={openMom}>مقارنة ربحية شهر بشهر</button>
                  <Link to="/vehicles">الأسطول</Link>
                  <CardMenu label="خيارات الإشغال" items={menus.occupancy} />
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
          <Card className="db-tasks-card">
            <CardHeader
              title="مهام مهمة"
              description="ما يجب تنفيذه حسب صلاحياتك"
              action={
                <>
                  <span className="db-tasks__count tabular-nums">
                    {(data.important_tasks ?? []).length}
                  </span>
                  <CardMenu label="خيارات المهام" items={menus.operations} />
                </>
              }
            />
            <CardContent flush>
              {(data.important_tasks ?? []).length === 0 ? (
                <div className="db-tasks__empty" role="status">
                  <IconClipboard size={22} />
                  <strong>لا مهام عاجلة حالياً</strong>
                  <span>كل شيء ضمن الصلاحيات المتاحة تحت السيطرة</span>
                </div>
              ) : (
                <ul className="db-tasks">
                  {(data.important_tasks ?? []).map((task, i) => {
                    const pill = severityPill(task.severity, task.due_on)
                    return (
                      <motion.li
                        key={task.id}
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.03 * i, type: 'spring', stiffness: 380, damping: 28 }}
                      >
                        <button
                          type="button"
                          className={`db-task db-task--${task.severity}`}
                          onClick={() => navigate(task.href)}
                        >
                          <span className="db-task__rail" aria-hidden />
                          <span className="db-task__ico" aria-hidden>
                            {taskIcon(task.rule)}
                          </span>
                          <span className="db-task__body">
                            <strong>{task.title}</strong>
                            <span className="db-task__sub">{task.subtitle}</span>
                          </span>
                          <span className="db-task__meta">
                            <Badge tone={pill.tone}>{pill.label}</Badge>
                            {task.due_on ? (
                              <time className="db-task__due tabular-nums" dateTime={task.due_on}>
                                {formatDayShort(task.due_on)}
                              </time>
                            ) : null}
                          </span>
                        </button>
                      </motion.li>
                    )
                  })}
                </ul>
              )}
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
                  <CardMenu label="خيارات الإرجاعات" items={menus.returns} />
                </>
              }
            />
            <CardContent flush>
              <div className="db-table-wrap">
                <table className="db-table">
                  <thead>
                    <tr>
                      <th>العربية</th>
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
                <button type="button" className="db-quick__btn" onClick={openMom}>
                  <span className="db-quick__ico">
                    <IconTrend size={18} />
                  </span>
                  <span className="db-quick__label">ربحية شهر بشهر</span>
                  <b className="tabular-nums">
                    {data.profitability_compare
                      ? `${data.profitability_compare.fleet.delta_pct >= 0 ? '+' : ''}${formatNumber(data.profitability_compare.fleet.delta_pct)}%`
                      : '—'}
                  </b>
                </button>
              </div>
            </CardContent>
          </Card>
        </motion.div>
      </section>

      <Sheet open={momOpen} title="مقارنة ربحية شهر بشهر" onClose={() => setMomOpen(false)}>
        {mom ? (
          <div className="rpm-stack">
            <p className="muted">{mom.current_month} مقابل {mom.previous_month}</p>
            <div className="db-mom-grid">
              <div>
                <span>ربح التشغيل الحالي</span>
                <strong className="tabular-nums">{formatMoney(mom.fleet.current.operating_profit)}</strong>
              </div>
              <div>
                <span>الشهر السابق</span>
                <strong className="tabular-nums">{formatMoney(mom.fleet.previous.operating_profit)}</strong>
              </div>
              <div>
                <span>الفرق</span>
                <strong className="tabular-nums">{formatMoney(mom.fleet.delta)}</strong>
              </div>
              <div>
                <span>نسبة التغيّر</span>
                <strong className="tabular-nums">{formatNumber(mom.fleet.delta_pct)}%</strong>
              </div>
            </div>
            <ul className="db-mom-vehicles">
              {mom.vehicles.slice(0, 12).map((v) => (
                <li key={v.vehicle_id}>
                  <span>{v.label}</span>
                  <b className="tabular-nums">{formatMoney(v.current.operating_profit)}</b>
                  <em className="tabular-nums">{formatNumber(v.delta_pct)}%</em>
                </li>
              ))}
            </ul>
            <Button variant="secondary" onClick={() => navigate('/reports')}>فتح التقارير</Button>
          </div>
        ) : (
          <SkeletonBlock rows={3} />
        )}
      </Sheet>
    </div>
  )
}
