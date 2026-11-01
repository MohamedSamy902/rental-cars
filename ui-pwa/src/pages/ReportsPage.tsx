import { useEffect, useMemo, useState } from 'react'
import { motion } from 'framer-motion'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, SkeletonBlock, ErrorState, MetricCardGrid, FilterBar, Button, Sheet, SelectField,
  IconCash, IconReturn, IconTrend, IconWallet, IconClipboard, IconCheck, IconUsers, IconBan, IconAlert,
} from '@/shared/ui'
import './pages.css'
import './ReportsPage.css'

type Report = {
  receivables: number
  payments_in: number
  payments_out: number
  active_contracts: number
  completed_contracts: number
  customers: number
  blacklisted_customers: number
  open_violations: number
  expected_revenue_active: number
  occupancy_pct?: number
  fleet: { total: number; available: number; rented: number; reserved: number; maintenance: number }
  rent_by_month: { ym: string; total: number }[]
}

type TrialRow = { code: string; name: string; type: string; debit: number; credit: number; balance: number }
type IncomeStatement = {
  income: Array<TrialRow & { amount: number }>
  expenses: Array<TrialRow & { amount: number }>
  income_total: number
  expense_total: number
  net: number
}
type PayMethod = { method: string; label: string; in: number; out: number; net: number }
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
    current: { operating_profit: number; revenue: number }
    previous: { operating_profit: number }
    delta: number
    delta_pct: number
  }>
}
type CustomerOption = { id: number; full_name: string; phone?: string }
type CustomerStatement = {
  credit_balance: number
  contracts_total: number
  payments_in: number
  open_receivable: number
  customer: { id: number; full_name: string; credit_balance: number }
  contracts: Array<{ id: number; number: string; status: string; final_total: number; period_rent: number }>
}

const fleetMeta: Record<string, { label: string; tone: string }> = {
  available: { label: 'متاحة', tone: 'success' },
  rented: { label: 'مؤجرة', tone: 'info' },
  reserved: { label: 'محجوزة', tone: 'warning' },
  maintenance: { label: 'في الصيانة', tone: 'danger' },
}

const monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']

function ymLabel(ym: string) {
  const [y, m] = ym.split('-')
  return `${monthNames[Number(m) - 1] ?? m} ${y}`
}

type TabId = 'overview' | 'trial' | 'income' | 'payments' | 'customer' | 'profit'

