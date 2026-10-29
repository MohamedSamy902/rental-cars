import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/format'
import {
  Button, MetricCardGrid, StatusPill, SkeletonBlock, ErrorState, EmptyState, Sheet, TextField, useToast,
  IconCash, IconReceipt, IconWallet, IconTrend, IconReturn, IconBriefcase, IconAlert, IconKey,
} from '@/shared/ui'
import './pages.css'
import './MonthLedgerPage.css'

type Row = {
  id: number
  date?: string | null
  amount: number
  title?: string
  category?: string
  method?: string | null
  vehicle?: string | null
  contract?: string | null
  customer?: string | null
  receipt?: string | null
  reason?: string | null
  narration?: string | null
  kind?: string
  employee?: string | null
  plate?: string | null
  status?: string
  notes?: string | null
  reference?: string | null
  link_type?: string
}

type Ledger = {
  year: number
  month: number
  label: string
  lock: { locked: boolean; locked_at?: string | null; locked_by?: string | null; reopen_reason?: string | null }
  summary: {
    rent_revenue: number
    fees_revenue: number
    expenses: number
    payroll_paid: number
    net_operating: number
    deposits_received: number
    deposits_returned: number
    deposit_deductions: number
    payments_in: number
    client_payouts: number
    violations_tracked: number
    completed_contracts: number
  }
  rents: Row[]
  fees: Row[]
  expenses: Row[]
  deposits: { received: Row[]; returned: Row[]; deductions: Row[] }
  payments_in: Row[]
  client_payouts: Row[]
  payroll: Row[]
  violations: Row[]
  audit: { id: number; action: string; at?: string | null; user?: string | null; reason?: string | null }[]
}

function csvEscape(value: unknown) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`
}

function downloadCsv(filename: string, header: string[], rows: unknown[][]) {
  const body = [header, ...rows].map((r) => r.map(csvEscape).join(',')).join('\n')
  const blob = new Blob([`\uFEFF${body}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

function when(value?: string | null) {
  if (!value) return '—'
  return value.length > 10 ? formatDateTime(value) : value
}

function MoneyRows({ rows, empty, render }: { rows: Row[]; empty: string; render: (r: Row) => { title: string; sub: string } }) {
  if (rows.length === 0) return <EmptyState title={empty} />
  return (
    <div className="rpm-dense-list">
      {rows.map((r) => {
        const cell = render(r)
        return (
          <div key={r.id} className="rpm-dense-row">
            <div>
              <strong>{cell.title}</strong>
              <p className="muted">{cell.sub}</p>
            </div>
            <strong className="tabular-nums">{formatMoney(r.amount)}</strong>
          </div>
        )
      })}
    </div>
  )
}

