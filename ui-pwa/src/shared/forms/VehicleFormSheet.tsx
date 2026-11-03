import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { BrandSelectField } from '@/shared/forms/BrandSelectField'
import {
  Button, Sheet, TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField,
  PlateField, splitPlate, useToast,
} from '@/shared/ui'

type OwnerOption = { id: number; full_name: string; phone?: string | null }
type Brand = { id: number; name: string }
type Addon = { id: number; name: string; category?: string | null; default_qty: number }

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
  purchase_value_estimate?: number | string | null
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  notes?: string | null
  is_active?: boolean | null
  owner_id?: number | string | null
  owner_monthly_amount?: number | string | null
}

type Props = {
  open: boolean
  mode: 'create' | 'edit'
  vehicleId?: number
  initial?: VehicleFormInitial | null
  onClose: () => void
  onSaved: (vehicleId?: number) => void
}

type FormState = {
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
  purchase_value_estimate: string
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

function emptyForm(): FormState {
  return {
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
    purchase_value_estimate: '',
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
  }
}

function str(v: unknown, fallback = '') {
  if (v == null || v === '') return fallback
  return String(v)
}

function fromInitial(initial?: VehicleFormInitial | null): FormState {
  const base = emptyForm()
  if (!initial) return base
  const plate = initial.plate_letters != null || initial.plate_numbers != null
    ? { letters: str(initial.plate_letters), numbers: str(initial.plate_numbers) }
    : splitPlate(str(initial.plate))
  return {
    ...base,
    code: str(initial.code),
    plate_letters: plate.letters,
    plate_numbers: plate.numbers,
    brand: str(initial.brand),
    model: str(initial.model),
    year: str(initial.year),
    category: str(initial.category, 'اقتصادي'),
    fuel_type: str(initial.fuel_type, 'بنزين'),
    color: str(initial.color),
    transmission: str(initial.transmission, 'أوتوماتيك'),
    chassis_number: str(initial.chassis_number),
    engine_number: str(initial.engine_number),
    engine_capacity: str(initial.engine_capacity),
    seats: str(initial.seats, '5'),
    odometer: str(initial.odometer),
    daily_rate: str(initial.daily_rate),
    default_deposit: str(initial.default_deposit),
    target_monthly_income: str(initial.target_monthly_income),
    purchase_value_estimate: str(initial.purchase_value_estimate),
    tracker_device_number: str(initial.tracker_device_number),
    tracker_sim_number: str(initial.tracker_sim_number),
    notes: str(initial.notes),
    is_active: initial.is_active !== false,
    owner_id: str(initial.owner_id),
    owner_monthly_amount: str(initial.owner_monthly_amount),
    owner_mode: initial.owner_id ? 'existing' : 'none',
  }
}

/**
 * Unified create/edit vehicle sheet — same field schema both modes.
 * Create: auto code preview, addon chips, inline new owner.
 * Edit: code locked read-only; addons editable via the same catalog chips.
 */
export function VehicleFormSheet({ open, mode, vehicleId, initial, onClose, onSaved }: Props) {
  const toast = useToast()
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [nextCode, setNextCode] = useState('—')
  const [brands, setBrands] = useState<Brand[]>([])
  const [addons, setAddons] = useState<Addon[]>([])
  const [selectedAddons, setSelectedAddons] = useState<number[]>([])
  const [owners, setOwners] = useState<OwnerOption[]>([])

  useEffect(() => {
    if (!open) return
    setErrors({})
    setSelectedAddons([])
    setForm(mode === 'edit' ? fromInitial(initial) : emptyForm())
    void api<{ data: { brands: Brand[]; addons: Addon[]; next_code: string } }>('/vehicles/catalogs')
      .then((res) => {
        const brandList = res.data.brands || []
        const addonList = res.data.addons || []
        setBrands(brandList)
        setAddons(addonList)
        setNextCode(res.data.next_code || '—')
        if (mode === 'create' && brandList[0]) {
          setForm((f) => (f.brand ? f : { ...f, brand: brandList[0].name }))
        }
        if (mode === 'edit' && initial) {
          const names = new Set(
            ((initial as VehicleFormInitial & { accessories?: Array<{ name?: string | null }> }).accessories || [])
              .map((a) => (a.name || '').trim().toLowerCase())
              .filter(Boolean),
          )
          setSelectedAddons(
            addonList.filter((a) => names.has(a.name.trim().toLowerCase())).map((a) => a.id),
          )
        }
      })
      .catch(() => {
        setBrands([])
        setAddons([])
      })
    void api<{ data: OwnerOption[] }>('/owners?active_only=1&per_page=100')
      .then((res) => setOwners(res.data))
      .catch(() => setOwners([]))
  }, [open, mode, initial])

  function toggleAddon(id: number) {
    setSelectedAddons((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  function corePayload(): Record<string, unknown> {
    return {
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
      chassis_number: form.chassis_number || null,
      engine_number: form.engine_number || null,
      engine_capacity: form.engine_capacity || null,
      color: form.color || null,
      transmission: form.transmission || null,
      seats: form.seats !== '' ? Number(form.seats) : null,
      target_monthly_income: form.target_monthly_income !== '' ? Number(form.target_monthly_income) : null,
      tracker_device_number: form.tracker_device_number || null,
      tracker_sim_number: form.tracker_sim_number || null,
      notes: form.notes || null,
      is_active: form.is_active,
    }
  }

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      const payload = corePayload()

      payload.addon_ids = selectedAddons
      if (mode === 'create') {
        if (form.owner_mode === 'existing' && form.owner_id) {
          payload.owner_id = Number(form.owner_id)
          payload.owner_monthly_amount = form.owner_monthly_amount !== '' ? Number(form.owner_monthly_amount) : null
        } else if (form.owner_mode === 'new') {
          payload.owner_inline = {
            full_name: form.owner_full_name,
            phone: form.owner_phone || null,
            national_id: form.owner_national_id || null,
            notes: form.owner_notes || null,
          }
          payload.owner_monthly_amount = form.owner_monthly_amount !== '' ? Number(form.owner_monthly_amount) : null
        }
        const res = await api<{ data: { id: number } }>('/vehicles', { method: 'POST', json: payload })
        toast.success('تم إضافة العربية.')
        onClose()
        onSaved(res.data?.id)
      } else {
        if (!vehicleId) throw new Error('معرّف العربية مطلوب للتعديل.')
        payload.owner_id = form.owner_id ? Number(form.owner_id) : null
        payload.owner_monthly_amount = form.owner_id && form.owner_monthly_amount !== ''
          ? Number(form.owner_monthly_amount)
          : null
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

  const codeValue = mode === 'edit' ? (form.code || '—') : nextCode

  return (
    <Sheet
      open={open}
      title={mode === 'edit' ? 'تعديل العربية' : 'إضافة عربية جديدة'}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>
            {mode === 'edit' ? 'حفظ التعديلات' : 'حفظ العربية'}
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
          hint={mode === 'edit' ? 'يُدار تلقائياً ولا يُعدَّل يدوياً' : 'يُولَّد تلقائياً بالتسلسل عند الحفظ'}
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
        <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'دفع رباعي', label: 'دفع رباعي' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
        <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
        <TextField label="اللون" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
        <SelectField label="ناقل الحركة" options={[{ value: 'أوتوماتيك', label: 'أوتوماتيك' }, { value: 'مانيوال', label: 'مانيوال' }]} value={form.transmission} onChange={(e) => setForm({ ...form, transmission: e.target.value })} />
        <NumberField label="عدد المقاعد" value={form.seats} onChange={(e) => setForm({ ...form, seats: e.target.value })} />
        <TextField label="رقم الشاسيه" value={form.chassis_number} error={errors.chassis_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, chassis_number: e.target.value })} />
        <TextField label="رقم المحرك" value={form.engine_number} error={errors.engine_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, engine_number: e.target.value })} />
        <TextField label="سعة المحرك" value={form.engine_capacity} onChange={(e) => setForm({ ...form, engine_capacity: e.target.value })} />
        <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
        <MoneyField label="الإيجار اليومي الافتراضي" required value={form.daily_rate} error={errors.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
        <MoneyField label="التأمين النقدي الافتراضي" required value={form.default_deposit} error={errors.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
        <MoneyField label="المستهدف الشهري" value={form.target_monthly_income} onChange={(e) => setForm({ ...form, target_monthly_income: e.target.value })} />
        <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
        <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />

        {mode === 'create' ? (
          <>
            <SelectField
              label="المالك"
              value={form.owner_mode}
              onChange={(e) => setForm({ ...form, owner_mode: e.target.value as FormState['owner_mode'], owner_id: '' })}
              options={[
                { value: 'none', label: '— ملك المركز —' },
                { value: 'existing', label: 'مالك مسجّل' },
                { value: 'new', label: 'إنشاء مالك جديد من هنا' },
              ]}
            />
            {form.owner_mode === 'existing' ? (
              <SelectField
                label="اختيار المالك"
                required
                value={form.owner_id}
                options={[
                  { value: '', label: 'اختر…' },
                  ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
                ]}
                onChange={(e) => setForm({ ...form, owner_id: e.target.value })}
              />
            ) : null}
            {form.owner_mode === 'new' ? (
              <>
                <TextField label="اسم المالك" required value={form.owner_full_name} error={errors['owner_inline.full_name']} onChange={(e) => setForm({ ...form, owner_full_name: e.target.value })} />
                <TextField label="هاتف المالك" value={form.owner_phone} onChange={(e) => setForm({ ...form, owner_phone: e.target.value })} />
                <TextField label="الرقم القومي" value={form.owner_national_id} onChange={(e) => setForm({ ...form, owner_national_id: e.target.value })} />
                <TextAreaField label="ملاحظات المالك" value={form.owner_notes} onChange={(e) => setForm({ ...form, owner_notes: e.target.value })} />
              </>
            ) : null}
            {form.owner_mode !== 'none' ? (
              <MoneyField
                label="المبلغ الشهري للمالك"
                value={form.owner_monthly_amount}
                onChange={(e) => setForm({ ...form, owner_monthly_amount: e.target.value })}
              />
            ) : null}
          </>
        ) : (
          <>
            <SelectField
              label="المالك"
              value={form.owner_id}
              onChange={(e) => setForm({ ...form, owner_id: e.target.value })}
              options={[
                { value: '', label: '— ملك المركز —' },
                ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
              ]}
            />
            {form.owner_id ? (
              <MoneyField label="المبلغ الشهري للمالك" required value={form.owner_monthly_amount} onChange={(e) => setForm({ ...form, owner_monthly_amount: e.target.value })} />
            ) : null}
          </>
        )}

        <div className="rpm-field" style={{ gridColumn: '1 / -1' }}>
          <span className="rpm-field__label">بيانات الإضافة / المستلزمات</span>
          <span className="rpm-field__hint">اختر المستلزمات من القائمة الرئيسية لإضافتها أو تحديثها مع العربية</span>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginBlockStart: '0.5rem' }}>
            {addons.map((a) => {
              const on = selectedAddons.includes(a.id)
              return (
                <Button key={a.id} type="button" size="sm" variant={on ? 'accent' : 'secondary'} onClick={() => toggleAddon(a.id)}>
                  {a.name}
                </Button>
              )
            })}
            {!addons.length ? <span className="muted">لا توجد بيانات إضافة بعد</span> : null}
          </div>
        </div>

        <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
      </div>
    </Sheet>
  )
}
