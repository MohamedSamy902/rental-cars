import { useEffect, useMemo, useState } from 'react'
import { api } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, StatusPill, SkeletonBlock, EmptyState, IconChevron } from '@/shared/ui'
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

const monthFormatter = new Intl.DateTimeFormat('ar-EG', { month: 'long', year: 'numeric' })
const dayFormatter = new Intl.DateTimeFormat('ar-EG', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
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

function buildMonthCells(month: Date) {
  const first = new Date(month.getFullYear(), month.getMonth(), 1)
  // JS: 0=Sun … 6=Sat. We want Saturday-first → shift
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

  useEffect(() => {
    api<{ data: Contract[] }>('/contracts?per_page=100')
      .then((res) => setRows(res.data.filter((c) => ['pending', 'approved', 'active'].includes(c.status))))
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

  function shiftMonth(delta: number) {
    setMonth((m) => new Date(m.getFullYear(), m.getMonth() + delta, 1))
  }

  return (
    <div className="cal">
      <PageHeader title="التقويم" subtitle="عرض شهري للحجوزات المعتمدة والنشطة والمعلّقة" />

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
              return (
                <button
                  key={key}
                  type="button"
                  role="gridcell"
                  className={[
                    'cal-cell',
                    inMonth ? '' : 'is-muted',
                    key === todayKey ? 'is-today' : '',
                    key === selectedKey ? 'is-selected' : '',
                  ].join(' ')}
                  aria-label={`${dayFormatter.format(day)}${events.length ? ` · ${events.length} حجز` : ''}`}
                  aria-selected={key === selectedKey}
                  onClick={() => {
                    setSelected(startOfDay(day))
                    if (!inMonth) setMonth(new Date(day.getFullYear(), day.getMonth(), 1))
                  }}
                >
                  <span className="cal-cell__num">{day.getDate()}</span>
                  <span className="cal-cell__dots" aria-hidden>
                    {events.slice(0, 3).map((e) => (
                      <i key={e.id} className={`cal-dot is-${e.status}`} />
                    ))}
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      ) : null}

      <div className="cal-legend" aria-hidden>
        <span><i className="cal-dot is-active" /> نشط</span>
        <span><i className="cal-dot is-approved" /> معتمد</span>
        <span><i className="cal-dot is-pending" /> معلّق</span>
      </div>

      <section className="cal-day" aria-live="polite">
        <div className="cal-day__head">
          <h2>{dayFormatter.format(selected)}</h2>
          <span>{selectedEvents.length} حجز</span>
        </div>
        {!loading && selectedEvents.length === 0 ? <EmptyState title="لا حجوزات في هذا اليوم" /> : null}
        {selectedEvents.map((b) => (
          <article key={b.id} className="cal-event">
            <div className="cal-event__top">
              <strong>{b.number}</strong>
              <StatusPill status={b.status} label={statusLabel[b.status] || b.status} />
            </div>
            <p>{b.customer?.full_name}</p>
            <p className="muted">{b.vehicle?.brand} {b.vehicle?.model} · {b.vehicle?.plate}</p>
            <p className="muted">{formatDateTime(b.starts_at)} → {formatDateTime(b.planned_ends_at)}</p>
          </article>
        ))}
      </section>
    </div>
  )
}
