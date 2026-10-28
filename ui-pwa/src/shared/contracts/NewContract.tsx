import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, SelectField, NumberField, MoneyField, TextAreaField, useToast,
} from '@/shared/ui'
import { NewContractContext } from './newContractContext'

type Customer = { id: number; full_name: string; phone: string }
type Vehicle = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number }

const emptyForm = {
  customer_id: '', vehicle_id: '', starts_at: '', planned_ends_at: '',
  daily_rate: '', deposit: '', min_days: '', notes: '',
}
const emptyCustomer = { full_name: '', phone: '', birth_date: '1990-01-01', license_number: '', license_expires_on: '2030-01-01' }

export function NewContractProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [createdVersion, setCreatedVersion] = useState(0)
  const [customers, setCustomers] = useState<Customer[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [inlineCustomer, setInlineCustomer] = useState(false)
  const [newCustomer, setNewCustomer] = useState(emptyCustomer)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const openNewContract = useCallback(() => {
    setOpen(true)
    Promise.all([
      api<{ data: Customer[] }>('/customers?per_page=100'),
      api<{ data: Vehicle[] }>('/vehicles?per_page=100'),
    ])
      .then(([c, v]) => {
        setCustomers(c.data)
        setVehicles(v.data)
      })
      .catch((e) => toast.error((e as ApiError).message))
  }, [toast])

  function close() {
    setOpen(false)
  }

  async function addInlineCustomer() {
    try {
      const res = await api<{ data: Customer; message: string }>('/customers', {
        method: 'POST',
        json: newCustomer,
      })
      setCustomers((prev) => [res.data, ...prev])
      setForm((f) => ({ ...f, customer_id: String(res.data.id) }))
      setInlineCustomer(false)
      setNewCustomer(emptyCustomer)
      toast.success('تم تسجيل العميل وربطه بالحجز.')
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function onSave() {
    setSaving(true)
    setErrors({})
    try {
      await api('/contracts', {
        method: 'POST',
        json: {
          customer_id: Number(form.customer_id),
          vehicle_id: Number(form.vehicle_id),
          starts_at: form.starts_at,
          planned_ends_at: form.planned_ends_at,
          daily_rate: form.daily_rate ? Number(form.daily_rate) : undefined,
          deposit: form.deposit ? Number(form.deposit) : undefined,
          min_days: form.min_days ? Number(form.min_days) : undefined,
          notes: form.notes || undefined,
        },
      })
      toast.success('تم إنشاء العقد.')
      setForm(emptyForm)
      setOpen(false)
      setCreatedVersion((v) => v + 1)
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

  const value = useMemo(() => ({ openNewContract, createdVersion }), [openNewContract, createdVersion])

  return (
    <NewContractContext.Provider value={value}>
      {children}
      <Sheet
        open={open}
        title="إضافة حجز / عقد"
        onClose={close}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>حفظ</Button>
            <Button variant="secondary" onClick={close}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <div className="rpm-inline-create">
            <SelectField
              label="العميل"
              required
              options={customers.map((c) => ({ value: String(c.id), label: `${c.full_name} · ${c.phone}` }))}
              value={form.customer_id}
              error={errors.customer_id}
              onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
            />
            <Button type="button" variant="secondary" size="sm" onClick={() => setInlineCustomer((v) => !v)}>
              + إضافة من هنا
            </Button>
          </div>

          {inlineCustomer ? (
            <div className="rpm-inline-box">
              <p className="rpm-inline-box__title">تسجيل عميل سريع</p>
              <TextField label="الاسم الكامل" required value={newCustomer.full_name} onChange={(e) => setNewCustomer({ ...newCustomer, full_name: e.target.value })} />
              <TextField label="الهاتف" required value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
              <TextField label="رقم الرخصة" required value={newCustomer.license_number} onChange={(e) => setNewCustomer({ ...newCustomer, license_number: e.target.value })} />
              <Button type="button" size="sm" onClick={addInlineCustomer}>حفظ واختيار</Button>
            </div>
          ) : null}

          <SelectField
            label="السيارة"
            required
            options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={form.vehicle_id}
            error={errors.vehicle_id}
            onChange={(e) => {
              const id = e.target.value
              const v = vehicles.find((x) => String(x.id) === id)
              setForm({
                ...form,
                vehicle_id: id,
                daily_rate: v ? String(v.daily_rate) : form.daily_rate,
                deposit: v ? String(v.default_deposit) : form.deposit,
              })
            }}
          />
          <TextField label="وقت البداية" required type="datetime-local" value={form.starts_at} error={errors.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
          <TextField label="وقت النهاية المخطط" required type="datetime-local" value={form.planned_ends_at} error={errors.planned_ends_at} onChange={(e) => setForm({ ...form, planned_ends_at: e.target.value })} />
          <MoneyField label="السعر اليومي" value={form.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
          <NumberField label="الحد الأدنى للأيام" value={form.min_days} onChange={(e) => setForm({ ...form, min_days: e.target.value })} />
          <MoneyField label="التأمين النقدي المتفق" value={form.deposit} onChange={(e) => setForm({ ...form, deposit: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} hint="نص حر فقط — لا ينشئ قواعد مالية" />
        </div>
      </Sheet>
    </NewContractContext.Provider>
  )
}