export function ReportsPage() {
  const [data, setData] = useState<Report | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<TabId>('overview')
  const [trial, setTrial] = useState<TrialRow[] | null>(null)
  const [income, setIncome] = useState<IncomeStatement | null>(null)
  const [methods, setMethods] = useState<PayMethod[] | null>(null)
  const [profit, setProfit] = useState<ProfitCompare | null>(null)
  const [profitOpen, setProfitOpen] = useState(false)
  const [customers, setCustomers] = useState<CustomerOption[]>([])
  const [customerId, setCustomerId] = useState('')
  const [statement, setStatement] = useState<CustomerStatement | null>(null)
  const [tabError, setTabError] = useState('')
  const [tabLoading, setTabLoading] = useState(false)

  useEffect(() => {
    api<{ data: Report }>('/reports/summary')
      .then((r) => setData(r.data))
      .catch((e) => setError((e as ApiError).message))
  }, [])

  useEffect(() => {
    if (tab === 'overview') return
    setTabError('')
    setTabLoading(true)
    const run = async () => {
      try {
        if (tab === 'trial') {
          const r = await api<{ data: TrialRow[] }>('/reports/trial-balance')
          setTrial(r.data)
        } else if (tab === 'income') {
          const r = await api<{ data: IncomeStatement }>('/reports/income-statement')
          setIncome(r.data)
        } else if (tab === 'payments') {
          const r = await api<{ data: PayMethod[] }>('/reports/payment-methods')
          setMethods(r.data)
        } else if (tab === 'profit') {
          const r = await api<{ data: ProfitCompare }>('/reports/profitability-compare')
          setProfit(r.data)
        } else if (tab === 'customer') {
          const list = await api<{ data: CustomerOption[] }>('/customers?per_page=100')
          setCustomers(list.data)
        }
      } catch (e) {
        setTabError((e as ApiError).message)
      } finally {
        setTabLoading(false)
      }
    }
    void run()
  }, [tab])

  useEffect(() => {
    if (tab !== 'customer' || !customerId) {
      setStatement(null)
      return
    }
    setTabLoading(true)
    api<{ data: CustomerStatement }>(`/reports/customers/${customerId}/statement`)
      .then((r) => setStatement(r.data))
      .catch((e) => setTabError((e as ApiError).message))
      .finally(() => setTabLoading(false))
  }, [tab, customerId])

  const peak = useMemo(() => Math.max(1, ...(data?.rent_by_month.map((r) => r.total) ?? [0])), [data])

  async function openMom() {
    setProfitOpen(true)
    try {
      const r = await api<{ data: ProfitCompare }>('/reports/profitability-compare')
      setProfit(r.data)
    } catch (e) {
      setTabError((e as ApiError).message)
    }
  }

  if (error) return <ErrorState title="تعذّر تحميل التقارير" description={error} />
  if (!data) return <SkeletonBlock rows={8} />

  const occupancy = typeof data.occupancy_pct === 'number'
    ? Math.round(data.occupancy_pct)
    : (data.fleet.total ? Math.round((data.fleet.rented / data.fleet.total) * 100) : 0)

  const chips = [
    { id: 'overview', label: 'ملخص' },
    { id: 'profit', label: 'ربحية شهر بشهر' },
    { id: 'trial', label: 'ميزان مراجعة' },
    { id: 'income', label: 'قائمة دخل' },
    { id: 'payments', label: 'أرصدة طرق الدفع' },
    { id: 'customer', label: 'كشف عميل' },
  ]

  return (
    <div className="rpm-stack">
      <PageHeader
        title="التقارير"
        subtitle="الإشغال · ميزان المراجعة · قائمة الدخل · أرباح التشغيل"
        action={<Button onClick={() => void openMom()}>مقارنة أرباح الشهر</Button>}
      />

      <FilterBar
        search=""
        onSearchChange={() => undefined}
        chips={chips}
        activeChip={tab}
        onChipChange={(id) => setTab(id as TabId)}
        placeholder="أقسام التقارير"
      />

      {tab === 'overview' ? (
        <>
          <MetricCardGrid
            label="المؤشرات المالية"
            items={[
              { id: 'receivables', label: 'ذمم عقود', value: data.receivables, format: 'money', tone: 'warning', icon: <IconWallet size={16} /> },
              { id: 'in', label: 'مقبوضات', value: data.payments_in, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
              { id: 'out', label: 'مدفوع للعملاء', value: data.payments_out, format: 'money', icon: <IconReturn size={16} /> },
              { id: 'expected', label: 'إيراد متوقع (نشط)', value: data.expected_revenue_active, format: 'money', tone: 'info', icon: <IconTrend size={16} /> },
            ]}
          />

          <MetricCardGrid
            label="المؤشرات التشغيلية"
            mobile="wrap"
            items={[
              { id: 'active', label: 'عقود نشطة', value: data.active_contracts, tone: 'info', icon: <IconClipboard size={16} /> },
              { id: 'completed', label: 'عقود مكتملة', value: data.completed_contracts, tone: 'success', icon: <IconCheck size={16} /> },
              { id: 'customers', label: 'عملاء', value: data.customers, icon: <IconUsers size={16} /> },
              { id: 'blacklist', label: 'قائمة سوداء', value: data.blacklisted_customers, tone: 'danger', icon: <IconBan size={16} /> },
              { id: 'violations', label: 'مخالفات مفتوحة', value: data.open_violations, tone: 'warning', icon: <IconAlert size={16} />, hint: 'للمتابعة فقط — بلا ترحيل' },
            ]}
          />

          <div className="rp-bento">
            <section className="rpm-panel rp-fleet">
              <div className="rpm-panel__head">
                <h3>توزيع حالة الأسطول</h3>
                <span className="rp-chip tabular-nums">إشغال {occupancy}%</span>
              </div>
              <div className="rp-fleet__stack" aria-hidden>
                {Object.entries(fleetMeta).map(([key, meta]) => {
                  const value = data.fleet[key as keyof Report['fleet']] ?? 0
                  const pct = data.fleet.total ? (value / data.fleet.total) * 100 : 0
                  return pct > 0 ? (
                    <motion.span
                      key={key}
                      className={`rp-fleet__seg is-${meta.tone}`}
                      initial={{ flexGrow: 0 }}
                      animate={{ flexGrow: pct }}
                      transition={{ type: 'spring', stiffness: 160, damping: 26 }}
                    />
                  ) : null
                })}
              </div>
              <ul className="rp-fleet__legend">
                {Object.entries(fleetMeta).map(([key, meta]) => {
                  const value = data.fleet[key as keyof Report['fleet']] ?? 0
                  return (
                    <li key={key}>
                      <i className={`rp-dot is-${meta.tone}`} />
                      <span>{meta.label}</span>
                      <strong className="tabular-nums">{formatNumber(value)}</strong>
                    </li>
                  )
                })}
                <li className="is-total">
                  <span>الإجمالي</span>
                  <strong className="tabular-nums">{formatNumber(data.fleet.total)}</strong>
                </li>
              </ul>
            </section>

            <section className="rpm-panel rp-rent">
              <div className="rpm-panel__head"><h3>إيراد الإيجار حسب الشهر</h3></div>
              {data.rent_by_month.length === 0 ? (
                <p className="muted">يظهر بعد استلام عقود مكتملة وترحيلها محاسبياً.</p>
              ) : (
                <ol className="rp-rent__list">
                  {data.rent_by_month.map((row, i) => (
                    <li key={row.ym}>
                      <div className="rp-rent__meta">
                        <span>{ymLabel(row.ym)}</span>
                        <strong className="tabular-nums">{formatMoney(row.total)}</strong>
                      </div>
                      <div className="rp-rent__track">
                        <motion.span
                          initial={{ width: '0%' }}
                          animate={{ width: `${(row.total / peak) * 100}%` }}
                          transition={{ type: 'spring', stiffness: 140, damping: 24, delay: i * 0.04 }}
                        />
                      </div>
                    </li>
                  ))}
                </ol>
              )}
            </section>
          </div>
        </>
      ) : null}

      {tabLoading ? <SkeletonBlock rows={4} /> : null}
      {tabError ? <p className="muted">{tabError}</p> : null}

      {tab === 'trial' && trial ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>ميزان مراجعة</h3></div>
          <div className="rpm-table-wrap">
            <table className="rpm-table rpm-table--stack">
              <thead>
                <tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr>
              </thead>
              <tbody>
                {trial.map((r) => (
                  <tr key={r.code}>
                    <td data-label="الحساب"><strong className="rpm-table__primary">{r.code} · {r.name}</strong></td>
                    <td data-label="مدين" className="tabular-nums">{formatMoney(r.debit)}</td>
                    <td data-label="دائن" className="tabular-nums">{formatMoney(r.credit)}</td>
                    <td data-label="الرصيد" className="tabular-nums">{formatMoney(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {tab === 'income' && income ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head">
            <h3>قائمة دخل</h3>
            <span className="rp-chip tabular-nums">صافي {formatMoney(income.net)}</span>
          </div>
          <MetricCardGrid
            label="ملخص الفترة"
            items={[
              { id: 'inc', label: 'إيرادات', value: income.income_total, format: 'money', tone: 'success' },
              { id: 'exp', label: 'مصروفات', value: income.expense_total, format: 'money', tone: 'warning' },
              { id: 'net', label: 'صافي', value: income.net, format: 'money', tone: 'info' },
            ]}
          />
          <div className="rpm-table-wrap">
            <table className="rpm-table rpm-table--stack">
              <thead><tr><th>البند</th><th>النوع</th><th>المبلغ</th></tr></thead>
              <tbody>
                {income.income.map((r) => (
                  <tr key={`i-${r.code}`}><td data-label="البند">{r.code} · {r.name}</td><td data-label="النوع">إيراد</td><td data-label="المبلغ" className="tabular-nums">{formatMoney(r.amount)}</td></tr>
                ))}
                {income.expenses.map((r) => (
                  <tr key={`e-${r.code}`}><td data-label="البند">{r.code} · {r.name}</td><td data-label="النوع">مصروف</td><td data-label="المبلغ" className="tabular-nums">{formatMoney(r.amount)}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {tab === 'payments' && methods ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>أرصدة طرق الدفع</h3></div>
          <div className="rpm-card-list">
            {methods.map((m) => (
              <article key={m.method} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{m.label}</strong>
                  <strong className="tabular-nums">{formatMoney(m.net)}</strong>
                </div>
                <p className="muted">داخل {formatMoney(m.in)} · خارج {formatMoney(m.out)}</p>
              </article>
            ))}
          </div>
        </section>
      ) : null}

      {tab === 'customer' ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>كشف عميل</h3></div>
          <div className="rpm-form-grid">
            <SelectField
              label="العميل"
              value={customerId}
              onChange={(e) => setCustomerId(e.target.value)}
              options={customers.map((c) => ({ value: String(c.id), label: `${c.full_name}${c.phone ? ` · ${c.phone}` : ''}` }))}
            />
          </div>
          {statement ? (
            <>
              <MetricCardGrid
                label={statement.customer.full_name}
                items={[
                  { id: 'debt', label: 'إجمالي عقود', value: statement.contracts_total, format: 'money' },
                  { id: 'paid', label: 'مدفوع', value: statement.payments_in, format: 'money', tone: 'success' },
                  { id: 'open', label: 'ذمم مفتوحة', value: statement.open_receivable, format: 'money', tone: 'warning' },
                  { id: 'credit', label: 'رصيد دائن', value: statement.credit_balance, format: 'money', tone: 'info' },
                ]}
              />
              <div className="rpm-table-wrap">
                <table className="rpm-table rpm-table--stack">
                  <thead><tr><th>العقد</th><th>الحالة</th><th>الإجمالي</th></tr></thead>
                  <tbody>
                    {statement.contracts.map((c) => (
                      <tr key={c.id}>
                        <td data-label="العقد"><span className="rpm-table__code">{c.number}</span></td>
                        <td data-label="الحالة">{c.status}</td>
                        <td data-label="الإجمالي" className="tabular-nums">{formatMoney(c.final_total || c.period_rent)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          ) : null}
        </section>
      ) : null}

      {tab === 'profit' && profit ? <ProfitPanel data={profit} /> : null}

      <Sheet open={profitOpen} title="مقارنة أرباح الشهر" onClose={() => setProfitOpen(false)}>
        {profit ? <ProfitPanel data={profit} /> : <SkeletonBlock rows={3} />}
      </Sheet>
    </div>
  )
}

function ProfitPanel({ data }: { data: ProfitCompare }) {
  return (
    <div className="rpm-stack">
      <MetricCardGrid
        label={`${data.current_month} مقابل ${data.previous_month}`}
        items={[
          { id: 'cur', label: 'صافي الشهر الحالي', value: data.fleet.current.operating_profit, format: 'money', tone: 'success' },
          { id: 'prev', label: 'الشهر السابق', value: data.fleet.previous.operating_profit, format: 'money' },
          { id: 'delta', label: 'الفرق', value: data.fleet.delta, format: 'money', tone: data.fleet.delta >= 0 ? 'info' : 'warning' },
          { id: 'pct', label: 'نسبة التغيّر %', value: data.fleet.delta_pct, tone: data.fleet.delta_pct >= 0 ? 'success' : 'danger' },
        ]}
      />
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>حسب السيارة</h3></div>
        <div className="rpm-card-list">
          {data.vehicles.slice(0, 24).map((v) => (
            <article key={v.vehicle_id} className="rpm-card">
              <div className="rpm-card__row">
                <strong>{v.label}</strong>
                <strong className="tabular-nums">{formatMoney(v.current.operating_profit)}</strong>
              </div>
              <p className="muted">
                السابق {formatMoney(v.previous.operating_profit)} · فرق {formatMoney(v.delta)} · {formatNumber(v.delta_pct)}%
              </p>
            </article>
          ))}
        </div>
      </section>
    </div>
  )
}
