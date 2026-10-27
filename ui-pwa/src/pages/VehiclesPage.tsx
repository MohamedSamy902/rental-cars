import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, FilterBar, Button, StatusPill, Sheet,
  TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField,
  useToast, EmptyState, SkeletonBlock, ErrorState,
} from '@/shared/ui'
import './pages.css'

type Vehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  odometer: number
  daily_rate: number
  default_deposit: number
  status: string
  rental_priority: number
}

const statusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
}

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'available', label: 'متاحة' },
  { id: 'rented', label: 'مؤجرة' },
  { id: 'reserved', label: 'محجوزة' },
  { id: 'maintenance', label: 'صيانة' },
]

const emptyForm = {
  code: '', plate: '', brand: '', model: '', year: '', category: 'اقتصادي', fuel_type: 'بنزين',
  odometer: '', daily_rate: '', default_deposit: '', rental_priority: '50',
  tracker_device_number: '', tracker_sim_number: '', notes: '', is_active: true,
}

export function VehiclesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Vehicle[]>([])
  const [summary, setSummary] = useState({ total: 0, available: 0, rented: 0, reserved: 0, maintenance: 0 })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function load(nextSearch = search, nextChip = chip) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (nextSearch.trim()) qs.set('search', nextSearch.trim())
      if (nextChip !== 'all') qs.set('status', nextChip)
      const res = await api<{ data: Vehicle[]; summary: typeof summary }>(`/vehicles?${qs}`)
      setRows(res.data)
      setSummary(res.summary)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => load(), 250)
    return () => window.clearTimeout(t)
  }, [search, chip])

  async function onSave() {
    setSaving(true)
    setErrors({})
    try {
      await api('/vehicles', {
        method: 'POST',
        json: {
          ...form,
          year: Number(form.year),
          odometer: Number(form.odometer),
          daily_rate: Number(form.daily_rate),
          default_deposit: Number(form.default_deposit),
          rental_priority: Number(form.rental_priority || 50),
        },
      })
      toast.success('تم إضافة السيارة.')
      setOpen(false)
      setForm(emptyForm)
      await load()
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
    <div className="rpm-stack">
      <PageHeader
        title="المركبات"
        subtitle="أسطول التشغيل — الحالة مشتقة من العقود والصيانة"
        action={<Button onClick={() => setOpen(true)}>إضافة مركبة</Button>}
      />

      <SummaryStrip
        items={[
          { label: 'الإجمالي', value: String(summary.total) },
          { label: 'متاحة', value: String(summary.available), tone: 'success' },
          { label: 'مؤجرة', value: String(summary.rented) },
          { label: 'صيانة', value: String(summary.maintenance), tone: 'danger' },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالكود أو اللوحة أو الموديل…"
      />

      {loading ? <SkeletonBlock rows={6} /> : null}
      {!loading && error ? <ErrorState title="تعذّر تحميل المركبات" description={error} action={<Button onClick={() => load()}>إعادة المحاولة</Button>} /> : null}
      {!loading && !error && rows.length === 0 ? <EmptyState title="لا توجد مركبات مطابقة" /> : null}

      {!loading && !error && rows.length > 0 ? (
        <>
          <div className="rpm-card-list rpm-mobile-only">
            {rows.map((v) => (
              <article key={v.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{v.brand} {v.model}</strong>
                  <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
                </div>
                <p>{v.code} · {v.plate}</p>
                <p className="muted">{v.year} · {v.category} · {v.fuel_type}</p>
                <div className="rpm-card__meta">
                  <span>{formatMoney(v.daily_rate)} / يوم</span>
                  <span>{formatNumber(v.odometer)} كم</span>
                </div>
              </article>
            ))}
          </div>
          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>الكود</th><th>اللوحة</th><th>المركبة</th><th>الفئة</th><th>الحالة</th><th>العداد</th><th>اليومي</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id}>
                    <td>{v.code}</td>
                    <td>{v.plate}</td>
                    <td>{v.brand} {v.model} {v.year}</td>
                    <td>{v.category}</td>
                    <td><StatusPill status={v.status} label={statusLabel[v.status] || v.status} /></td>
                    <td>{formatNumber(v.odometer)}</td>
                    <td>{formatMoney(v.daily_rate)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}

      <Sheet
        open={open}
        title="إضافة مركبة"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>حفظ</Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <TextField label="الكود الداخلي" required value={form.code} error={errors.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <TextField label="رقم اللوحة" required value={form.plate} error={errors.plate} onChange={(e) => setForm({ ...form, plate: e.target.value })} />
          <TextField label="الماركة" required value={form.brand} error={errors.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} />
          <TextField label="الموديل" required value={form.model} error={errors.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
          <NumberField label="سنة الصنع" required value={form.year} error={errors.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
          <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'SUV', label: 'SUV' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
          <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
          <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
          <MoneyField label="الإيجار اليومي الافتراضي" required value={form.daily_rate} error={errors.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
          <MoneyField label="التأمين النقدي الافتراضي" required value={form.default_deposit} error={errors.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
          <NumberField label="درجة أولوية التأجير" value={form.rental_priority} onChange={(e) => setForm({ ...form, rental_priority: e.target.value })} hint="من ٠ إلى ١٠٠" />
          <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
          <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
        </div>
      </Sheet>
    </div>
  )
}
