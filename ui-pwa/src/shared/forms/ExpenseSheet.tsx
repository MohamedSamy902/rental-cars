import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, MoneyField, SelectField, TextAreaField, DateField, SwitchField, useToast,
} from '@/shared/ui'

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Pre-links the expense to a vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string }
}

type VehicleOption = { id: number; brand: string; model: string; plate: string }
type WorkOrderOption = { id: number; workshop?: string | null; status: string; opened_on?: string | null }
type EmployeeOption = { id: number; name: string }

const today = () => new Date().toISOString().slice(0, 10)
const emptyForm = () => ({
  title: '',
  category: 'تشغيل',
  amount: '',
  spent_on: today(),
  payment_method: 'cash',
  notes: '',
  vehicle_id: '',
  work_order_id: '',
  employee_id: '',
  from_petty_cash: false,
})

export function ExpenseSheet({ open, onClose, onSaved, vehicle }: Props) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [workOrders, setWorkOrders] = useState<WorkOrderOption[]>([])
  const [employees, setEmployees] = useState<EmployeeOption[]>([])

  useEffect(() => {
    if (!open) return
    setForm({
      ...emptyForm(),
      vehicle_id: vehicle?.id ? String(vehicle.id) : '',
    })
    setErrors({})
    void api<{ data: VehicleOption[] }>('/vehicles?per_page=100')
      .then((r) => setVehicles(r.data))
      .catch(() => setVehicles([]))
    void api<{ data: EmployeeOption[] }>('/employees/lookup')
      .then((r) => setEmployees(r.data || []))
      .catch(() => setEmployees([]))
  }, [open, vehicle?.id])

  useEffect(() => {
    if (!open || form.category !== 'صيانة' || !form.vehicle_id) {
      setWorkOrders([])
      return
    }
    void api<{ data: WorkOrderOption[] }>(`/work-orders?vehicle_id=${form.vehicle_id}&per_page=50`)
      .then((r) => setWorkOrders(r.data || []))
      .catch(() => setWorkOrders([]))
  }, [open, form.category, form.vehicle_id])

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await api('/expenses', {
        method: 'POST',
        json: {
          title: form.title,
          category: form.category,
          amount: Number(form.amount),
          spent_on: form.spent_on,
          payment_method: form.from_petty_cash ? 'petty_cash' : form.payment_method,
          notes: form.notes || undefined,
          vehicle_id: form.vehicle_id ? Number(form.vehicle_id) : vehicle?.id,
          work_order_id: form.category === 'صيانة' && form.work_order_id ? Number(form.work_order_id) : undefined,
          from_petty_cash: form.from_petty_cash,
          employee_id: form.from_petty_cash && form.employee_id ? Number(form.employee_id) : undefined,
        },
      })
      toast.success('تم تسجيل المصروف.')
      onClose()
      onSaved?.()
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Sheet
      open={open}
      title={vehicle ? `مصروف · ${vehicle.label}` : 'تسجيل مصروف'}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>حفظ المصروف</Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        <TextField label="العنوان" required value={form.title} error={errors.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <MoneyField label="المبلغ" required value={form.amount} error={errors.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        <SelectField
          label="التصنيف"
          value={form.category}
          onChange={(e) => setForm({ ...form, category: e.target.value, work_order_id: '' })}
          options={[{ value: 'تشغيل', label: 'تشغيل' }, { value: 'صيانة', label: 'صيانة' }, { value: 'أخرى', label: 'أخرى' }]}
        />
        <DateField label="التاريخ" required value={form.spent_on} error={errors.spent_on} onChange={(e) => setForm({ ...form, spent_on: e.target.value })} />
        {!vehicle ? (
          <SelectField
            label="المركبة"
            required={form.category === 'صيانة'}
            value={form.vehicle_id}
            error={errors.vehicle_id}
            options={[
              { value: '', label: form.category === 'صيانة' ? 'اختر مركبة…' : '— بدون مركبة —' },
              ...vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` })),
            ]}
            onChange={(e) => setForm({ ...form, vehicle_id: e.target.value, work_order_id: '' })}
          />
        ) : null}
        {form.category === 'صيانة' ? (
          <SelectField
            label="أمر الشغل المرتبط"
            value={form.work_order_id}
            error={errors.work_order_id}
            hint="يربط المصروف بسجل صيانة المركبة محاسبياً"
            options={[
              { value: '', label: '— بدون أمر شغل —' },
              ...workOrders.map((w) => ({
                value: String(w.id),
                label: `#${w.id} · ${w.status}${w.opened_on ? ` · ${w.opened_on}` : ''}${w.workshop ? ` · ${w.workshop}` : ''}`,
              })),
            ]}
            onChange={(e) => setForm({ ...form, work_order_id: e.target.value })}
          />
        ) : null}
        <SwitchField
          label="الصرف من عهدة موظف"
          checked={form.from_petty_cash}
          onChange={(from_petty_cash) => setForm({ ...form, from_petty_cash })}
        />
        {form.from_petty_cash ? (
          <SelectField
            label="موظف العهدة"
            required
            value={form.employee_id}
            error={errors.employee_id}
            options={[
              { value: '', label: 'اختر الموظف…' },
              ...employees.map((e) => ({ value: String(e.id), label: e.name })),
            ]}
            onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
          />
        ) : (
          <SelectField
            label="طريقة الدفع"
            value={form.payment_method}
            onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
            options={[{ value: 'cash', label: 'نقدي' }, { value: 'transfer', label: 'تحويل' }, { value: 'card', label: 'بطاقة' }]}
          />
        )}
        <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      </div>
    </Sheet>
  )
}
