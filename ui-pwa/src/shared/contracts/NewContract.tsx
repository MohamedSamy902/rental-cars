import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, SelectField, MoneyField, TextAreaField, DateTimeField, useToast,
} from '@/shared/ui'
import { NewContractContext, type NewContractOptions } from './newContractContext'

type Customer = { id: number; full_name: string; phone: string }
type Vehicle = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number }
type RankedVehicle = Vehicle & {
  category?: string
  rental_priority?: number
  scores?: { underuse: number; availability: number; manual: number; display: number }
}

const emptyForm = {
  customer_id: '', vehicle_id: '', starts_at: '', planned_ends_at: '',
  daily_rate: '', deposit: '', notes: '',
}
const emptyCustomer = { full_name: '', phone: '', birth_date: '1990-01-01', license_number: '', license_expires_on: '2030-01-01' }

function toQueryIso(local: string): string {
  if (!local) return ''
  // datetime from DateTimeField is YYYY-MM-DDTHH:mm — append seconds for API
  return local.length === 16 ? `${local}:00` : local
}

export function NewContractProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [createdVersion, setCreatedVersion] = useState(0)
  const [customers, setCustomers] = useState<Customer[]>([])
  const [vehicles, setVehicles] = useState<RankedVehicle[]>([])
  const [weights, setWeights] = useState<{ underuse: number; availability: number; manual: number } | null>(null)
  const [inlineCustomer, setInlineCustomer] = useState(false)
  const [newCustomer, setNewCustomer] = useState(emptyCustomer)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [lockedVehicleId, setLockedVehicleId] = useState<number | undefined>()

  const loadVehicles = useCallback(async (starts?: string, ends?: string, preferredId?: number) => {
    const params = new URLSearchParams()
    if (starts) params.set('from', toQueryIso(starts))
    if (ends) params.set('to', toQueryIso(ends))
    const qs = params.toString()
    const path = qs ? `/vehicles/recommend?${qs}` : '/vehicles/recommend'
    try {
      const v = await api<{ data: RankedVehicle[]; weights?: { underuse: number; availability: number; manual: number } }>(path)
      setVehicles(v.data)
      setWeights(v.weights ?? null)
      if (preferredId && !v.data.some((x) => x.id === preferredId)) {
        // Locked vehicle from dossier may be busy in the chosen window — keep it listed for clarity.
        const fallback = await api<{ data: Vehicle[] }>('/vehicles?per_page=100').catch(() => ({ data: [] as Vehicle[] }))
        const found = fallback.data.find((x) => x.id === preferredId)
        if (found) setVehicles((prev) => [{ ...found }, ...prev.filter((p) => p.id !== found.id)])
      }
      return v.data
    } catch {
      const fallback = await api<{ data: Vehicle[] }>('/vehicles?per_page=100')
      setVehicles(fallback.data as RankedVehicle[])
      setWeights(null)
      return fallback.data as RankedVehicle[]
    }
  }, [])

  const openNewContract = useCallback((opts?: NewContractOptions) => {
    setOpen(true)
    setInlineCustomer(false)
    setErrors({})
    setLockedVehicleId(opts?.vehicleId)
    setForm({
      ...emptyForm,
      vehicle_id: opts?.vehicleId ? String(opts.vehicleId) : '',
    })
    void Promise.all([
      api<{ data: Customer[] }>('/customers?per_page=100'),
      loadVehicles(undefined, undefined, opts?.vehicleId),
    ])
      .then(([c, v]) => {
        setCustomers(c.data)
        if (opts?.vehicleId) {
          const picked = v.find((x) => x.id === opts.vehicleId)
          if (picked) {
            setForm((f) => ({
              ...f,
              vehicle_id: String(picked.id),
              daily_rate: String(picked.daily_rate),
              deposit: String(picked.default_deposit),
            }))
          }
        }
      })
      .catch((e) => toast.error((e as ApiError).message))
  }, [loadVehicles, toast])

  // Re-filter available fleet when the booking window changes.
  useEffect(() => {
    if (!open) return
    if (!form.starts_at || !form.planned_ends_at) return
    const handle = window.setTimeout(() => {
      void loadVehicles(form.starts_at, form.planned_ends_at, lockedVehicleId).then((list) => {
        if (form.vehicle_id && !list.some((v) => String(v.id) === form.vehicle_id) && Number(form.vehicle_id) !== lockedVehicleId) {
          setForm((f) => ({ ...f, vehicle_id: '', daily_rate: '', deposit: '' }))
          toast.info('المركبة المختارة غير متاحة في هذه الفترة — اختر مركبة أخرى.')
        }
      })
    }, 350)
    return () => window.clearTimeout(handle)
  }, [open, form.starts_at, form.planned_ends_at, form.vehicle_id, loadVehicles, lockedVehicleId, toast])

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
          starts_at: toQueryIso(form.starts_at),
          planned_ends_at: toQueryIso(form.planned_ends_at),
          daily_rate: form.daily_rate ? Number(form.daily_rate) : undefined,
          deposit: form.deposit ? Number(form.deposit) : undefined,
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
  const selected = vehicles.find((v) => String(v.id) === form.vehicle_id)

  return (
    <NewContractContext.Provider value={value}>
      {children}
      <Sheet
        open={open}
        title="إضافة حجز / عقد"
        onClose={close}
        footer={
          <>
            <Button loading={saving} onClick={() => void onSave()}>حفظ</Button>
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
              <Button type="button" size="sm" onClick={() => void addInlineCustomer()}>حفظ واختيار</Button>
            </div>
          ) : null}

          <DateTimeField
            label="بداية الحجز"
            required
            value={form.starts_at}
            error={errors.starts_at}
            onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
          />
          <DateTimeField
            label="نهاية الحجز المخططة"
            required
            value={form.planned_ends_at}
            error={errors.planned_ends_at}
            onChange={(e) => setForm({ ...form, planned_ends_at: e.target.value })}
          />

          <SelectField
            label="السيارة المتاحة في الفترة"
            required
            hint={form.starts_at && form.planned_ends_at ? 'تُستبعد المركبات المحجوزة أو المؤجرة في نفس الفترة' : 'حدد فترة الحجز أولاً لتصفية المتاح'}
            options={[
              { value: '', label: vehicles.length ? 'اختر مركبة…' : 'لا مركبات متاحة في هذه الفترة' },
              ...vehicles.map((v) => ({
                value: String(v.id),
                label: v.scores
                  ? `${v.brand} ${v.model} · ${v.plate} · درجة ${Math.round(v.scores.display)}`
                  : `${v.brand} ${v.model} · ${v.plate}`,
              })),
            ]}
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
          {selected?.scores || weights ? (
            <p className="muted" style={{ margin: 0 }}>
              {selected?.scores
                ? `درجة الظهور ${Math.round(selected.scores.display)} · قلة استخدام ${selected.scores.underuse} · توفر ${selected.scores.availability} · أولوية يدوية ${selected.scores.manual}`
                : null}
              {weights ? ` · أوزان ${weights.underuse}/${weights.availability}/${weights.manual}` : null}
            </p>
          ) : null}
          <MoneyField label="السعر اليومي" value={form.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
          <MoneyField label="التأمين النقدي المتفق" value={form.deposit} onChange={(e) => setForm({ ...form, deposit: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} hint="نص حر فقط — لا ينشئ قواعد مالية" />
        </div>
      </Sheet>
    </NewContractContext.Provider>
  )
}
