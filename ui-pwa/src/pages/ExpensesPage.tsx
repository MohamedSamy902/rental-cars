import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, Fab, TextField, MoneyField, SelectField, TextAreaField,
  useToast, EmptyState, SkeletonBlock,
} from '@/shared/ui'
import './pages.css'

type Expense = {
  id: number
  title: string
  category: string
  amount: number
  spent_on: string
  payment_method: string
  from_petty_cash: boolean
  notes?: string | null
}

export function ExpensesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Expense[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    title: '', category: 'تشغيل', amount: '', spent_on: new Date().toISOString().slice(0, 10),
    payment_method: 'cash', notes: '',
  })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Expense[] }>('/expenses')
      setRows(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function save() {
    setSaving(true)
    try {
      await api('/expenses', {
        method: 'POST',
        json: {
          ...form,
          amount: Number(form.amount),
        },
      })
      toast.success('تم تسجيل المصروف.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="المصروفات" subtitle="تشغيل · صيانة · أخرى مع ترحيل محاسبي" action={<Button onClick={() => setOpen(true)}>مصروف جديد</Button>} mobileFab />
      <Fab label="مصروف جديد" onClick={() => setOpen(true)} />
      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا مصروفات بعد" /> : null}
      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row"><strong>{r.title}</strong><span>{formatMoney(r.amount)}</span></div>
            <p className="muted">{r.category} · {r.spent_on} · {r.payment_method}</p>
          </article>
        ))}
      </div>
      <Sheet open={open} title="تسجيل مصروف" onClose={() => setOpen(false)} footer={<><Button loading={saving} onClick={() => void save()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="العنوان" required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <SelectField label="التصنيف" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={[{ value: 'تشغيل', label: 'تشغيل' }, { value: 'صيانة', label: 'صيانة' }, { value: 'أخرى', label: 'أخرى' }]} />
          <MoneyField label="المبلغ" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <TextField label="التاريخ" type="date" required value={form.spent_on} onChange={(e) => setForm({ ...form, spent_on: e.target.value })} />
          <SelectField label="طريقة الدفع" value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })} options={[{ value: 'cash', label: 'نقدي' }, { value: 'transfer', label: 'تحويل' }, { value: 'card', label: 'بطاقة' }]} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
