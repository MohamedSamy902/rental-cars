import { useMemo, useState } from 'react'
import {
  PageHeader,
  SummaryStrip,
  FilterBar,
  Button,
  StatusPill,
  Sheet,
  TextField,
  NumberField,
  MoneyField,
  SelectField,
  TextAreaField,
  SwitchField,
  useToast,
  EmptyState,
} from '@/shared/ui'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  categoryOptions,
  fuelOptions,
  vehicleStatusLabel,
  vehicles as seedVehicles,
  type Vehicle,
} from '@/shared/lib/mock-data'
import './pages.css'

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'available', label: 'متاحة' },
  { id: 'rented', label: 'مؤجرة' },
  { id: 'reserved', label: 'محجوزة' },
  { id: 'maintenance', label: 'صيانة' },
]

type FormState = {
  code: string
  plate: string
  brand: string
  model: string
  year: string
  category: string
  fuel: string
  odometer: string
  dailyRate: string
  deposit: string
  priority: string
  trackerDevice: string
  trackerSim: string
  notes: string
  active: boolean
}

const emptyForm: FormState = {
  code: '',
  plate: '',
  brand: '',
  model: '',
  year: '',
  category: '',
  fuel: '',
  odometer: '',
  dailyRate: '',
  deposit: '',
  priority: '50',
  trackerDevice: '',
  trackerSim: '',
  notes: '',
  active: true,
}

