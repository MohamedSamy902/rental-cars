import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, MoneyField, SelectField, TextAreaField, DateField, SwitchField, useToast,
} from '@/shared/ui'

export type ExpenseEditInitial = {
  id: number
  title: string
  category?: string | null
  amount: number
  spent_on: string
  payment_method?: string | null
  notes?: string | null
  vehicle_id?: number | null
  work_order_id?: number | null
  employee_id?: number | null
  from_petty_cash?: boolean
  scope?: 'vehicle' | 'general' | string | null
  recorded_by_name?: string | null
  vehicle?: { id: number; brand?: string; model?: string; plate?: string } | null
}

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Pre-links the expense to a vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string }
  /** Lock create flow to a custody employee (petty-cash detail quick action) */
  custodyEmployee?: { id: number; name?: string }
  /** When set, sheet works in edit mode */
  initial?: ExpenseEditInitial | null
}

type VehicleOption = { id: number; brand: string; model: string; plate: string }
type WorkOrderOption = { id: number; workshop?: string | null; status: string; opened_on?: string | null }
type EmployeeOption = { id: number; name: string }

const today = () => new Date().toISOString().slice(0, 10)
const emptyForm = () => ({
  title: '',
  category: 'تشغيل',
  scope: 'general' as 'general' | 'vehicle',
  amount: '',
  spent_on: today(),
  payment_method: 'cash',
  notes: '',
  vehicle_id: '',
  work_order_id: '',
  employee_id: '',
  from_petty_cash: false,
})

function spentOnValue(raw: string | undefined): string {
  if (!raw) return today()
  return raw.slice(0, 10)
}

export function ExpenseSheet({ open, onClose, onSaved, vehicle, custodyEmployee, initial }: Props) {
  const toast = useToast()
  const editing = Boolean(initial?.id)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [workOrders, setWorkOrders] = useState<WorkOrderOption[]>([])
  const [employees, setEmployees] = useState<EmployeeOption[]>([])

  useEffect(() => {
    if (!open) return
    if (initial) {
      const hasVehicle = Boolean(initial.vehicle_id || vehicle?.id)
      setForm({
        title: initial.title || '',
        category: initial.category || 'تشغيل',
        scope: hasVehicle || initial.scope === 'vehicle' ? 'vehicle' : 'general',
        amount: String(initial.amount ?? ''),
        spent_on: spentOnValue(initial.spent_on),
        payment_method: initial.from_petty_cash ? 'cash' : (initial.payment_method || 'cash'),
        notes: initial.notes || '',
        vehicle_id: initial.vehicle_id ? String(initial.vehicle_id) : (vehicle?.id ? String(vehicle.id) : ''),
        work_order_id: initial.work_order_id ? String(initial.work_order_id) : '',
        employee_id: initial.employee_id ? String(initial.employee_id) : '',
        from_petty_cash: Boolean(initial.from_petty_cash),
      })
    } else {
      setForm({
        ...emptyForm(),
        scope: vehicle?.id ? 'vehicle' : 'general',
        vehicle_id: vehicle?.id ? String(vehicle.id) : '',
        from_petty_cash: Boolean(custodyEmployee?.id),
        employee_id: custodyEmployee?.id ? String(custodyEmployee.id) : '',
      })
    }
    setErrors({})
    void api<{ data: VehicleOption[] }>('/vehicles?per_page=100')
      .then((r) => setVehicles(r.data))
      .catch(() => setVehicles([]))
    void api<{ data: EmployeeOption[] }>('/employees/lookup')
      .then((r) => setEmployees(r.data || []))
      .catch(() => setEmployees([]))
  }, [open, vehicle?.id, custodyEmployee?.id, initial])

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
      const linkedVehicle = form.scope === 'vehicle'
        ? (form.vehicle_id ? Number(form.vehicle_id) : vehicle?.id)
        : undefined
      const payload = {
        title: form.title,
        category: form.category,
        amount: Number(form.amount),
        spent_on: form.spent_on,
        payment_method: form.from_petty_cash ? 'petty_cash' : form.payment_method,
        notes: form.notes || undefined,
        vehicle_id: linkedVehicle || null,
        work_order_id: form.category === 'صيانة' && form.work_order_id ? Number(form.work_order_id) : undefined,
        from_petty_cash: form.from_petty_cash,
        employee_id: form.from_petty_cash && form.employee_id ? Number(form.employee_id) : undefined,
      }
      if (editing && initial) {
        await api(`/expenses/${initial.id}`, { method: 'PUT', json: payload })
        toast.success('تم تحديث المصروف.')
      } else {
        await api('/expenses', { method: 'POST', json: payload })
        toast.success('تم تسجيل المصروف.')
      }
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

  const title = editing
    ? 'تعديل المصروف'
    : vehicle
      ? `مصروف · ${vehicle.label}`
      : 'تسجيل مصروف'

  return (
    <Sheet
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>
            {editing ? 'حفظ التعديلات' : 'حفظ المصروف'}
          </Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        <TextField label="العنوان" required value={form.title} error={errors.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <MoneyField label="المبلغ" required value={form.amount} error={errors.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        {!vehicle ? (
          <SelectField
            label="نوع المصروف"
            value={form.scope}
            onChange={(e) => {
              const scope = e.target.value as 'general' | 'vehicle'
              setForm({
                ...form,
                scope,
                vehicle_id: scope === 'general' ? '' : form.vehicle_id,
                work_order_id: '',
                category: scope === 'general' && form.category === 'صيانة' ? 'تشغيل' : form.category,
              })
            }}
            options={[
              { value: 'general', label: 'مصروف عام / فرع' },
              { value: 'vehicle', label: 'مرتبط بعربية' },
            ]}
            hint="المصروف العام يُسجَّل على الفرع دون ربط بعربية"
          />
        ) : null}
        <SelectField
          label="التصنيف"
          value={form.category}
          onChange={(e) => setForm({ ...form, category: e.target.value, work_order_id: '' })}
          options={[
            { value: 'تشغيل', label: 'تشغيل' },
            ...(form.scope === 'vehicle' || vehicle ? [{ value: 'صيانة', label: 'صيانة' }] : []),
            { value: 'فرع', label: 'فرع / موقع' },
            { value: 'أخرى', label: 'أخرى' },
          ]}
        />
        <DateField label="تاريخ الصرف" required value={form.spent_on} error={errors.spent_on} onChange={(e) => setForm({ ...form, spent_on: e.target.value })} />
        {(form.scope === 'vehicle' || vehicle) && !vehicle ? (
          <SelectField
            label="العربية"
            required={form.category === 'صيانة'}
            value={form.vehicle_id}
            error={errors.vehicle_id}
            options={[
              { value: '', label: form.category === 'صيانة' ? 'اختر عربية…' : '— اختر عربية —' },
              ...vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` })),
            ]}
            onChange={(e) => setForm({ ...form, vehicle_id: e.target.value, work_order_id: '' })}
          />
        ) : null}
        {form.category === 'صيانة' ? (
          <SelectField
            label="أمر الصيانة المرتبط"
            value={form.work_order_id}
            error={errors.work_order_id}
            hint="يربط المصروف بسجل صيانة العربية محاسبياً"
            options={[
              { value: '', label: '— بدون أمر صيانة —' },
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
            label="موظف العهدة (من دفع)"
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
        {editing && initial?.recorded_by_name ? (
          <TextField label="سجّله" value={initial.recorded_by_name} disabled onChange={() => undefined} />
        ) : null}
        <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      </div>
    </Sheet>
  )
}
