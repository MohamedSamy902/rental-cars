import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { todayCivilIso } from '@/shared/lib/dates'
import { formatMoney, formatDate, formatDateTime } from '@/shared/lib/format'
import {
  PageHeader, MetricCardGrid, Button, StatusPill, useToast,
  SkeletonBlock, ErrorState, TextField, Sheet, DateField,
  IconCash, IconLandmark, IconWallet,
} from '@/shared/ui'
import './pages.css'
import './AccountsPage.css'

const ACCOUNTS_PER_PAGE = 20
const PAYMENTS_PER_PAGE = 20

const accountTypeLabel: Record<string, string> = {
  asset: 'أصل',
  liability: 'التزام',
  equity: 'حقوق ملكية',
  income: 'إيراد',
  expense: 'مصروف',
}

type MonthRow = {
  year: number
  month: number
  label: string
  locked: boolean
  rent_revenue: number
  fees_revenue?: number
  expenses?: number
  payroll_paid?: number
  net_operating?: number
  completed_contracts: number
}

type Account = { id: number; code: string; name: string; type: string }
type Payment = { id: number; amount: number; direction: string; method: string; paid_at: string; notes?: string; reference?: string }

type DailyEntry = {
  id: string
  source: string
  direction: 'in' | 'out' | string
  amount: number
  title: string
  subtitle?: string | null
  method?: string | null
  at?: string | null
  href?: string | null
}

type DailyCash = {
  date: string
  totals: { in: number; out: number; net: number; count: number }
  entries: DailyEntry[]
}

function monthKey(m: MonthRow) {
  return `${m.year}-${String(m.month).padStart(2, '0')}`
}

