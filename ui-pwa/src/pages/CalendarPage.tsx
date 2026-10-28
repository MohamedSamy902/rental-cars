import { useEffect, useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { api } from '@/shared/api/client'
import { formatTime } from '@/shared/lib/format'
import { PageHeader, StatusPill, SkeletonBlock, IconChevron } from '@/shared/ui'
import { useIsMobile } from '@/shared/ui/useSheetDrag'
import './CalendarPage.css'

type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  customer?: { full_name: string }
  vehicle?: { brand: string; model: string; plate: string }
}

const statusLabel: Record<string, string> = {
  pending: 'معلّق',
  approved: 'معتمد',
  active: 'نشط',
  completed: 'مكتمل',
}

const WEEKDAYS = ['س', 'ح', 'ن', 'ث', 'ر', 'خ', 'ج'] // سبت → جمعة

const monthFormatter = new Intl.DateTimeFormat('ar-EG', { month: 'long', year: 'numeric', numberingSystem: 'latn' })
const dayFormatter = new Intl.DateTimeFormat('ar-EG', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  numberingSystem: 'latn',
})

function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
}

function toKey(d: Date) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function overlapsDay(c: Contract, day: Date) {
  const start = startOfDay(new Date(c.starts_at))
  const end = startOfDay(new Date(c.planned_ends_at))
  const t = startOfDay(day).getTime()
  return t >= start.getTime() && t <= end.getTime()
}

function shortLabel(c: Contract) {
  const who = c.customer?.full_name?.split(/\s+/)[0] || c.number
  const car = c.vehicle?.model || c.vehicle?.brand || ''
  return car ? `${who} · ${car}` : who
}

function buildMonthCells(month: Date) {
  const first = new Date(month.getFullYear(), month.getMonth(), 1)
  const jsDay = first.getDay()
  const offset = (jsDay + 1) % 7 // Sat=0
  const start = new Date(first)
  start.setDate(first.getDate() - offset)
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    return d
  })
}