export function VehiclesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Vehicle[]>(seedVehicles)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({})

  const filtered = useMemo(() => {
    return rows.filter((v) => {
      const matchesChip = chip === 'all' || v.status === chip
      const q = search.trim()
      const matchesSearch =
        !q ||
        [v.code, v.plate, v.brand, v.model, v.category].some((x) => x.includes(q))
      return matchesChip && matchesSearch
    })
  }, [rows, chip, search])

  const summary = [
    { label: 'الإجمالي', value: String(rows.length) },
    {
      label: 'متاحة',
      value: String(rows.filter((v) => v.status === 'available').length),
      tone: 'success' as const,
    },
    {
      label: 'مؤجرة',
      value: String(rows.filter((v) => v.status === 'rented').length),
    },
    {
      label: 'صيانة',
      value: String(rows.filter((v) => v.status === 'maintenance').length),
      tone: 'danger' as const,
    },
  ]

  function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
    setErrors((prev) => ({ ...prev, [key]: undefined }))
  }

  function validate(): boolean {
    const next: Partial<Record<keyof FormState, string>> = {}
    if (!form.code.trim()) next.code = 'الكود الداخلي مطلوب.'
    if (!form.plate.trim()) next.plate = 'رقم اللوحة مطلوب.'
    if (!form.brand.trim()) next.brand = 'الماركة مطلوبة.'
    if (!form.model.trim()) next.model = 'الموديل مطلوب.'
    if (!form.year.trim()) next.year = 'سنة الصنع مطلوبة.'
    if (!form.category) next.category = 'الفئة مطلوبة.'
    if (!form.fuel) next.fuel = 'نوع الوقود مطلوب.'
    if (!form.odometer.trim()) next.odometer = 'العداد الفعلي مطلوب.'
    if (!form.dailyRate.trim()) next.dailyRate = 'الإيجار اليومي مطلوب.'
    if (!form.deposit.trim()) next.deposit = 'التأمين النقدي مطلوب.'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  async function onSave() {
    if (!validate()) {
      toast.error('تعذّر الحفظ. راجع الحقول.')
      return
    }
    setSaving(true)
    await new Promise((r) => setTimeout(r, 450))
    const created: Vehicle = {
      id: `v-${Date.now()}`,
      code: form.code.trim(),
      plate: form.plate.trim(),
      brand: form.brand.trim(),
      model: form.model.trim(),
      year: Number(form.year),
      category: categoryOptions.find((c) => c.value === form.category)?.label ?? form.category,
      fuel: fuelOptions.find((c) => c.value === form.fuel)?.label ?? form.fuel,
      odometer: Number(form.odometer),
      dailyRate: Number(form.dailyRate),
      deposit: Number(form.deposit),
      status: 'available',
      priority: Number(form.priority || 50),
    }
    setRows((prev) => [created, ...prev])
    setSaving(false)
    setOpen(false)
    setForm(emptyForm)
    toast.success('تم إضافة السيارة.')
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="السيارات"
        subtitle="أسطول التشغيل — حالة اليوم وحقول المواصفة"
        action={<Button onClick={() => setOpen(true)}>إضافة سيارة</Button>}
      />

      <SummaryStrip items={summary} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالكود أو اللوحة أو الموديل…"
      />

      {filtered.length === 0 ? (
        <EmptyState title="لا توجد سيارات مطابقة" description="جرّب تغيير الفلتر أو أضف سيارة جديدة." />
      ) : (
        <>
          <div className="rpm-card-list rpm-mobile-only">
            {filtered.map((v) => (
              <article key={v.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>
                    {v.brand} {v.model}
                  </strong>
                  <StatusPill status={v.status} label={vehicleStatusLabel[v.status]} />
                </div>
                <p>
                  {v.code} · {v.plate}
                </p>
                <p className="muted">
                  {v.year} · {v.category} · {v.fuel}
                </p>
                <div className="rpm-card__meta">
                  <span>{formatMoney(v.dailyRate)} / يوم</span>
                  <span>{formatNumber(v.odometer)} كم</span>
                </div>
              </article>
            ))}
          </div>

          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>الكود</th>
                  <th>اللوحة</th>
                  <th>السيارة</th>
                  <th>الفئة</th>
                  <th>الحالة</th>
                  <th>العداد</th>
                  <th>اليومي</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((v) => (
                  <tr key={v.id}>
                    <td>{v.code}</td>
                    <td>{v.plate}</td>
                    <td>
                      {v.brand} {v.model} {v.year}
                    </td>
                    <td>{v.category}</td>
                    <td>
                      <StatusPill status={v.status} label={vehicleStatusLabel[v.status]} />
                    </td>
                    <td>{formatNumber(v.odometer)}</td>
                    <td>{formatMoney(v.dailyRate)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <Sheet
        open={open}
        title="إضافة سيارة"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>
              حفظ
            </Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              إلغاء
            </Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <TextField label="الكود الداخلي" required value={form.code} error={errors.code} onChange={(e) => setField('code', e.target.value)} />
          <TextField label="رقم اللوحة" required value={form.plate} error={errors.plate} onChange={(e) => setField('plate', e.target.value)} />
          <TextField label="الماركة" required value={form.brand} error={errors.brand} onChange={(e) => setField('brand', e.target.value)} />
          <TextField label="الموديل" required value={form.model} error={errors.model} onChange={(e) => setField('model', e.target.value)} />
          <NumberField label="سنة الصنع" required value={form.year} error={errors.year} onChange={(e) => setField('year', e.target.value)} />
          <SelectField label="الفئة" required options={categoryOptions} value={form.category} error={errors.category} onChange={(e) => setField('category', e.target.value)} />
          <SelectField label="نوع الوقود" required options={fuelOptions} value={form.fuel} error={errors.fuel} onChange={(e) => setField('fuel', e.target.value)} />
          <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setField('odometer', e.target.value)} />
          <MoneyField label="الإيجار اليومي الافتراضي" required value={form.dailyRate} error={errors.dailyRate} onChange={(e) => setField('dailyRate', e.target.value)} />
          <MoneyField label="التأمين النقدي الافتراضي" required value={form.deposit} error={errors.deposit} onChange={(e) => setField('deposit', e.target.value)} />
          <NumberField label="درجة أولوية التأجير" value={form.priority} onChange={(e) => setField('priority', e.target.value)} hint="من ٠ إلى ١٠٠" />
          <TextField label="رقم جهاز التتبع" value={form.trackerDevice} onChange={(e) => setField('trackerDevice', e.target.value)} />
          <TextField label="رقم شريحة التتبع" value={form.trackerSim} onChange={(e) => setField('trackerSim', e.target.value)} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setField('notes', e.target.value)} />
          <SwitchField label="نشطة" checked={form.active} onChange={(v) => setField('active', v)} />
        </div>
      </Sheet>
    </div>
  )
}
