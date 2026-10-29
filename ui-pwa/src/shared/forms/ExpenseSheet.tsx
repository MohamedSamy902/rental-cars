import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, MoneyField, SelectField, TextAreaField, SwitchField, useToast,
} from '@/shared/ui'

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Pre-links the expense to a vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string }
}

type EmployeeOption = { id: number; name: string }

const today = () => new Date().toISOString().slice(0, 10)
const emptyForm = () => ({
  title: '', category: 'تشغيل', amount: '', spent_on: today(), payment_method: 'cash', notes: '',
  from_petty_cash: false, employee_id: '',
})

export function ExpenseSheet({ open, onClose, onSaved, vehicle }: Props) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [employees, setEmployees] = useState<EmployeeOption[]>([])

  useEffect(() => {
    if (!open) return
    setForm(emptyForm())
    setErrors({})
    api<{ data: EmployeeOption[] }>('/employees')
      .then((r) => setEmployees(r.data || []))
      .catch(() => setEmployees([]))
  }, [open])

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
          vehicle_id: vehicle?.id,
          from_petty_cash: form.from_petty_cash,
          employee_id: form.from_petty_cash ? Number(form.employee_id) : undefined,
        },
      })
      toast.success(form.from_petty_cash ? 'تم تسجيل المصروف وخصمه من العهدة.' : 'تم تسجيل المصروف.')
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
          onChange={(e) => setForm({ ...form, category: e.target.value })}
          options={[{ value: 'تشغيل', label: 'تشغيل' }, { value: 'صيانة', label: 'صيانة' }, { value: 'أخرى', label: 'أخرى' }]}
        />
        <TextField label="التاريخ" type="date" required value={form.spent_on} error={errors.spent_on} onChange={(e) => setForm({ ...form, spent_on: e.target.value })} />
        <SwitchField
          label="الخصم من عهدة موظف"
          checked={form.from_petty_cash}
          onChange={(v) => setForm({ ...form, from_petty_cash: v })}
        />
        {form.from_petty_cash ? (
          <SelectField
            label="موظف العهدة"
            required
            value={form.employee_id}
            error={errors.employee_id}
            onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
            options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
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
