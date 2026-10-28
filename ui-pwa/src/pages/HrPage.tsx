import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, NumberField, TextAreaField, SelectField,
  useToast, EmptyState, SkeletonBlock, StatusPill,
} from '@/shared/ui'
import './pages.css'

type Payroll = {
  id: number
  year: number
  month: number
  status: string
  total_amount: number
  lines?: { id: number; net_amount: number; employee?: { name: string } }[]
}

type Evaluation = {
  id: number
  score: number
  period?: string | null
  notes?: string | null
  employee?: { name: string }
}

export function HrPage() {
  const toast = useToast()
  const [payrolls, setPayrolls] = useState<Payroll[]>([])
  const [evals, setEvals] = useState<Evaluation[]>([])
  const [employees, setEmployees] = useState<{ id: number; name: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [evalOpen, setEvalOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [evalForm, setEvalForm] = useState({ employee_id: '', score: '4', period: '', notes: '' })

  async function load() {
    setLoading(true)
    try {
      const [p, e, emp] = await Promise.all([
        api<{ data: Payroll[] }>('/payroll'),
        api<{ data: Evaluation[] }>('/evaluations'),
        api<{ data: { id: number; name: string }[] }>('/employees/lookup'),
      ])
      setPayrolls(p.data)
      setEvals(e.data)
      setEmployees(emp.data)
    } catch (err) {
      toast.error((err as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function draftPayroll() {
    const now = new Date()
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

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الموارد البشرية"
        subtitle="مسير رواتب · تقييمات موظفين"
        action={
          <>
            <Button variant="secondary" onClick={() => setEvalOpen(true)}>تقييم</Button>
            <Button onClick={() => void draftPayroll()}>مسودة مسير</Button>
          </>
        }
      />
      {loading ? <SkeletonBlock rows={5} /> : null}
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>مسير الرواتب</h3></div>
        {!loading && payrolls.length === 0 ? <EmptyState title="لا مسيرات بعد" /> : null}
        <div className="rpm-card-list">
          {payrolls.map((p) => (
            <article key={p.id} className="rpm-card">
              <div className="rpm-card__row">
                <strong>{p.year}-{String(p.month).padStart(2, '0')}</strong>
                <StatusPill status={p.status === 'approved' ? 'completed' : 'pending'} label={p.status === 'approved' ? 'معتمد' : 'مسودة'} />
              </div>
              <p>الإجمالي: {formatMoney(p.total_amount)} · {p.lines?.length || 0} موظف</p>
              {p.status !== 'approved' ? (
                <div className="rpm-card__actions"><Button size="sm" onClick={() => void approve(p.id)}>اعتماد</Button></div>
              ) : null}
            </article>
          ))}
        </div>
      </section>
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>التقييمات</h3></div>
        <div className="rpm-card-list">
          {evals.map((ev) => (
            <article key={ev.id} className="rpm-card">
              <div className="rpm-card__row"><strong>{ev.employee?.name || '—'}</strong><span>{ev.score}/5</span></div>
              <p className="muted">{ev.period || '—'} · {ev.notes || ''}</p>
            </article>
          ))}
        </div>
      </section>
      <Sheet open={evalOpen} title="تقييم موظف" onClose={() => setEvalOpen(false)} footer={<><Button loading={saving} onClick={() => void saveEval()}>حفظ</Button><Button variant="secondary" onClick={() => setEvalOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="الموظف" required value={evalForm.employee_id} onChange={(e) => setEvalForm({ ...evalForm, employee_id: e.target.value })} options={employees.map((e) => ({ value: String(e.id), label: e.name }))} />
          <NumberField label="الدرجة (1-5)" required value={evalForm.score} onChange={(e) => setEvalForm({ ...evalForm, score: e.target.value })} />
          <TextAreaField label="ملاحظات" value={evalForm.notes} onChange={(e) => setEvalForm({ ...evalForm, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
