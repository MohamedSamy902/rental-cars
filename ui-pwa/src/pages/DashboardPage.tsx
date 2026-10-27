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
  formatDayShort,
  formatMoney,
  formatNumber,
  formatPercent,
  formatTime,
} from '@/shared/lib/format'
import { AnimatedNumber } from '@/shared/ui/AnimatedNumber'
import { ErrorState, SkeletonBlock } from '@/shared/ui'
import { IconClipboard, IconMore, IconPlus, IconReturn, IconWrench } from '@/shared/ui/Icons'
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
  available: '#047857',
  rented: '#0d9488',
  reserved: '#b45309',
  maintenance: '#b91c1c',
  out_of_service: '#8b95a8',
}

type LaneKey = 'handover' | 'return' | 'approve'

type LaneItem = {
  id: string
  title: string
  subtitle: string
  at: string
  status: string
  href: string
}

function startOfDay(d: Date) {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}

function isSameDay(a: Date, b: Date) {
  return startOfDay(a).getTime() === startOfDay(b).getTime()
}

function classifyTask(title: string): LaneKey {
  if (title.includes('اعتماد') || title.includes('معلّق')) return 'approve'
  if (title.includes('استلام') || title.includes('إرجاع')) return 'return'
  return 'handover'
}

export function DashboardPage() {
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [menuId, setMenuId] = useState<string | null>(null)
  const [lane, setLane] = useState<LaneKey | 'all'>('all')

  useEffect(() => {
    api<Dash>('/dashboard')
      .then(setData)
      .catch((e) => setError(e.message || 'تعذّر تحميل اللوحة'))
      .finally(() => setLoading(false))
  }, [])

  const todayLabel = useMemo(
    () =>
      new Intl.DateTimeFormat('ar-EG', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        numberingSystem: 'latn',
      }).format(new Date()),
    [],
  )

  const lanes = useMemo(() => {
    const empty: Record<LaneKey, LaneItem[]> = {
      handover: [],
      return: [],
      approve: [],
    }
    if (!data) return empty

    for (const task of data.tasks) {
      const key = classifyTask(task.title)
      empty[key].push({
        id: `task-${task.id}`,
        title: task.title,
        subtitle: task.subtitle,
        at: task.at,
        status: task.status,
        href: '/bookings',
      })
    }

    const today = new Date()
    for (const c of data.recent_contracts) {
      if (c.status === 'approved' && isSameDay(new Date(c.starts_at), today)) {
        empty.handover.push({
          id: `start-${c.id}`,
          title: `تسليم ${c.number}`,
          subtitle: `${c.customer?.full_name || 'عميل'} · ${c.vehicle?.brand || ''} ${c.vehicle?.model || ''}`,
          at: c.starts_at,
          status: c.status,
          href: '/bookings',
        })
      }
      if ((c.status === 'active' || c.status === 'approved') && isSameDay(new Date(c.planned_ends_at), today)) {
        empty.return.push({
          id: `end-${c.id}`,
          title: `استلام ${c.number}`,
          subtitle: `${c.customer?.full_name || 'عميل'} · ${c.vehicle?.plate || ''}`,
          at: c.planned_ends_at,
          status: c.status,
          href: '/bookings',
        })
      }
      if (c.status === 'pending') {
        empty.approve.push({
          id: `pend-${c.id}`,
          title: `اعتماد ${c.number}`,
          subtitle: `${c.customer?.full_name || 'عميل'} · ${formatMoney(c.frozen_daily_rate)}/يوم`,
          at: c.starts_at,
          status: c.status,
          href: '/bookings',
        })
      }
    }

    const dedupe = (items: LaneItem[]) => {
      const seen = new Set<string>()
      return items
        .filter((item) => {
          const key = item.title + item.subtitle
          if (seen.has(key)) return false
          seen.add(key)
          return true
        })
        .sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime())
    }

    return {
      handover: dedupe(empty.handover),
      return: dedupe(empty.return),
      approve: dedupe(empty.approve),
    }
  }, [data])

  const activeQueue = useMemo(() => {
    if (lane === 'all') return [...lanes.handover, ...lanes.return, ...lanes.approve]
      .sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime())
    return lanes[lane]
  }, [lanes, lane])

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

  const fleetSegments = useMemo(() => {
    if (!data) return []
    const total = data.fleet_total || 1
    return [
      { key: 'available', label: 'متاح', value: data.fleet_status.available || 0 },
      { key: 'rented', label: 'مؤجّر', value: data.fleet_status.rented || 0 },
      { key: 'reserved', label: 'محجوز', value: data.fleet_status.reserved || 0 },
      { key: 'maintenance', label: 'صيانة', value: data.fleet_status.maintenance || 0 },
    ]
      .filter((s) => s.value > 0)
      .map((s) => ({ ...s, pct: Math.round((s.value / total) * 100) }))
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

  const laneMeta: { key: LaneKey | 'all'; label: string; count: number }[] = [
    { key: 'all', label: 'الكل', count: lanes.handover.length + lanes.return.length + lanes.approve.length },
    { key: 'handover', label: 'تسليم', count: lanes.handover.length },
    { key: 'return', label: 'استلام', count: lanes.return.length },
    { key: 'approve', label: 'اعتماد', count: lanes.approve.length },
  ]

  return (
    <div className="dash">
      {/* فكرة جديدة: لوحة تشغيل اليوم — مش بطل تسويقي ولا صف KPI */}
      <motion.section
        className="ops"
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        aria-label="لوحة تشغيل اليوم"
      >
        <header className="ops__head">
          <div>
            <p className="ops__date">{todayLabel}</p>
            <h1>تشغيل اليوم</h1>
            <p className="ops__lede">
              صفّ المهام حسب التسليم والاستلام والاعتماد — ابدأ من هنا، مش من نسب عامة.
            </p>
          </div>
          <div className="ops__head-actions">
            <Link to="/bookings" className="ops-cta">
              <IconPlus size={16} />
              عقد جديد
            </Link>
            <Link to="/calendar" className="ops-ghost">
              التقويم
            </Link>
          </div>
        </header>

        <div className="ops__pulse" aria-label="نبض الأسطول">
          <div className="ops__pulse-meta">
            <strong>نبض الأسطول</strong>
            <span>
              إشغال {formatPercent(data.occupancy_pct)} · {formatNumber(data.fleet_total)} مركبة · إيراد متوقع{' '}
              {formatMoney(data.kpis.expected_revenue || 0)}
            </span>
          </div>
          <div className="ops__bar" role="img" aria-label="توزيع حالة الأسطول">
            {fleetSegments.map((seg) => (
              <span
                key={seg.key}
                className={`ops__bar-seg is-${seg.key}`}
                style={{ flexGrow: seg.value, flexBasis: 0 }}
                title={`${seg.label}: ${seg.value}`}
              />
            ))}
          </div>
          <ul className="ops__bar-legend">
            {fleetSegments.map((seg) => (
              <li key={seg.key}>
                <i className={`is-${seg.key}`} />
                {seg.label}
                <b className="tabular-nums">{formatNumber(seg.value)}</b>
              </li>
            ))}
            <li>
              <i className="is-wo" />
              أوامر شغل
              <b className="tabular-nums">{formatNumber(data.kpis.open_work_orders || 0)}</b>
            </li>
          </ul>
        </div>

        <div className="ops__board">
          <div className="ops__lanes" role="tablist" aria-label="تصفية مهام اليوم">
            {laneMeta.map((tab) => (
              <button
                key={tab.key}
                type="button"
                role="tab"
                aria-selected={lane === tab.key}
                className={`ops__lane ${lane === tab.key ? 'is-active' : ''}`}
                onClick={() => setLane(tab.key)}
              >
                <span>{tab.label}</span>
                <b className="tabular-nums">{formatNumber(tab.count)}</b>
              </button>
            ))}
          </div>

          <ul className="ops__queue">
            <AnimatePresence mode="popLayout">
              {activeQueue.length === 0 ? (
                <motion.li
                  key="empty"
                  className="ops__empty"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                >
                  لا مهام في هذا المسار الآن — يوم هادئ.
                </motion.li>
              ) : (
                activeQueue.map((item, index) => (
                  <motion.li
                    key={item.id}
                    layout
                    className="ops__item"
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -4 }}
                    transition={{ delay: Math.min(index * 0.03, 0.2) }}
                  >
                    <time dateTime={item.at} className="tabular-nums">
                      {formatTime(item.at)}
                    </time>
                    <div className="ops__item-body">
                      <strong>{item.title}</strong>
                      <span>{item.subtitle}</span>
                    </div>
                    <em className={`ops-status is-${item.status}`}>
                      {statusLabel[item.status] || item.status}
                    </em>
                    <div className="ops-qa">
                      <button
                        type="button"
                        aria-label="إجراءات سريعة"
                        aria-expanded={menuId === item.id}
                        onClick={() => setMenuId(menuId === item.id ? null : item.id)}
                      >
                        <IconMore size={16} />
                      </button>
                      <AnimatePresence>
                        {menuId === item.id ? (
                          <motion.div
                            className="ops-qa__menu"
                            initial={{ opacity: 0, y: 4 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: 2 }}
                          >
                            <Link to={item.href} onClick={() => setMenuId(null)}>
                              <IconClipboard size={14} />
                              فتح العقود
                            </Link>
                            <Link to="/maintenance" onClick={() => setMenuId(null)}>
                              <IconWrench size={14} />
                              الصيانة
                            </Link>
                          </motion.div>
                        ) : null}
                      </AnimatePresence>
                    </div>
                  </motion.li>
                ))
              )}
            </AnimatePresence>
          </ul>
        </div>
      </motion.section>

      <section className="dash-grid">
        <motion.article
          className="dash-panel dash-panel--chart"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1, duration: 0.35 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>توقّع الإيراد</h2>
              <p>سبعة أيام من العقود النشطة</p>
            </div>
            <span className="dash-panel__figure tabular-nums">
              <AnimatedNumber value={data.kpis.expected_revenue || 0} kind="money" />
            </span>
          </header>
          <div className="dash-chart">
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
                  strokeWidth={2.4}
                  fill="url(#revFill)"
                  animationDuration={800}
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </motion.article>

        <motion.article
          className="dash-panel dash-panel--fleet"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.14, duration: 0.35 }}
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
          className="dash-panel dash-panel--returns"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.18, duration: 0.35 }}
        >
          <header className="dash-panel__head">
            <div>
              <h2>مواعيد الإرجاع القادمة</h2>
              <p>خط زمني للاستلام</p>
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
                    <em className={`ops-status is-${c.status}`}>
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
