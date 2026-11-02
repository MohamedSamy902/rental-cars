import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField,
  PlateField, useToast,
} from '@/shared/ui'
import { BrandSelectField } from '@/shared/forms/BrandSelectField'

type OwnerOption = { id: number; full_name: string; phone?: string | null }
type Brand = { id: number; name: string }
type Addon = { id: number; name: string; category?: string | null; default_qty: number }

export type VehicleFormValues = {
  code: string
  plate_letters: string
  plate_numbers: string
  brand: string
  model: string
  year: string
  category: string
  fuel_type: string
  color: string
  transmission: string
  chassis_number: string
  engine_number: string
  engine_capacity: string
  seats: string
  odometer: string
  daily_rate: string
  default_deposit: string
  target_monthly_income: string
  tracker_device_number: string
  tracker_sim_number: string
  notes: string
  is_active: boolean
  owner_id: string
  owner_monthly_amount: string
  owner_mode: 'none' | 'existing' | 'new'
  owner_full_name: string
  owner_phone: string
  owner_national_id: string
  owner_notes: string
}

const emptyForm = (): VehicleFormValues => ({
  code: '',
  plate_letters: '',
  plate_numbers: '',
  brand: '',
  model: '',
  year: '',
  category: 'اقتصادي',
  fuel_type: 'بنزين',
  color: '',
  transmission: 'أوتوماتيك',
  chassis_number: '',
  engine_number: '',
  engine_capacity: '',
  seats: '5',
  odometer: '',
  daily_rate: '',
  default_deposit: '',
  target_monthly_income: '',
  tracker_device_number: '',
  tracker_sim_number: '',
  notes: '',
  is_active: true,
  owner_id: '',
  owner_monthly_amount: '',
  owner_mode: 'none',
  owner_full_name: '',
  owner_phone: '',
  owner_national_id: '',
  owner_notes: '',
})

/** Loose vehicle-shaped input for edit/create prefill (API numbers OK). */
export type VehicleFormInitial = {
  code?: string | null
  plate?: string | null
  plate_letters?: string | null
  plate_numbers?: string | null
  brand?: string | null
  model?: string | null
  year?: number | string | null
  category?: string | null
  fuel_type?: string | null
  color?: string | null
  transmission?: string | null
  chassis_number?: string | null
  engine_number?: string | null
  engine_capacity?: string | null
  seats?: number | string | null
  odometer?: number | string | null
  daily_rate?: number | string | null
  default_deposit?: number | string | null
  target_monthly_income?: number | string | null
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  notes?: string | null
  is_active?: boolean
  owner_id?: number | string | null
  owner_monthly_amount?: number | string | null
}

type Props = {
  open: boolean
  mode: 'create' | 'edit'
  vehicleId?: number
  /** Prefill for edit (or rare create defaults) */
  initial?: VehicleFormInitial | null
  onClose: () => void
  onSaved: (vehicleId?: number) => void
}

