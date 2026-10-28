import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import { PageHeader, Button, Sheet, Fab, SelectField, TextField, MoneyField, StatusPill, useToast, SkeletonBlock, EmptyState } from '@/shared/ui'
import './pages.css'

type Violation = {
  id: number
  amount: number
  occurred_at: string
  status: string
  location?: string
  vehicle?: { brand: string; model: string; plate: string }
  customer?: { full_name: string }
}

type Vehicle = { id: number; brand: string; model: string; plate: string }

const statusLabel: Record<string, string> = {
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

export function ViolationsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Violation[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ vehicle_id: '', occurred_at: '', amount: '', location: '' })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Violation[] }>('/violations')
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function onSave() {
    try {
      await api('/violations', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          occurred_at: form.occurred_at,
          amount: Number(form.amount),
          location: form.location || undefined,
        },
      })
      toast.success('تم التسجيل للمتابعة بلا ترحيل محاسبي.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function confirm(id: number) {
    try {
      await api(`/violations/${id}/confirm`, { method: 'POST', json: { collection_note: 'تم التأكيد' } })
      toast.success('تم تأكيد السداد دون قيد.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخالفات"
        subtitle="متابعة تشغيلية فقط — لا تدخل الحسابات أو إجمالي العقد"
        action={<Button onClick={async () => { setOpen(true); const v = await api<{ data: Vehicle[] }>('/vehicles'); setVehicles(v.data) }}>تسجيل مخالفة</Button>}
        mobileFab
      />
      <Fab label="تسجيل مخالفة" onClick={async () => { setOpen(true); const v = await api<{ data: Vehicle[] }>('/vehicles'); setVehicles(v.data) }} />
      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد مخالفات" /> : null}
      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{r.vehicle?.brand} {r.vehicle?.model}</strong>
              <StatusPill status={r.status === 'confirmed' ? 'completed' : 'pending'} label={statusLabel[r.status] || r.status} />
            </div>
            <p>{r.customer?.full_name || 'بلا إسناد بعد'}</p>
            <p className="muted">{formatDateTime(r.occurred_at)} · {formatMoney(r.amount)}</p>
            {r.status !== 'confirmed' ? <Button size="sm" variant="secondary" onClick={() => confirm(r.id)}>تأكيد السداد</Button> : null}
          </article>
        ))}
      </div>
      <Sheet open={open} title="مخالفة جديدة" onClose={() => setOpen(false)} footer={<><Button onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="السيارة" required options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))} value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })} />
          <TextField label="وقت المخالفة" required type="datetime-local" value={form.occurred_at} onChange={(e) => setForm({ ...form, occurred_at: e.target.value })} />
          <MoneyField label="المبلغ (للمتابعة)" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <TextField label="المكان" value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
