import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField,
  PlateField, useToast,
} from '@/shared/ui'

type OwnerOption = { id: number; full_name: string; phone?: string | null }
type Brand = { id: number; name: string }
type Addon = { id: number; name: string; category?: string | null; default_qty: number }

type Props = {
  open: boolean
  onClose: () => void
  onSaved: (vehicleId?: number) => void
}

const emptyForm = {
  plate_letters: '',
  plate_numbers: '',
  brand: '',
  model: '',
  year: '',
  category: 'اقتصادي',
  fuel_type: 'بنزين',
  odometer: '',
  daily_rate: '',
  default_deposit: '',
  chassis_number: '',
  engine_number: '',
  engine_capacity: '',
  color: '',
  transmission: 'أوتوماتيك',
  seats: '',
  tracker_device_number: '',
  tracker_sim_number: '',
  notes: '',
  is_active: true,
  owner_id: '',
  owner_monthly_amount: '',
  owner_mode: 'none' as 'none' | 'existing' | 'new',
  owner_full_name: '',
  owner_phone: '',
  owner_national_id: '',
  owner_notes: '',
}

export function VehicleCreateSheet({ open, onClose, onSaved }: Props) {
  const toast = useToast()
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [nextCode, setNextCode] = useState('—')
  const [brands, setBrands] = useState<Brand[]>([])
  const [addons, setAddons] = useState<Addon[]>([])
  const [selectedAddons, setSelectedAddons] = useState<number[]>([])
  const [owners, setOwners] = useState<OwnerOption[]>([])

  useEffect(() => {
    if (!open) return
    setForm(emptyForm)
    setErrors({})
    setSelectedAddons([])
    void api<{ data: { brands: Brand[]; addons: Addon[]; next_code: string } }>('/vehicles/catalogs')
      .then((res) => {
        const brandList = res.data.brands || []
        setBrands(brandList)
        setAddons(res.data.addons || [])
        setNextCode(res.data.next_code || '—')
        if (brandList[0]) {
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
  }, [open])

  function toggleAddon(id: number) {
    setSelectedAddons((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  async function save() {
    setSaving(true)
    setErrors({})
    try {
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
        chassis_number: form.chassis_number || null,
        engine_number: form.engine_number || null,
        engine_capacity: form.engine_capacity || null,
        color: form.color || null,
        transmission: form.transmission || null,
        seats: form.seats ? Number(form.seats) : null,
        tracker_device_number: form.tracker_device_number || null,
        tracker_sim_number: form.tracker_sim_number || null,
        notes: form.notes || null,
        is_active: form.is_active,
        addon_ids: selectedAddons,
      }

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
      title="إضافة عربية جديدة"
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>حفظ العربية</Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        <TextField label="الكود الداخلي" value={nextCode} disabled hint="يُولَّد تلقائياً بالتسلسل عند الحفظ" />
        <PlateField
          required
          letters={form.plate_letters}
          numbers={form.plate_numbers}
          error={errors.plate || errors.plate_letters || errors.plate_numbers}
          onLettersChange={(plate_letters) => setForm({ ...form, plate_letters })}
          onNumbersChange={(plate_numbers) => setForm({ ...form, plate_numbers })}
        />
        <SelectField
          label="الماركة"
          required
          value={form.brand}
          error={errors.brand}
          options={brands.map((b) => ({ value: b.name, label: b.name }))}
          onChange={(e) => setForm({ ...form, brand: e.target.value })}
        />
        <TextField label="الموديل" required value={form.model} error={errors.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
        <NumberField label="سنة الصنع" required value={form.year} error={errors.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
        <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'دفع رباعي', label: 'دفع رباعي' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
        <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
        <TextField label="اللون" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
        <SelectField label="ناقل الحركة" options={[{ value: 'أوتوماتيك', label: 'أوتوماتيك' }, { value: 'مانيوال', label: 'مانيوال' }]} value={form.transmission} onChange={(e) => setForm({ ...form, transmission: e.target.value })} />
        <TextField label="رقم الشاسيه" value={form.chassis_number} error={errors.chassis_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, chassis_number: e.target.value })} />
        <TextField label="رقم المحرك" value={form.engine_number} error={errors.engine_number} hint="إنجليزي/أرقام فقط · 5–32" onChange={(e) => setForm({ ...form, engine_number: e.target.value })} />
        <TextField label="سعة المحرك" value={form.engine_capacity} onChange={(e) => setForm({ ...form, engine_capacity: e.target.value })} />
        <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
        <MoneyField label="الإيجار اليومي الافتراضي" required value={form.daily_rate} error={errors.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
        <MoneyField label="التأمين النقدي الافتراضي" required value={form.default_deposit} error={errors.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />

        <SelectField
          label="المالك"
          value={form.owner_mode}
          onChange={(e) => setForm({ ...form, owner_mode: e.target.value as typeof form.owner_mode, owner_id: '' })}
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

        <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
      </div>
    </Sheet>
  )
}