function splitPlate(plate?: string | null): { letters: string; numbers: string } {
  if (!plate) return { letters: '', numbers: '' }
  const nums = (plate.match(/\d+/g) || []).join('')
  const letters = plate
    .replace(/[0-9]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  return { letters, numbers: nums }
}

function toForm(initial?: VehicleFormInitial | null): VehicleFormValues {
  const base = emptyForm()
  if (!initial) return base
  const plate = initial.plate_letters != null || initial.plate_numbers != null
    ? { letters: initial.plate_letters || '', numbers: initial.plate_numbers || '' }
    : splitPlate(initial.plate)
  const ownerId = initial.owner_id != null && initial.owner_id !== '' ? String(initial.owner_id) : ''
  return {
    ...base,
    code: initial.code != null ? String(initial.code) : '',
    plate_letters: plate.letters,
    plate_numbers: plate.numbers,
    brand: initial.brand != null ? String(initial.brand) : '',
    model: initial.model != null ? String(initial.model) : '',
    year: initial.year != null && initial.year !== '' ? String(initial.year) : '',
    category: initial.category || base.category,
    fuel_type: initial.fuel_type || base.fuel_type,
    color: initial.color != null ? String(initial.color) : '',
    transmission: initial.transmission || base.transmission,
    chassis_number: initial.chassis_number != null ? String(initial.chassis_number) : '',
    engine_number: initial.engine_number != null ? String(initial.engine_number) : '',
    engine_capacity: initial.engine_capacity != null ? String(initial.engine_capacity) : '',
    seats: initial.seats != null && initial.seats !== '' ? String(initial.seats) : base.seats,
    odometer: initial.odometer != null && initial.odometer !== '' ? String(initial.odometer) : '',
    daily_rate: initial.daily_rate != null && initial.daily_rate !== '' ? String(initial.daily_rate) : '',
    default_deposit: initial.default_deposit != null && initial.default_deposit !== '' ? String(initial.default_deposit) : '',
    target_monthly_income: initial.target_monthly_income != null && initial.target_monthly_income !== ''
      ? String(initial.target_monthly_income) : '',
    tracker_device_number: initial.tracker_device_number != null ? String(initial.tracker_device_number) : '',
    tracker_sim_number: initial.tracker_sim_number != null ? String(initial.tracker_sim_number) : '',
    notes: initial.notes != null ? String(initial.notes) : '',
    is_active: initial.is_active !== false,
    owner_id: ownerId,
    owner_monthly_amount: initial.owner_monthly_amount != null && initial.owner_monthly_amount !== ''
      ? String(initial.owner_monthly_amount) : '',
    owner_mode: ownerId ? 'existing' : 'none',
    owner_full_name: '',
    owner_phone: '',
    owner_national_id: '',
    owner_notes: '',
  }
}

function buildPayload(form: VehicleFormValues, mode: 'create' | 'edit', selectedAddons: number[]) {
  const payload: Record<string, unknown> = {
    plate_letters: form.plate_letters,
    plate_numbers: form.plate_numbers,
    brand: form.brand,
    model: form.model,
    year: Number(form.year),
    category: form.category,
    fuel_type: form.fuel_type,
    odometer: Number(form.odometer),
    daily_rate: Number(form.daily_rate),
    default_deposit: Number(form.default_deposit),
    seats: form.seats ? Number(form.seats) : null,
    target_monthly_income: form.target_monthly_income !== '' ? Number(form.target_monthly_income) : null,
    color: form.color || null,
    transmission: form.transmission || null,
    chassis_number: form.chassis_number || null,
    engine_number: form.engine_number || null,
    engine_capacity: form.engine_capacity || null,
    tracker_device_number: form.tracker_device_number || null,
    tracker_sim_number: form.tracker_sim_number || null,
    notes: form.notes || null,
    is_active: form.is_active,
  }

  if (form.owner_mode === 'existing' && form.owner_id) {
    payload.owner_id = Number(form.owner_id)
    payload.owner_monthly_amount = form.owner_monthly_amount !== '' ? Number(form.owner_monthly_amount) : null
  } else if (form.owner_mode === 'new' && mode === 'create') {
    payload.owner_inline = {
      full_name: form.owner_full_name,
      phone: form.owner_phone || null,
      national_id: form.owner_national_id || null,
      notes: form.owner_notes || null,
    }
    payload.owner_monthly_amount = form.owner_monthly_amount !== '' ? Number(form.owner_monthly_amount) : null
  } else if (mode === 'edit') {
    payload.owner_id = form.owner_id ? Number(form.owner_id) : null
    payload.owner_monthly_amount = form.owner_id && form.owner_monthly_amount !== ''
      ? Number(form.owner_monthly_amount)
      : null
  }

  if (mode === 'create') {
    payload.addon_ids = selectedAddons
  }

  return payload
}

/**
 * Single vehicle form for create + edit (edit layout is the source of truth).
 */
export function VehicleFormSheet({ open, mode, vehicleId, initial, onClose, onSaved }: Props) {
  const toast = useToast()
  const [form, setForm] = useState<VehicleFormValues>(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [brands, setBrands] = useState<Brand[]>([])
  const [addons, setAddons] = useState<Addon[]>([])
  const [selectedAddons, setSelectedAddons] = useState<number[]>([])
  const [owners, setOwners] = useState<OwnerOption[]>([])
  const [nextCode, setNextCode] = useState('—')

  useEffect(() => {
    if (!open) return
    const seeded = toForm(initial)
    setForm(seeded)
    setErrors({})
    setSelectedAddons([])
    void api<{ data: { brands: Brand[]; addons: Addon[]; next_code: string } }>('/vehicles/catalogs')
      .then((res) => {
        const brandList = res.data.brands || []
        setBrands(brandList)
        setAddons(res.data.addons || [])
        setNextCode(res.data.next_code || '—')
        if (!seeded.brand && mode === 'create' && brandList[0]) {
          setForm((f) => (f.brand ? f : { ...f, brand: brandList[0].name }))
        }
      })
      .catch(() => {
        setBrands([])
        setAddons([])
      })
    void api<{ data: OwnerOption[] }>('/owners?active_only=1&per_page=100')
      .then((res) => setOwners(res.data))
      .catch(() => setOwners([]))
    // Seed once when the sheet opens — avoid resetting mid-edit when parent re-renders.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mode, vehicleId])

  function toggleAddon(id: number) {
    setSelectedAddons((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  async function save() {
    if (mode === 'edit' && (!vehicleId || vehicleId <= 0)) {
      toast.error('معرّف العربية غير صالح.')
      return
    }
    if (!form.brand.trim()) {
      setErrors((e) => ({ ...e, brand: 'الماركة مطلوبة.' }))
      toast.error('اختر الماركة أو أضف ماركة جديدة.')
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const payload = buildPayload(form, mode, selectedAddons)
      if (mode === 'create') {
        const res = await api<{ data: { id: number } }>('/vehicles', { method: 'POST', json: payload })
        toast.success('تم إضافة العربية.')
        onClose()
        onSaved(res.data?.id)
      } else {
        await api(`/vehicles/${vehicleId}`, { method: 'PUT', json: payload })
        toast.success('تم تحديث العربية.')
        onClose()
        onSaved(vehicleId)
      }
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

  const codeValue = mode === 'create' ? nextCode : (form.code || '—')
  const showOwnerMonthly = form.owner_mode === 'existing'
    ? !!form.owner_id
    : form.owner_mode === 'new'

  return (
    <Sheet
      open={open}
      title={mode === 'create' ? 'إضافة عربية' : 'تعديل العربية'}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>
            {mode === 'create' ? 'حفظ العربية' : 'حفظ التعديل'}
          </Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        <TextField
          label="الكود الداخلي"
          value={codeValue}
          disabled
          hint={mode === 'create' ? 'يُولَّد تلقائياً بالتسلسل عند الحفظ' : 'يُدار تلقائياً ولا يُعدَّل يدوياً'}
        />
        <PlateField
          required
          letters={form.plate_letters}
          numbers={form.plate_numbers}
          error={errors.plate || errors.plate_letters || errors.plate_numbers}
          onLettersChange={(plate_letters) => setForm({ ...form, plate_letters })}
          onNumbersChange={(plate_numbers) => setForm({ ...form, plate_numbers })}
        />
        <BrandSelectField
          brands={brands}
          value={form.brand}
          required
          error={errors.brand}
          onChange={(brand) => setForm({ ...form, brand })}
          onBrandsChange={setBrands}
        />
        <TextField label="الموديل" required value={form.model} error={errors.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
        <NumberField label="سنة الصنع" required value={form.year} error={errors.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
        <SelectField
          label="الفئة"
          required
          options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'دفع رباعي', label: 'دفع رباعي' }, { value: 'فاخر', label: 'فاخر' }]}
          value={form.category}
          onChange={(e) => setForm({ ...form, category: e.target.value })}
        />
        <SelectField
          label="نوع الوقود"
          required
          options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]}
          value={form.fuel_type}
          onChange={(e) => setForm({ ...form, fuel_type: e.target.value })}
        />
        <TextField label="اللون" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
        <SelectField
          label="ناقل الحركة"
          options={[{ value: 'أوتوماتيك', label: 'أوتوماتيك' }, { value: 'مانيوال', label: 'مانيوال' }]}
          value={form.transmission}
          onChange={(e) => setForm({ ...form, transmission: e.target.value })}
        />
        <NumberField label="عدد المقاعد" value={form.seats} onChange={(e) => setForm({ ...form, seats: e.target.value })} />
        <TextField label="رقم الشاسيه" value={form.chassis_number} error={errors.chassis_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, chassis_number: e.target.value })} />
        <TextField label="رقم المحرك" value={form.engine_number} error={errors.engine_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, engine_number: e.target.value })} />
        <TextField label="سعة المحرك" value={form.engine_capacity} onChange={(e) => setForm({ ...form, engine_capacity: e.target.value })} />
        <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
        <MoneyField label="الإيجار اليومي" required value={form.daily_rate} error={errors.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
        <MoneyField label="التأمين النقدي" required value={form.default_deposit} error={errors.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
        <MoneyField label="المستهدف الشهري" value={form.target_monthly_income} onChange={(e) => setForm({ ...form, target_monthly_income: e.target.value })} />
        <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
        <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />

        {mode === 'create' ? (
          <SelectField
            label="المالك"
            value={form.owner_mode}
            onChange={(e) => setForm({
              ...form,
              owner_mode: e.target.value as VehicleFormValues['owner_mode'],
              owner_id: '',
            })}
            options={[
              { value: 'none', label: '— ملك المركز —' },
              { value: 'existing', label: 'مالك مسجّل' },
              { value: 'new', label: 'إنشاء مالك جديد من هنا' },
            ]}
          />
        ) : (
          <SelectField
            label="المالك"
            value={form.owner_id}
            onChange={(e) => setForm({
              ...form,
              owner_id: e.target.value,
              owner_mode: e.target.value ? 'existing' : 'none',
            })}
            options={[
              { value: '', label: '— ملك المركز —' },
              ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
            ]}
          />
        )}

        {mode === 'create' && form.owner_mode === 'existing' ? (
          <SelectField
            label="اختيار المالك"
            required
            value={form.owner_id}
            error={errors.owner_id}
            options={[
              { value: '', label: 'اختر…' },
              ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
            ]}
            onChange={(e) => setForm({ ...form, owner_id: e.target.value })}
          />
        ) : null}

        {mode === 'create' && form.owner_mode === 'new' ? (
          <>
            <TextField label="اسم المالك" required value={form.owner_full_name} error={errors['owner_inline.full_name']} onChange={(e) => setForm({ ...form, owner_full_name: e.target.value })} />
            <TextField label="هاتف المالك" value={form.owner_phone} onChange={(e) => setForm({ ...form, owner_phone: e.target.value })} />
            <TextField label="الرقم القومي" value={form.owner_national_id} onChange={(e) => setForm({ ...form, owner_national_id: e.target.value })} />
            <TextAreaField label="ملاحظات المالك" value={form.owner_notes} onChange={(e) => setForm({ ...form, owner_notes: e.target.value })} />
          </>
        ) : null}

        {showOwnerMonthly ? (
          <MoneyField
            label="المبلغ الشهري للمالك"
            required={mode === 'edit' || form.owner_mode !== 'none'}
            value={form.owner_monthly_amount}
            error={errors.owner_monthly_amount}
            onChange={(e) => setForm({ ...form, owner_monthly_amount: e.target.value })}
          />
        ) : null}

        {mode === 'create' ? (
          <div className="rpm-field" style={{ gridColumn: '1 / -1' }}>
            <span className="rpm-field__label">بيانات الإضافة</span>
            <span className="rpm-field__hint">اختر المستلزمات من القائمة الرئيسية لإضافتها مع العربية</span>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginBlockStart: '0.5rem' }}>
              {addons.map((a) => {
                const on = selectedAddons.includes(a.id)
                return (
                  <Button
                    key={a.id}
                    type="button"
                    size="sm"
                    variant={on ? 'accent' : 'secondary'}
                    onClick={() => toggleAddon(a.id)}
                  >
                    {a.name}
                  </Button>
                )
              })}
              {!addons.length ? <span className="muted">لا توجد بيانات إضافة بعد</span> : null}
            </div>
          </div>
        ) : null}

        <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
      </div>
    </Sheet>
  )
}