export function CalendarPage() {
  const [rows, setRows] = useState<Contract[]>([])
  const [loading, setLoading] = useState(true)
  const [month, setMonth] = useState(() => new Date(new Date().getFullYear(), new Date().getMonth(), 1))
  const [selected, setSelected] = useState(() => startOfDay(new Date()))
  const [focusId, setFocusId] = useState<number | null>(null)
  const [fleetSize, setFleetSize] = useState(0)
  const mobile = useIsMobile()

  useEffect(() => {
    Promise.all([
      api<{ data: Contract[]; meta?: { total?: number } }>('/contracts?per_page=200'),
      api<{ summary?: { total?: number } }>('/vehicles?per_page=1'),
    ])
      .then(([res, fleet]) => {
        setRows(res.data.filter((c) => ['pending', 'approved', 'active'].includes(c.status)))
        setFleetSize(fleet.summary?.total ?? 0)
      })
      .finally(() => setLoading(false))
  }, [])

  const cells = useMemo(() => buildMonthCells(month), [month])
  const todayKey = toKey(new Date())
  const selectedKey = toKey(selected)

  const byDay = useMemo(() => {
    const map = new Map<string, Contract[]>()
    for (const cell of cells) {
      const key = toKey(cell)
      map.set(
        key,
        rows.filter((c) => overlapsDay(c, cell)),
      )
    }
    return map
  }, [cells, rows])

  const selectedEvents = byDay.get(selectedKey) || []
  const orderedEvents = focusId
    ? [...selectedEvents].sort((a, b) => Number(b.id === focusId) - Number(a.id === focusId))
    : selectedEvents

  function shiftMonth(delta: number) {
    setMonth((m) => new Date(m.getFullYear(), m.getMonth() + delta, 1))
  }

  function pickDay(day: Date, contractId?: number) {
    setSelected(startOfDay(day))
    setFocusId(contractId ?? null)
    if (day.getMonth() !== month.getMonth()) {
      setMonth(new Date(day.getFullYear(), day.getMonth(), 1))
    }
  }

  return (
    <div className="cal">
      <PageHeader title="التقويم" subtitle="الحجوزات ظاهرة على الأيام مباشرة — اضغط يوماً لعرض حجوزاته" />

      <div className="cal-toolbar">
        <button type="button" className="cal-nav" aria-label="الشهر السابق" onClick={() => shiftMonth(-1)}>
          <IconChevron size={18} />
        </button>
        <div className="cal-toolbar__title">
          <strong>{monthFormatter.format(month)}</strong>
          <span>{rows.length} حجز في الأفق</span>
        </div>
        <button type="button" className="cal-nav cal-nav--next" aria-label="الشهر التالي" onClick={() => shiftMonth(1)}>
          <IconChevron size={18} />
        </button>
      </div>

      {loading ? <SkeletonBlock rows={6} /> : null}

      {!loading ? (
        <div className="cal-board">
          <div className="cal-weekdays" aria-hidden>
            {WEEKDAYS.map((d) => (
              <span key={d}>{d}</span>
            ))}
          </div>
          <div className="cal-grid" role="grid" aria-label="التقويم الشهري">
            {cells.map((day) => {
              const key = toKey(day)
              const events = byDay.get(key) || []
              const inMonth = day.getMonth() === month.getMonth()
              const extra = Math.max(0, events.length - 2)
              const dots = events.slice(0, 3)
              const uniqueCars = new Set(events.map((e) => e.vehicle?.plate || e.id)).size
              const loadPct = fleetSize ? Math.round((uniqueCars / fleetSize) * 100) : events.length
              const label = `${dayFormatter.format(day)}${events.length ? ` · ${events.length} حجز` : ''}`
              return (
                <div
                  key={key}
                  role="gridcell"
                  className={[
                    'cal-cell',
                    inMonth ? '' : 'is-muted',
                    key === todayKey ? 'is-today' : '',
                    key === selectedKey ? 'is-selected' : '',
                    events.length ? 'has-events' : '',
                  ].join(' ')}
                >
                  <button
                    type="button"
                    className="cal-cell__daybtn"
                    aria-label={label}
                    aria-selected={key === selectedKey}
                    onClick={() => pickDay(day)}
                  >
                    <span className="cal-cell__num tabular-nums">{day.getDate()}</span>
                    {mobile && events.length ? (
                      fleetSize ? (
                        <span className="cal-cell__load" aria-hidden>{loadPct}%</span>
                      ) : (
                        <span className="cal-cell__dots" aria-hidden>
                          {dots.map((e) => (
                            <i key={e.id} className={`cal-dot is-${e.status}`} />
                          ))}
                        </span>
                      )
                    ) : null}
                  </button>

                  {!mobile ? (
                    <div className="cal-cell__events">
                      {events.slice(0, 2).map((e) => (
                        <button
                          key={e.id}
                          type="button"
                          className={`cal-chip is-${e.status} ${focusId === e.id ? 'is-focus' : ''}`}
                          title={`${e.number} · ${e.customer?.full_name || ''} · ${statusLabel[e.status] || e.status}`}
                          onClick={() => pickDay(day, e.id)}
                        >
                          <span className="cal-chip__text">{shortLabel(e)}</span>
                        </button>
                      ))}
                      {extra > 0 ? (
                        <button type="button" className="cal-chip is-more" onClick={() => pickDay(day)}>
                          +{extra}
                        </button>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              )
            })}
          </div>
          <div className="cal-legend" aria-hidden>
            <span><i className="cal-dot is-active" /> نشط</span>
            <span><i className="cal-dot is-approved" /> معتمد</span>
            <span><i className="cal-dot is-pending" /> معلّق</span>
          </div>
        </div>
      ) : null}

      <AnimatePresence mode="wait" initial={false}>
        <motion.section
          key={selectedKey}
          className="cal-day"
          aria-live="polite"
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -4 }}
          transition={{ duration: 0.18 }}
        >
          <header className="cal-day__head">
            <h2>{dayFormatter.format(selected)}</h2>
            <span className="cal-day__count tabular-nums">{selectedEvents.length} حجز</span>
          </header>

          {!loading && selectedEvents.length === 0 ? (
            <p className="cal-day__empty">لا حجوزات في هذا اليوم</p>
          ) : null}

          <div className="cal-day__list">
            {orderedEvents.map((b) => (
              <button
                key={b.id}
                type="button"
                className={`cal-event is-${b.status} ${focusId === b.id ? 'is-focus' : ''}`}
                aria-pressed={focusId === b.id}
                onClick={() => setFocusId(b.id)}
              >
                <div className="cal-event__top">
                  <strong className="tabular-nums">{b.number}</strong>
                  <StatusPill status={b.status} label={statusLabel[b.status] || b.status} />
                </div>
                <p className="cal-event__who">{b.customer?.full_name}</p>
                <dl className="cal-event__meta">
                  <div>
                    <dt>المركبة</dt>
                    <dd>{b.vehicle?.brand} {b.vehicle?.model} · {b.vehicle?.plate}</dd>
                  </div>
                  <div>
                    <dt>الاستلام / التسليم</dt>
                    <dd className="tabular-nums">{formatTime(b.starts_at)} – {formatTime(b.planned_ends_at)}</dd>
                  </div>
                </dl>
              </button>
            ))}
          </div>
        </motion.section>
      </AnimatePresence>
    </div>
  )
}
