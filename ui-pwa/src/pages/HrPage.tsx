import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, FilterBar, Button, Sheet, NumberField, TextAreaField, SelectField, MoneyField, TextField,
  useToast, EmptyState, SkeletonBlock, StatusPill, MetricCardGrid,
  IconBriefcase, IconWallet, IconCash, IconTrend,
} from '@/shared/ui'
import './pages.css'
import './HrPage.css'

type Line = {
  id: number
  employee_id: number
  base_salary: number
  allowances: number
  deductions: number
  carried_in: number
  advances: number
  net_amount: number
  paid_amount: number
  carried_over: number
  employee?: { name: string }
  payouts?: { id: number; amount: number; paid_on: string }[]
}

type Payroll = {
  id: number
  year: number
  month: number
  status: string
  total_amount: number
  lines?: Line[]
}

type Evaluation = {
  id: number
  score: number
  period?: string | null
  notes?: string | null
  employee?: { name: string }
}

type Advance = {
  id: number
  amount: number
  advanced_on: string
  method?: string
  notes?: string | null
  employee?: { id: number; name: string }
}

const monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']

function periodLabel(year: number, month: number) {
  return `${monthNames[month - 1] ?? month} ${year}`
}

export function HrPage() {
  const toast = useToast()
  const now = useMemo(() => new Date(), [])
  const [payrolls, setPayrolls] = useState<Payroll[]>([])
  const [evals, setEvals] = useState<Evaluation[]>([])
  const [employees, setEmployees] = useState<{ id: number; name: string }[]>([])
  const [advances, setAdvances] = useState<Advance[]>([])
  const [loading, setLoading] = useState(true)
  const [evalOpen, setEvalOpen] = useState(false)
  const [advanceOpen, setAdvanceOpen] = useState(false)
  const [payoutLine, setPayoutLine] = useState<Line | null>(null)
  const [saving, setSaving] = useState(false)
  const [evalForm, setEvalForm] = useState({ employee_id: '', score: '4', period: '', notes: '' })
  const [advanceForm, setAdvanceForm] = useState({
    employee_id: '', amount: '', advanced_on: now.toISOString().slice(0, 10), method: 'cash', notes: '',
  })
  const [payoutForm, setPayoutForm] = useState({ amount: '', paid_on: now.toISOString().slice(0, 10), method: 'cash', notes: '' })
  const [chip, setChip] = useState('all')

  async function load() {
    setLoading(true)
    try {
      const [p, e, emp, adv] = await Promise.all([
        api<{ data: Payroll[] }>('/payroll'),
        api<{ data: Evaluation[] }>('/evaluations'),
        api<{ data: { id: number; name: string }[] }>('/employees/lookup'),
        api<{ data: Advance[] }>(`/salary-advances?year=${now.getFullYear()}&month=${now.getMonth() + 1}`),
      ])
      setPayrolls(p.data)
      setEvals(e.data)
      setEmployees(emp.data)
      setAdvances(adv.data)
    } catch (err) {
      toast.error((err as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function draftPayroll() {
    try {
      await api('/payroll/draft', { method: 'POST', json: { year: now.getFullYear(), month: now.getMonth() + 1 } })
      toast.success('تم تجهيز مسودة المسير.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function approve(id: number) {
    try {
      await api(`/payroll/${id}/approve`, { method: 'POST' })
      toast.success('تم اعتماد المسير.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function saveEval() {
    setSaving(true)
    try {
      await api('/evaluations', {
        method: 'POST',
        json: {
          employee_id: Number(evalForm.employee_id),
          score: Number(evalForm.score),
          period: evalForm.period || undefined,
          notes: evalForm.notes || undefined,
        },
      })
      toast.success('تم حفظ التقييم.')
      setEvalOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function saveAdvance() {
    setSaving(true)
    try {
      await api('/salary-advances', {
        method: 'POST',
        json: {
          employee_id: Number(advanceForm.employee_id),
          amount: Number(advanceForm.amount),
          advanced_on: advanceForm.advanced_on,
          method: advanceForm.method,
          notes: advanceForm.notes || undefined,
        },
      })
      toast.success('تم تسجيل السلفة.')
      setAdvanceOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function deleteAdvance(id: number) {
    try {
      await api(`/salary-advances/${id}`, { method: 'DELETE' })
      toast.success('تم حذف السلفة.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  function openPayout(line: Line) {
    const remaining = Math.max(0, line.net_amount - line.paid_amount)
    setPayoutLine(line)
    setPayoutForm({
      amount: String(remaining),
      paid_on: now.toISOString().slice(0, 10),
      method: 'cash',
      notes: '',
    })
  }

  async function savePayout() {
    if (!payoutLine) return
    setSaving(true)
    try {
      const res = await api<{ message: string }>(`/payroll/lines/${payoutLine.id}/payout`, {
        method: 'POST',
        json: {
          amount: Number(payoutForm.amount),
          paid_on: payoutForm.paid_on,
          method: payoutForm.method,
          notes: payoutForm.notes || undefined,
        },
      })
      toast.success(res.message)
      setPayoutLine(null)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const stats = useMemo(() => {
    const draft = payrolls.find((p) => p.year === now.getFullYear() && p.month === now.getMonth() + 1)
    const remaining = (draft?.lines ?? []).reduce((s, l) => s + Math.max(0, l.net_amount - l.paid_amount), 0)
    const draftCount = payrolls.filter((p) => p.status !== 'approved').length
    return {
      advances: advances.reduce((s, a) => s + Number(a.amount), 0),
      advanceCount: advances.length,
      net: draft?.total_amount ?? 0,
      remaining,
      paid: (draft?.lines ?? []).reduce((s, l) => s + Number(l.paid_amount), 0),
      draftCount,
    }
  }, [advances, payrolls, now])

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'advances',
        label: 'السلف',
        attention: stats.advanceCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${stats.advanceCount} سلفة هذا الشهر`,
      },
      {
        id: 'payroll',
        label: 'المسير',
        attention: stats.draftCount > 0 ? ('warning' as const) : stats.remaining > 0 ? ('critical' as const) : null,
        attentionLabel: stats.draftCount > 0 ? 'مسيرات بانتظار الاعتماد' : 'متبقي للصرف',
      },
      { id: 'evals', label: 'التقييمات' },
    ],
    [stats.advanceCount, stats.draftCount, stats.remaining],
  )

  return (
    <div className="rpm-stack hr-page">
      <PageHeader
        title="الموارد البشرية"
        subtitle="سلف شهرية · مسير · صرف جزئي وترحيل المتبقي"
        action={
          <>
            <Button variant="secondary" onClick={() => setAdvanceOpen(true)}>سلفة</Button>
            <Button variant="secondary" onClick={() => setEvalOpen(true)}>تقييم</Button>
            <Button onClick={() => void draftPayroll()}>مسودة مسير</Button>
          </>
        }
      />

      <MetricCardGrid
        label="ملخص المسير الحالي"
        items={[
          { id: 'adv', label: 'سلف هذا الشهر', value: stats.advances, format: 'money', tone: 'warning', icon: <IconWallet size={16} /> },
          { id: 'net', label: 'صافي المسير', value: stats.net, format: 'money', icon: <IconBriefcase size={16} /> },
          { id: 'paid', label: 'مصروف', value: stats.paid, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'left', label: 'متبقي للترحيل', value: stats.remaining, format: 'money', tone: 'info', icon: <IconTrend size={16} /> },
        ]}
      />

      <FilterBar
        search=""
        onSearchChange={() => undefined}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="أقسام الموارد البشرية"
      />

      {loading ? <SkeletonBlock rows={5} /> : null}

      {chip === 'all' || chip === 'advances' ? (
      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>سلف {periodLabel(now.getFullYear(), now.getMonth() + 1)}</h3>
          <Button size="sm" variant="secondary" onClick={() => setAdvanceOpen(true)}>تسجيل سلفة</Button>
        </div>
        {!loading && advances.length === 0 ? <EmptyState title="لا سلف هذا الشهر" description="سجّل السلفة أثناء الشهر لتُخصم تلقائياً من المسير." /> : null}
        <div className="rpm-card-list">
          {advances.map((a) => (
            <article key={a.id} className="rpm-card hr-card">
              <div className="rpm-card__row">
                <strong>{a.employee?.name || 'موظف'}</strong>
                <strong className="tabular-nums">{formatMoney(a.amount)}</strong>
              </div>
              <p className="muted">{a.advanced_on} · {a.method === 'transfer' ? 'تحويل' : a.method === 'card' ? 'بطاقة' : 'نقدي'}{a.notes ? ` · ${a.notes}` : ''}</p>
              <div className="rpm-card__actions">
                <Button size="sm" variant="danger" onClick={() => void deleteAdvance(a.id)}>حذف</Button>
              </div>
            </article>
          ))}
        </div>
      </section>
      ) : null}

      {chip === 'all' || chip === 'payroll' ? (
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>مسير الرواتب</h3></div>
        {!loading && payrolls.length === 0 ? <EmptyState title="لا مسيرات بعد" /> : null}
        <div className="rpm-card-list">
          {payrolls.map((p) => (
            <article key={p.id} className="rpm-card hr-card">
              <div className="rpm-card__row">
                <strong>{periodLabel(p.year, p.month)}</strong>
                <StatusPill status={p.status === 'approved' ? 'completed' : 'pending'} label={p.status === 'approved' ? 'معتمد' : 'مسودة'} />
              </div>
              <p>صافي المسير: {formatMoney(p.total_amount)} · {p.lines?.length || 0} موظف</p>
              <ul className="hr-lines">
                {(p.lines ?? []).map((l) => {
                  const remaining = Math.max(0, l.net_amount - l.paid_amount)
                  return (
                    <li key={l.id}>
                      <div className="hr-line__who">
                        <strong>{l.employee?.name || '—'}</strong>
                        <span className="tabular-nums">{formatMoney(l.net_amount)}</span>
                      </div>
                      <p className="muted">
                        أساسي {formatMoney(l.base_salary)}
                        {l.carried_in ? ` · مرحّل داخل ${formatMoney(l.carried_in)}` : ''}
                        {l.advances ? ` · سلف ${formatMoney(l.advances)}` : ''}
                        {` · مصروف ${formatMoney(l.paid_amount)}`}
                        {l.carried_over ? ` · يُرحَّل ${formatMoney(l.carried_over)}` : remaining && p.status === 'approved' ? ` · متبقي ${formatMoney(remaining)}` : ''}
                      </p>
                      {p.status === 'approved' && remaining > 0 ? (
                        <div className="rpm-card__actions">
                          <Button size="sm" onClick={() => openPayout(l)}>صرف / ترحيل</Button>
                        </div>
                      ) : null}
                    </li>
                  )
                })}
              </ul>
              {p.status !== 'approved' ? (
                <div className="rpm-card__actions">
                  <Button size="sm" onClick={() => void approve(p.id)}>اعتماد المسير</Button>
                </div>
              ) : null}
            </article>
          ))}
        </div>
      </section>
      ) : null}

      {chip === 'all' || chip === 'evals' ? (
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>التقييمات</h3></div>
        <div className="rpm-card-list">
          {evals.map((ev) => (
            <article key={ev.id} className="rpm-card hr-card">
              <div className="rpm-card__row"><strong>{ev.employee?.name || '—'}</strong><span>{ev.score}/5</span></div>
              <p className="muted">{ev.period || '—'} · {ev.notes || ''}</p>
            </article>
          ))}
        </div>
      </section>
      ) : null}

      <Sheet open={evalOpen} title="تقييم موظف" onClose={() => setEvalOpen(false)} footer={<><Button loading={saving} onClick={() => void saveEval()}>حفظ</Button><Button variant="secondary" onClick={() => setEvalOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="الموظف" required value={evalForm.employee_id} onChange={(e) => setEvalForm({ ...evalForm, employee_id: e.target.value })} options={employees.map((e) => ({ value: String(e.id), label: e.name }))} />
          <NumberField label="الدرجة (1-5)" required value={evalForm.score} onChange={(e) => setEvalForm({ ...evalForm, score: e.target.value })} />
          <TextAreaField label="ملاحظات" value={evalForm.notes} onChange={(e) => setEvalForm({ ...evalForm, notes: e.target.value })} />
        </div>
      </Sheet>

      <Sheet open={advanceOpen} title="تسجيل سلفة" onClose={() => setAdvanceOpen(false)} footer={<><Button loading={saving} onClick={() => void saveAdvance()}>حفظ السلفة</Button><Button variant="secondary" onClick={() => setAdvanceOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="الموظف" required value={advanceForm.employee_id} onChange={(e) => setAdvanceForm({ ...advanceForm, employee_id: e.target.value })} options={employees.map((e) => ({ value: String(e.id), label: e.name }))} />
          <MoneyField label="المبلغ" required value={advanceForm.amount} onChange={(e) => setAdvanceForm({ ...advanceForm, amount: e.target.value })} />
          <TextField label="التاريخ" type="date" required value={advanceForm.advanced_on} onChange={(e) => setAdvanceForm({ ...advanceForm, advanced_on: e.target.value })} />
          <SelectField
            label="طريقة الصرف"
            value={advanceForm.method}
            onChange={(e) => setAdvanceForm({ ...advanceForm, method: e.target.value })}
            options={[{ value: 'cash', label: 'نقدي' }, { value: 'transfer', label: 'تحويل' }, { value: 'card', label: 'بطاقة' }]}
          />
          <TextAreaField label="ملاحظات" value={advanceForm.notes} onChange={(e) => setAdvanceForm({ ...advanceForm, notes: e.target.value })} />
        </div>
      </Sheet>

      <Sheet
        open={!!payoutLine}
        title={payoutLine ? `صرف راتب · ${payoutLine.employee?.name || ''}` : ''}
        onClose={() => setPayoutLine(null)}
        footer={<><Button loading={saving} onClick={() => void savePayout()}>تأكيد الصرف</Button><Button variant="secondary" onClick={() => setPayoutLine(null)}>إلغاء</Button></>}
      >
        {payoutLine ? (
          <div className="rpm-form-grid">
            <p className="muted">
              الصافي {formatMoney(payoutLine.net_amount)} · مصروف {formatMoney(payoutLine.paid_amount)} · متبقي {formatMoney(Math.max(0, payoutLine.net_amount - payoutLine.paid_amount))}.
              أي مبلغ أقل من المتبقي يُرحَّل تلقائياً للمسير التالي.
            </p>
            <MoneyField label="المبلغ المصروف" required value={payoutForm.amount} onChange={(e) => setPayoutForm({ ...payoutForm, amount: e.target.value })} />
            <TextField label="تاريخ الصرف" type="date" required value={payoutForm.paid_on} onChange={(e) => setPayoutForm({ ...payoutForm, paid_on: e.target.value })} />
            <SelectField
              label="طريقة الصرف"
              value={payoutForm.method}
              onChange={(e) => setPayoutForm({ ...payoutForm, method: e.target.value })}
              options={[{ value: 'cash', label: 'نقدي' }, { value: 'transfer', label: 'تحويل' }, { value: 'card', label: 'بطاقة' }]}
            />
            <TextAreaField label="ملاحظات" value={payoutForm.notes} onChange={(e) => setPayoutForm({ ...payoutForm, notes: e.target.value })} />
          </div>
        ) : null}
      </Sheet>
    </div>
  )
}