export function AccountsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const { can } = useAuth()
  /** موقّت: أعد تفعيل أزرار إقفال الشهر بضبط VITE_MONTH_LOCK_UI=1 */
  const monthLockUi = import.meta.env.VITE_MONTH_LOCK_UI === '1'
  const canLock = can('accounting.lock_month')
  const canReopen = can('accounting.reopen_month')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [months, setMonths] = useState<MonthRow[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [payments, setPayments] = useState<Payment[]>([])
  const [totals, setTotals] = useState({ payments_in: 0 })
  const [reopen, setReopen] = useState<MonthRow | null>(null)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [cashDate, setCashDate] = useState(todayCivilIso())
  const [daily, setDaily] = useState<DailyCash | null>(null)
  const [dailyLoading, setDailyLoading] = useState(true)
  const [dailyError, setDailyError] = useState('')
  const [accountsPage, setAccountsPage] = useState(1)
  const [paymentsPage, setPaymentsPage] = useState(1)
  const [paymentsMeta, setPaymentsMeta] = useState({ current_page: 1, last_page: 1, total: 0 })

  async function loadPayments(page = paymentsPage) {
    const qs = new URLSearchParams({
      direction: 'in',
      per_page: String(PAYMENTS_PER_PAGE),
      page: String(page),
    })
    const pay = await api<{ data: Payment[]; meta: { current_page: number; last_page: number; total: number } }>(
      `/accounting/payments?${qs}`,
    )
    setPayments(pay.data)
    setPaymentsMeta({
      current_page: pay.meta.current_page,
      last_page: pay.meta.last_page,
      total: pay.meta.total,
    })
    setPaymentsPage(pay.meta.current_page)
  }

  async function load() {
    setLoading(true)
    setError('')
    try {
      const overview = await api<{ data: { months: MonthRow[]; accounts: Account[]; payments_in: number } }>('/accounting/overview')
      setMonths(overview.data.months)
      setAccounts(overview.data.accounts)
      setTotals({ payments_in: overview.data.payments_in })
      setAccountsPage(1)
      await loadPayments(1)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  const pagedAccounts = useMemo(() => {
    const start = (accountsPage - 1) * ACCOUNTS_PER_PAGE
    return accounts.slice(start, start + ACCOUNTS_PER_PAGE)
  }, [accounts, accountsPage])
  const accountsLastPage = Math.max(1, Math.ceil(accounts.length / ACCOUNTS_PER_PAGE))

  async function loadDaily(date = cashDate) {
    setDailyLoading(true)
    setDailyError('')
    try {
      const qs = new URLSearchParams({ date })
      const res = await api<{ data: DailyCash }>(`/accounting/daily-cash?${qs}`)
      setDaily(res.data)
    } catch (e) {
      setDaily(null)
      setDailyError((e as ApiError).message)
    } finally {
      setDailyLoading(false)
    }
  }

  useEffect(() => { void load() }, [])
  useEffect(() => { void loadDaily(cashDate) }, [cashDate])

  async function lockMonth(m: MonthRow) {
    try {
      await api('/accounting/lock-month', { method: 'POST', json: { year: m.year, month: m.month } })
      toast.success(`أُقفل ${m.label}`)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function reopenMonth() {
    if (!reopen) return
    setSaving(true)
    try {
      await api('/accounting/reopen-month', {
        method: 'POST',
        json: { year: reopen.year, month: reopen.month, reason },
      })
      toast.success(`أُعيد فتح ${reopen.label}`)
      setReopen(null)
      setReason('')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <SkeletonBlock rows={8} />
  if (error) return <ErrorState title="تعذّر تحميل الحسابات" description={error} />

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الحسابات"
        subtitle="حركة الفلوس اليومية · إيرادات الإيجار حسب الشهر"
      />

      <MetricCardGrid
        label="ملخص الحسابات"
        items={[
          { id: 'in', label: 'مقبوضات', value: totals.payments_in ?? 0, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'coa', label: 'دليل الحسابات', value: accounts.length ?? 0, icon: <IconLandmark size={16} /> },
          {
            id: 'day-net',
            label: 'صافي اليوم',
            value: daily?.totals.net ?? 0,
            format: 'money',
            tone: (daily?.totals.net ?? 0) >= 0 ? 'success' : 'warning',
            icon: <IconWallet size={16} />,
          },
        ]}
      />

      <section className="rpm-panel ac-daily">
        <header className="ac-daily__intro">
          <h3>حركة الفلوس اليومية</h3>
          <p className="muted">
            كل ما له علاقة بفلوس في النظام لهذا اليوم (قبض · مصروف · راتب · مالك…)
          </p>
        </header>

        <div className="ac-daily__date">
          <DateField
            label="التاريخ"
            value={cashDate}
            onChange={(e) => setCashDate(e.target.value || todayCivilIso())}
          />
        </div>

        {dailyLoading ? <SkeletonBlock rows={4} /> : null}
        {dailyError ? <ErrorState title="تعذّر تحميل حركة اليوم" description={dailyError} /> : null}

        {!dailyLoading && daily ? (
          <>
            <MetricCardGrid
              className="ac-daily__kpis"
              label={`ملخص ${formatDate(daily.date)}`}
              mobile="wrap"
              items={[
                { id: 'din', label: 'دخل', value: daily.totals.in, format: 'money', tone: 'success' },
                { id: 'dout', label: 'خرج', value: daily.totals.out, format: 'money', tone: 'warning' },
                { id: 'dnet', label: 'صافي', value: daily.totals.net, format: 'money', tone: daily.totals.net >= 0 ? 'success' : 'danger' },
                { id: 'dcnt', label: 'بنود', value: daily.totals.count },
              ]}
            />
            {daily.entries.length === 0 ? (
              <p className="muted ac-daily__empty">لا حركة فلوس في هذا التاريخ.</p>
            ) : (
              <div className="ac-daily__entries">
                {daily.entries.map((row) => {
                  const body = (
                    <>
                      <div>
                        <strong>
                          {row.direction === 'in' ? 'دخل' : 'خرج'} · {row.title}
                        </strong>
                        <p className="muted">
                          {row.subtitle || '—'}
                          {row.at ? ` · ${formatDateTime(row.at)}` : ''}
                        </p>
                      </div>
                      <strong className={`tabular-nums ${row.direction === 'in' ? 'ac-month__net--pos' : 'ac-month__net--neg'}`}>
                        {formatMoney(row.amount)}
                      </strong>
                    </>
                  )
                  return row.href ? (
                    <Link key={row.id} to={row.href} className="ac-daily__row">
                      {body}
                    </Link>
                  ) : (
                    <div key={row.id} className="ac-daily__row">
                      {body}
                    </div>
                  )
                })}
              </div>
            )}
          </>
        ) : null}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>الشهور</h3></div>
        {months.length === 0 ? (
          <div className="rpm-dense-list">
            <div className="rpm-dense-row">
              <div>
                <strong>إيرادات الإيجار</strong>
                <p className="muted">لا شهور بعد — القيمة صفر</p>
              </div>
              <strong className="tabular-nums">{formatMoney(0)}</strong>
            </div>
          </div>
        ) : (
          <div className="ac-months">
            {months.map((m) => (
              <article key={monthKey(m)} className="ac-month">
                <header className="ac-month__head">
                  <h4>{m.label}</h4>
                  <StatusPill status={m.locked ? 'maintenance' : 'available'} label={m.locked ? 'مقفل' : 'مفتوح'} />
                </header>
                <dl className="ac-month__metrics">
                  <div>
                    <dt>إيرادات الإيجار</dt>
                    <dd className="tabular-nums">{formatMoney(m.rent_revenue)}</dd>
                  </div>
                  <div>
                    <dt>مصروفات</dt>
                    <dd className="tabular-nums">{formatMoney(m.expenses ?? 0)}</dd>
                  </div>
                  <div>
                    <dt>صافي الربح</dt>
                    <dd className={`tabular-nums ${(m.net_operating ?? 0) >= 0 ? 'ac-month__net--pos' : 'ac-month__net--neg'}`}>
                      {formatMoney(m.net_operating ?? (m.rent_revenue - (m.expenses ?? 0)))}
                    </dd>
                  </div>
                  <div>
                    <dt>عقود مكتملة</dt>
                    <dd className="tabular-nums">{m.completed_contracts}</dd>
                  </div>
                </dl>
                <div className="ac-month__actions">
                  <Button size="sm" variant="primary" onClick={() => navigate(`/accounts/months/${monthKey(m)}`)}>
                    دفتر الشهر
                  </Button>
                  {monthLockUi && !m.locked && canLock ? (
                    <Button size="sm" variant="danger" onClick={() => void lockMonth(m)}>إقفال</Button>
                  ) : null}
                  {monthLockUi && m.locked && canReopen ? (
                    <Button size="sm" variant="secondary" onClick={() => setReopen(m)}>إعادة فتح</Button>
                  ) : null}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>دليل الحسابات</h3>
          <span className="muted">{accounts.length} حساب</span>
        </div>
        <div className="rpm-dense-list">
          {pagedAccounts.map((a) => (
            <div key={a.id} className="rpm-dense-row">
              <div>
                <strong>{a.code} — {a.name}</strong>
                <p className="muted">{accountTypeLabel[a.type] || a.type}</p>
              </div>
            </div>
          ))}
        </div>
        {accountsLastPage > 1 ? (
          <div className="ac-pager">
            <Button size="sm" variant="secondary" disabled={accountsPage <= 1} onClick={() => setAccountsPage((p) => Math.max(1, p - 1))}>
              السابق
            </Button>
            <span className="muted tabular-nums">{accountsPage} / {accountsLastPage}</span>
            <Button size="sm" variant="secondary" disabled={accountsPage >= accountsLastPage} onClick={() => setAccountsPage((p) => Math.min(accountsLastPage, p + 1))}>
              التالي
            </Button>
          </div>
        ) : null}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>آخر المقبوضات</h3>
          <span className="muted">{paymentsMeta.total} حركة</span>
        </div>
        {payments.length === 0 ? (
          <div className="rpm-dense-list">
            <div className="rpm-dense-row">
              <div>
                <strong>لا مقبوضات بعد</strong>
                <p className="muted">تظهر هنا قبض التأمين ومدفوعات العقود</p>
              </div>
              <strong className="tabular-nums">{formatMoney(0)}</strong>
            </div>
          </div>
        ) : (
          <div className="rpm-dense-list">
            {payments.map((p) => (
              <div key={p.id} className="rpm-dense-row">
                <div>
                  <strong>مقبوض · {formatMoney(p.amount)}</strong>
                  <p className="muted">{p.method} · {p.reference || '—'} · {p.notes || ''}</p>
                </div>
              </div>
            ))}
          </div>
        )}
        {paymentsMeta.last_page > 1 ? (
          <div className="ac-pager">
            <Button
              size="sm"
              variant="secondary"
              disabled={paymentsMeta.current_page <= 1}
              onClick={() => void loadPayments(paymentsMeta.current_page - 1)}
            >
              السابق
            </Button>
            <span className="muted tabular-nums">{paymentsMeta.current_page} / {paymentsMeta.last_page}</span>
            <Button
              size="sm"
              variant="secondary"
              disabled={paymentsMeta.current_page >= paymentsMeta.last_page}
              onClick={() => void loadPayments(paymentsMeta.current_page + 1)}
            >
              التالي
            </Button>
          </div>
        ) : null}
      </section>

      <Sheet
        open={!!reopen}
        title={reopen ? `إعادة فتح ${reopen.label}` : ''}
        onClose={() => setReopen(null)}
        footer={
          <>
            <Button loading={saving} onClick={() => void reopenMonth()}>تأكيد</Button>
            <Button variant="secondary" onClick={() => setReopen(null)}>إلغاء</Button>
          </>
        }
      >
        <TextField
          label="سبب إعادة الفتح"
          required
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          hint="إلزامي ويُسجَّل في سجل التعديلات"
        />
      </Sheet>
    </div>
  )
}
