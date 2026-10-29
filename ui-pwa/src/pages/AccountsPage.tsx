import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, Button, StatusPill, useToast,
  SkeletonBlock, ErrorState, EmptyState, TextField, Sheet,
} from '@/shared/ui'
import './pages.css'

type MonthRow = {
  year: number
  month: number
  label: string
  locked: boolean
  rent_revenue: number
  completed_contracts: number
}

type Account = { id: number; code: string; name: string; type: string }
type Payment = { id: number; amount: number; direction: string; method: string; paid_at: string; notes?: string; reference?: string }

export function AccountsPage() {
  const toast = useToast()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [months, setMonths] = useState<MonthRow[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [payments, setPayments] = useState<Payment[]>([])
  const [totals, setTotals] = useState({ payments_in: 0, payments_out: 0 })
  const [reopen, setReopen] = useState<MonthRow | null>(null)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  async function load() {
    setLoading(true)
    setError('')
    try {
      const [overview, pay] = await Promise.all([
        api<{ data: { months: MonthRow[]; accounts: Account[]; payments_in: number; payments_out: number } }>('/accounting/overview'),
        api<{ data: Payment[] }>('/accounting/payments'),
      ])
      setMonths(overview.data.months)
      setAccounts(overview.data.accounts)
      setTotals({ payments_in: overview.data.payments_in, payments_out: overview.data.payments_out })
      setPayments(pay.data)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

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
        subtitle="توزيع الإيجار على الشهور · إقفال شهر · رصيد دائن للعملاء · بلا ترحيل مخالفات"
      />

      <SummaryStrip
        items={[
          { label: 'مقبوضات', value: formatMoney(totals.payments_in), tone: 'success' },
          { label: 'مدفوعات للعملاء', value: formatMoney(totals.payments_out), tone: 'warning' },
          { label: 'دليل الحسابات', value: String(accounts.length) },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>الشهور</h3></div>
        {months.length === 0 ? <EmptyState title="لا توجد بيانات شهرية بعد" /> : (
          <div className="rpm-dense-list">
            {months.map((m) => (
              <div key={`${m.year}-${m.month}`} className="rpm-dense-row">
                <div>
                  <strong>{m.label}</strong>
                  <p className="muted">إيراد إيجار مرحّل: {formatMoney(m.rent_revenue)} · عقود مكتملة: {m.completed_contracts}</p>
                </div>
                <div className="rpm-card__actions">
                  <StatusPill status={m.locked ? 'maintenance' : 'available'} label={m.locked ? 'مقفل' : 'مفتوح'} />
                  {!m.locked ? (
                    <Button size="sm" variant="secondary" onClick={() => lockMonth(m)}>إقفال</Button>
                  ) : (
                    <Button size="sm" variant="secondary" onClick={() => setReopen(m)}>إعادة فتح</Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>دليل الحسابات</h3></div>
        <div className="rpm-dense-list">
          {accounts.map((a) => (
            <div key={a.id} className="rpm-dense-row">
              <div>
                <strong>{a.code} — {a.name}</strong>
                <p className="muted">{a.type}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>آخر الحركات</h3></div>
        {payments.length === 0 ? <EmptyState title="لا حركات بعد" description="تظهر هنا قبض/رد التأمين ومدفوعات العقود." /> : (
          <div className="rpm-dense-list">
            {payments.slice(0, 20).map((p) => (
              <div key={p.id} className="rpm-dense-row">
                <div>
                  <strong>{p.direction === 'in' ? 'مقبوض' : 'مدفوع للعميل'} · {formatMoney(p.amount)}</strong>
                  <p className="muted">{p.method} · {p.reference || '—'} · {p.notes || ''}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <Sheet
        open={!!reopen}
        title={reopen ? `إعادة فتح ${reopen.label}` : ''}
        onClose={() => setReopen(null)}
        footer={
          <>
            <Button loading={saving} onClick={reopenMonth}>تأكيد</Button>
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
