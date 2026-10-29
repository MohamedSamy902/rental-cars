import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  PageHeader, SummaryStrip, Button, Sheet, Fab, SelectField, NumberField, MoneyField,
  TextField, SwitchField, StatusPill, useToast, SkeletonBlock, EmptyState,
} from '@/shared/ui'
import './pages.css'

type WorkOrder = {
  id: number
  status: string
  workshop?: string
  parts_cost: number
  labor_cost: number
  vehicle?: { brand: string; model: string; plate: string }
}

type Vehicle = { id: number; brand: string; model: string; plate: string }
type Item = { id: number; name: string; code: string }

const statusLabel: Record<string, string> = {
  open: 'مفتوح', in_progress: 'قيد التنفيذ', closed: 'مغلق', cancelled: 'ملغى',
}

export function MaintenancePage() {
  const toast = useToast()
  const [rows, setRows] = useState<WorkOrder[]>([])
  const [summary, setSummary] = useState({ open: 0, closed: 0 })
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [items, setItems] = useState<Item[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [fromStock, setFromStock] = useState(true)
  const [fromPetty, setFromPetty] = useState(false)
  const [putsInMaintenance, setPutsInMaintenance] = useState(true)
  const [employees, setEmployees] = useState<{ id: number; name: string }[]>([])
  const [form, setForm] = useState({
    vehicle_id: '', odometer: '', workshop: '', labor_cost: '0', description: 'صيانة', inventory_item_id: '', quantity: '1', unit_price: '0',
    petty_cash_employee_id: '',
  })

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: WorkOrder[]; summary: typeof summary }>('/work-orders')
      setRows(res.data)
      setSummary(res.summary)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function openSheet() {
    setOpen(true)
    const [v, i, e] = await Promise.all([
      api<{ data: Vehicle[] }>('/vehicles'),
      api<{ data: Item[] }>('/inventory').catch(() => ({ data: [] as Item[] })),
      api<{ data: { id: number; name: string }[] }>('/employees').catch(() => ({ data: [] as { id: number; name: string }[] })),
    ])
    setVehicles(v.data)
    setItems(i.data)
    setEmployees(e.data)
  }

  async function onSave() {
    try {
      await api('/work-orders', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          odometer: Number(form.odometer),
          workshop: form.workshop || undefined,
          labor_cost: Number(form.labor_cost || 0),
          puts_in_maintenance: putsInMaintenance,
          force_after_resolution: true,
          lines: [
            {
              description: form.description,
              part_source: fromStock ? 'stock' : 'purchase',
              inventory_item_id: fromStock ? Number(form.inventory_item_id) : undefined,
              quantity: Number(form.quantity || 1),
              unit_price: fromStock ? undefined : Number(form.unit_price || 0),
              from_petty_cash: !fromStock && fromPetty,
              petty_cash_employee_id: !fromStock && fromPetty ? Number(form.petty_cash_employee_id) : undefined,
            },
          ],
        },
      })
      toast.success(fromStock ? 'تم فتح أمر الشغل مع ترحيل صرف المخزون.' : 'تم فتح أمر الشغل.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function closeOrder(id: number) {
    try {
      await api(`/work-orders/${id}/close`, { method: 'POST' })
      toast.success('تم إغلاق أمر الشغل.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="أوامر الشغل" subtitle="صرف FIFO بترحيل محاسبي · شراء من العهدة اختياري" action={<Button onClick={() => void openSheet()}>أمر شغل</Button>} mobileFab />
      <Fab label="أمر شغل" onClick={openSheet} />
      <SummaryStrip items={[
        { label: 'مفتوحة', value: String(summary.open), tone: 'warning' },
        { label: 'مغلقة', value: String(summary.closed) },
      ]} />
      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد أوامر شغل" /> : null}
      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{r.vehicle?.brand} {r.vehicle?.model} · {r.vehicle?.plate}</strong>
              <StatusPill status={r.status === 'closed' ? 'completed' : 'maintenance'} label={statusLabel[r.status] || r.status} />
            </div>
            <p className="muted">{r.workshop || '—'}</p>
            <div className="rpm-card__meta">
              <span>قطع: {r.parts_cost} ج.م</span>
              <span>عمالة: {r.labor_cost} ج.م</span>
            </div>
            {r.status !== 'closed' ? <Button size="sm" variant="secondary" onClick={() => closeOrder(r.id)}>إغلاق</Button> : null}
          </article>
        ))}
      </div>

      <Sheet open={open} title="أمر شغل" onClose={() => setOpen(false)} footer={<><Button onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="السيارة" required options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))} value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })} />
          <TextField label="الورشة" value={form.workshop} onChange={(e) => setForm({ ...form, workshop: e.target.value })} />
          <NumberField label="العداد" required value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
          <SwitchField label="يضع السيارة في صيانة" checked={putsInMaintenance} onChange={setPutsInMaintenance} />
          <SwitchField label="القطعة من المخزون (FIFO)" checked={fromStock} onChange={(v) => { setFromStock(v); if (v) setFromPetty(false) }} />
          {fromStock ? (
            <SelectField label="صنف المخزون" required options={items.map((i) => ({ value: String(i.id), label: `${i.code} · ${i.name}` }))} value={form.inventory_item_id} onChange={(e) => setForm({ ...form, inventory_item_id: e.target.value })} />
          ) : (
            <>
              <MoneyField label="سعر شراء الوحدة" required value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value })} />
              <SwitchField label="الدفع من عهدة موظف" checked={fromPetty} onChange={setFromPetty} />
              {fromPetty ? (
                <SelectField
                  label="موظف العهدة"
                  required
                  options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
                  value={form.petty_cash_employee_id}
                  onChange={(e) => setForm({ ...form, petty_cash_employee_id: e.target.value })}
                />
              ) : null}
            </>
          )}
          <NumberField label="الكمية" required value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
          <MoneyField label="تكلفة العمالة" value={form.labor_cost} onChange={(e) => setForm({ ...form, labor_cost: e.target.value })} />
          <TextField label="وصف البند" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
