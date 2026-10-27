import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { PageHeader, Button, Sheet, Fab, SelectField, TextField, StatusPill, useToast, SkeletonBlock, EmptyState } from '@/shared/ui'
import './pages.css'

type Row = { id: number; category: string; status: string; customer?: { full_name: string; phone: string } }
type Customer = { id: number; full_name: string; phone: string }

export function WaitlistPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Row[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ customer_id: '', category: 'اقتصادي' })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Row[] }>('/waitlist')
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function onSave() {
    try {
      await api('/waitlist', { method: 'POST', json: { customer_id: Number(form.customer_id), category: form.category } })
      toast.success('تم إضافة طلب الانتظار.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="قائمة الانتظار" subtitle="إشعار عند التوفر — بلا إنشاء عقد تلقائي" action={<Button onClick={async () => { setOpen(true); const c = await api<{ data: Customer[] }>('/customers'); setCustomers(c.data) }}>طلب جديد</Button>} mobileFab />
      <Fab label="طلب جديد" onClick={async () => { setOpen(true); const c = await api<{ data: Customer[] }>('/customers'); setCustomers(c.data) }} />
      {loading ? <SkeletonBlock rows={3} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد طلبات انتظار" /> : null}
      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{r.customer?.full_name}</strong>
              <StatusPill status="pending" label={r.status === 'waiting' ? 'منتظر' : r.status} />
            </div>
            <p className="muted">{r.category} · {r.customer?.phone}</p>
          </article>
        ))}
      </div>
      <Sheet open={open} title="طلب انتظار" onClose={() => setOpen(false)} footer={<><Button onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="العميل" required options={customers.map((c) => ({ value: String(c.id), label: `${c.full_name} · ${c.phone}` }))} value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })} />
          <TextField label="الفئة المطلوبة" required value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
