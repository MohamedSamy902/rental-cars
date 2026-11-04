import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import {
  PageHeader, EmptyState, SkeletonBlock, ErrorState, Button,
  SelectField, DateField, FilterBar,
} from '@/shared/ui'
import './pages.css'
import './AuditLogsPage.css'

type Log = {
  id: number
  action: string
  summary?: string
  record_type?: string
  record_id?: number
  user_id?: number
  user_name?: string | null
  user_role_title?: string | null
  user_phrase?: string | null
  created_at?: string
}

type FilterUser = { id: number; name: string; role_title?: string | null; label?: string }
type FilterAction = { value: string; label: string }

type AuditMeta = {
  users: FilterUser[]
  actions: FilterAction[]
}

const DATE_CHIPS = [
  { id: 'all', label: 'كل التواريخ' },
  { id: 'today', label: 'اليوم' },
  { id: 'week', label: 'آخر ٧ أيام' },
  { id: 'month', label: 'هذا الشهر' },
]

function toYmd(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function rangeForChip(chip: string): { from: string; to: string } {
  const today = new Date()
  const to = toYmd(today)
  if (chip === 'today') return { from: to, to }
  if (chip === 'week') {
    const from = new Date(today)
    from.setDate(from.getDate() - 6)
    return { from: toYmd(from), to }
  }
  if (chip === 'month') {
    const from = new Date(today.getFullYear(), today.getMonth(), 1)
    return { from: toYmd(from), to }
  }
  return { from: '', to: '' }
}

export function AuditLogsPage() {
  const [rows, setRows] = useState<Log[]>([])
  const [meta, setMeta] = useState<AuditMeta>({ users: [], actions: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [userId, setUserId] = useState('')
  const [action, setAction] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [dateChip, setDateChip] = useState('all')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = new URLSearchParams()
      params.set('limit', '80')
      if (userId) params.set('user_id', userId)
      if (action) params.set('action', action)
      if (dateFrom) params.set('date_from', dateFrom)
      if (dateTo) params.set('date_to', dateTo)
      const res = await api<{ data: Log[]; meta?: AuditMeta }>(`/audit-logs?${params.toString()}`)
      setRows(res.data)
      if (res.meta) {
        setMeta({
          users: res.meta.users ?? [],
          actions: res.meta.actions ?? [],
        })
      }
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }, [userId, action, dateFrom, dateTo])

  useEffect(() => { void load() }, [load])

  const userOptions = useMemo(
    () => [
      { value: '', label: 'كل المستخدمين' },
      ...meta.users.map((u) => ({
        value: String(u.id),
        label: u.label || (u.role_title ? `${u.role_title} — ${u.name}` : u.name),
      })),
    ],
    [meta.users],
  )

  const actionOptions = useMemo(
    () => [
      { value: '', label: 'كل الأحداث' },
      ...meta.actions.map((a) => ({ value: a.value, label: a.label })),
    ],
    [meta.actions],
  )

  const hasActiveFilters = Boolean(userId || action || dateFrom || dateTo)

  function onDateChip(id: string) {
    setDateChip(id)
    const range = rangeForChip(id)
    setDateFrom(range.from)
    setDateTo(range.to)
  }

  function onDateFromChange(value: string) {
    setDateFrom(value)
    setDateChip('all')
  }

  function onDateToChange(value: string) {
    setDateTo(value)
    setDateChip('all')
  }

  function clearFilters() {
    setUserId('')
    setAction('')
    setDateFrom('')
    setDateTo('')
    setDateChip('all')
  }

  return (
    <div className="rpm-stack audit-page">
      <PageHeader
        title="سجل التعديلات"
        subtitle="حركات واضحة بلغة عربية بسيطة — صفِّ حسب المستخدم أو نوع الحدث أو التاريخ"
        action={<Button variant="secondary" onClick={() => void load()}>تحديث</Button>}
      />

      <section className="audit-filters" aria-label="فلاتر سجل التعديلات">
        <div className="audit-filters__fields">
          <SelectField
            label="المستخدم"
            searchable
            searchPlaceholder="بحث عن مستخدم…"
            options={userOptions}
            value={userId}
            onChange={(e) => setUserId(e.target.value)}
            placeholder="كل المستخدمين"
          />
          <SelectField
            label="نوع الحدث"
            searchable
            searchPlaceholder="بحث عن حدث…"
            options={actionOptions}
            value={action}
            onChange={(e) => setAction(e.target.value)}
            placeholder="كل الأحداث"
          />
          <DateField
            label="من تاريخ"
            value={dateFrom}
            onChange={(e) => onDateFromChange(e.target.value)}
          />
          <DateField
            label="إلى تاريخ"
            value={dateTo}
            onChange={(e) => onDateToChange(e.target.value)}
          />
        </div>

        <FilterBar
          hideSearch
          search=""
          onSearchChange={() => {}}
          chips={DATE_CHIPS}
          activeChip={dateChip}
          onChipChange={onDateChip}
        />

        {hasActiveFilters ? (
          <div className="audit-filters__actions">
            <Button variant="ghost" onClick={clearFilters}>مسح الفلاتر</Button>
          </div>
        ) : null}
      </section>

      {loading ? <SkeletonBlock rows={6} /> : null}
      {!loading && error ? <ErrorState title="تعذّر التحميل" description={error} /> : null}
      {!loading && !error && rows.length === 0 ? (
        <EmptyState
          title={hasActiveFilters ? 'لا نتائج لهذه الفلاتر' : 'لا توجد سجلات بعد'}
          description={hasActiveFilters ? 'جرّب توسيع الفترة أو إزالة فلتر المستخدم/الحدث.' : undefined}
        />
      ) : null}

      {!loading && !error && rows.length > 0 ? (
        <div className="rpm-card-list audit-log-list">
          {rows.map((r) => (
            <article key={r.id} className="rpm-card audit-log-card">
              <div className="rpm-card__row">
                <strong className="audit-log-card__summary">{r.summary || r.action}</strong>
                <span className="muted audit-log-card__time">
                  {r.created_at ? formatDateTime(r.created_at) : ''}
                </span>
              </div>
              <div className="rpm-card__meta">
                <span>
                  {r.user_phrase
                    || (r.user_role_title && r.user_name
                      ? `${r.user_role_title} (${r.user_name})`
                      : null)
                    || r.user_name
                    || (r.user_id ? `مستخدم #${r.user_id}` : 'النظام')}
                </span>
                {r.action ? <span className="audit-log-card__action">{r.action}</span> : null}
              </div>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