export function MonthLedgerPage() {
  const { monthId } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState<Ledger | null>(null)
  const [error, setError] = useState('')
  const [reopen, setReopen] = useState(false)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  const match = /^(\d{4})-(\d{2})$/.exec(monthId || '')
  const year = match ? Number(match[1]) : 0
  const month = match ? Number(match[2]) : 0

  async function load() {
    if (!year || !month) {
      setError('معرّف الشهر غير صالح.')
      return
    }
    setError('')
    try {
      const res = await api<{ data: Ledger }>(`/accounting/months/${year}/${month}`)
      setData(res.data)
    } catch (e) {
      setError((e as ApiError).message)
    }
  }

  useEffect(() => { void load() }, [year, month])

  async function lockMonth() {
    if (!data) return
    try {
      await api('/accounting/lock-month', { method: 'POST', json: { year: data.year, month: data.month } })
      toast.success(`أُقفل ${data.label}`)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function reopenMonth() {
    if (!data) return
    setSaving(true)
    try {
      await api('/accounting/reopen-month', { method: 'POST', json: { year: data.year, month: data.month, reason } })
      toast.success(`أُعيد فتح ${data.label}`)
      setReopen(false)
      setReason('')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  function exportCsv() {
    if (!data) return
    const rows: unknown[][] = []
    const push = (kind: string, r: Row, extra = '') => {
      rows.push([kind, when(r.date), r.contract || '', r.customer || r.employee || r.vehicle || r.plate || extra, r.amount, r.method || r.receipt || r.reason || r.notes || ''])
    }
    data.rents.forEach((r) => push('إيجار', r))
    data.fees.forEach((r) => push(r.kind || 'رسوم', r))
    data.expenses.forEach((r) => push(`مصروف/${r.category || ''}`, r, r.title || ''))
    data.deposits.received.forEach((r) => push('تأمين مقبوض', r))
    data.deposits.returned.forEach((r) => push('تأمين مردود', r))
    data.deposits.deductions.forEach((r) => push('خصم تأمين', r))
    data.client_payouts.forEach((r) => push('مدفوع للعميل', r))
    data.payroll.forEach((r) => push('راتب', r))
    data.violations.forEach((r) => push('مخالفة (متابعة)', r))
    downloadCsv(`rpm-${data.year}-${String(data.month).padStart(2, '0')}.csv`, ['النوع', 'التاريخ', 'العقد', 'الطرف', 'المبلغ', 'مرجع'], rows)
  }

  if (!match) return <ErrorState title="شهر غير صالح" description="استخدم الرابط من قائمة الشهور." />
  if (error && !data) return <ErrorState title="تعذّر تحميل دفتر الشهر" description={error} action={<Button onClick={() => void load()}>إعادة المحاولة</Button>} />
  if (!data) return <SkeletonBlock rows={10} />

  const s = data.summary

  return (
    <div className="ml">
      <header className="ml-hero">
        <div className="ml-hero__top">
          <button type="button" className="ml-back" onClick={() => navigate('/accounts')}>رجوع للحسابات</button>
          <div className="ml-hero__actions">
            <Button size="sm" variant="secondary" onClick={exportCsv}>تصدير CSV</Button>
            {!data.lock.locked ? (
              <Button size="sm" variant="secondary" onClick={() => void lockMonth()}>إقفال الشهر</Button>
            ) : (
              <Button size="sm" variant="secondary" onClick={() => setReopen(true)}>إعادة فتح</Button>
            )}
          </div>
        </div>
        <div className="ml-identity">
          <StatusPill status={data.lock.locked ? 'maintenance' : 'available'} label={data.lock.locked ? 'مقفل' : 'مفتوح'} />
          <h1>{data.label}</h1>
          <p>
            {formatNumber(s.completed_contracts)} عقود مكتملة
            {data.lock.locked_by ? ` · أقفل ${data.lock.locked_by}` : ''}
            {data.lock.reopen_reason ? ` · آخر سبب إعادة فتح: ${data.lock.reopen_reason}` : ''}
          </p>
        </div>
      </header>

      <MetricCardGrid
        label="ملخص الشهر"
        items={[
          { id: 'rent', label: 'إيراد الإيجار', value: s.rent_revenue, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'fees', label: 'رسوم وأضرار', value: s.fees_revenue, format: 'money', icon: <IconTrend size={16} /> },
          { id: 'exp', label: 'المصروفات', value: s.expenses, format: 'money', tone: 'warning', icon: <IconReceipt size={16} /> },
          { id: 'pay', label: 'رواتب مصروفة', value: s.payroll_paid, format: 'money', icon: <IconBriefcase size={16} /> },
          { id: 'net', label: 'صافي التشغيل', value: s.net_operating, format: 'money', tone: s.net_operating >= 0 ? 'success' : 'danger', icon: <IconWallet size={16} /> },
          { id: 'dep', label: 'تأمينات مقبوضة', value: s.deposits_received, format: 'money', icon: <IconKey size={16} /> },
          { id: 'out', label: 'مدفوع للعملاء', value: s.client_payouts, format: 'money', icon: <IconReturn size={16} /> },
          { id: 'viol', label: 'مخالفات متابعة', value: s.violations_tracked, format: 'money', tone: 'warning', icon: <IconAlert size={16} />, hint: 'بلا ترحيل محاسبي' },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>الإيجارات المرحّلة</h3></div>
        <MoneyRows rows={data.rents} empty="لا إيجار مرحّل هذا الشهر" render={(r) => ({
          title: r.contract ? `${r.contract} · ${r.customer || ''}` : (r.narration || 'قيد إيجار'),
          sub: `${when(r.date)} · ${r.vehicle || ''}`.trim(),
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>رسوم وأضرار</h3></div>
        <MoneyRows rows={data.fees} empty="لا رسوم إضافية" render={(r) => ({
          title: `${r.kind || 'رسم'} · ${r.contract || ''}`,
          sub: `${when(r.date)} · ${r.customer || ''}`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المصروفات</h3></div>
        <MoneyRows rows={data.expenses} empty="لا مصروفات" render={(r) => ({
          title: r.title || 'مصروف',
          sub: `${when(r.date)} · ${r.category || ''} · ${r.vehicle || r.method || ''}`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>التأمينات النقدية</h3></div>
        <h4 className="ml-sub">مقبوض</h4>
        <MoneyRows rows={data.deposits.received} empty="لا قبض تأمين" render={(r) => ({
          title: `${r.contract || ''} · ${r.customer || ''}`,
          sub: `${when(r.date)} · ${r.receipt || r.method || ''}`,
        })} />
        <h4 className="ml-sub">خصومات</h4>
        <MoneyRows rows={data.deposits.deductions} empty="لا خصومات" render={(r) => ({
          title: r.reason || 'خصم',
          sub: `${when(r.date)} · ${r.contract || ''} · ${r.customer || ''}`,
        })} />
        <h4 className="ml-sub">مردود للعملاء</h4>
        <MoneyRows rows={data.deposits.returned} empty="لا رد تأمين" render={(r) => ({
          title: `${r.contract || ''} · ${r.customer || ''}`,
          sub: `${when(r.date)} · ${r.receipt || r.method || ''}`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>مدفوعات للعملاء</h3></div>
        <MoneyRows rows={data.client_payouts} empty="لا مدفوعات للعملاء" render={(r) => ({
          title: r.notes || r.reference || 'مدفوع',
          sub: `${when(r.date)} · ${r.method || ''} · ${r.link_type || ''}`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>صرف الرواتب</h3></div>
        <MoneyRows rows={data.payroll} empty="لا صرف رواتب" render={(r) => ({
          title: r.employee || 'موظف',
          sub: `${when(r.date)} · ${r.method || ''}`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>مخالفات للمتابعة</h3></div>
        <MoneyRows rows={data.violations} empty="لا مخالفات هذا الشهر" render={(r) => ({
          title: `${r.plate || ''} · ${r.customer || 'بدون عميل'}`,
          sub: `${when(r.date)} · ${r.status || ''} — بلا ترحيل محاسبي`,
        })} />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>سجل الإقفال</h3></div>
        {data.audit.length === 0 ? <EmptyState title="لا أثر إقفال بعد" /> : (
          <ol className="ml-audit">
            {data.audit.map((a) => (
              <li key={a.id}>
                <strong>{a.action === 'month_locked' ? 'إقفال' : 'إعادة فتح'}</strong>
                <span className="muted">{a.user || 'النظام'} · {a.at ? formatDateTime(a.at) : '—'}</span>
                {a.reason ? <p>{a.reason}</p> : null}
              </li>
            ))}
          </ol>
        )}
      </section>

      <Sheet
        open={reopen}
        title={`إعادة فتح ${data.label}`}
        onClose={() => setReopen(false)}
        footer={
          <>
            <Button loading={saving} onClick={() => void reopenMonth()}>تأكيد</Button>
            <Button variant="secondary" onClick={() => setReopen(false)}>إلغاء</Button>
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
