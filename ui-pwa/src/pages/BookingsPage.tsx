import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, FilterBar, Button, StatusPill, Sheet,
  TextField, SelectField, NumberField, MoneyField, TextAreaField,
  useToast, EmptyState, SkeletonBlock, ErrorState,
} from '@/shared/ui'
import './pages.css'

type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  frozen_daily_rate: number
  customer?: { id: number; full_name: string }
  vehicle?: { id: number; brand: string; model: string; plate: string }
}

type Customer = { id: number; full_name: string; phone: string }
type Vehicle = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number }

const statusLabel: Record<string, string> = {
  pending: 'معلّق', approved: 'معتمد', active: 'نشط', completed: 'مكتمل', cancelled: 'ملغى',
}

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'pending', label: 'معلّق' },
  { id: 'approved', label: 'معتمد' },
  { id: 'active', label: 'نشط' },
  { id: 'completed', label: 'مكتمل' },
]

export function BookingsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Contract[]>([])
  const [summary, setSummary] = useState({ total: 0, pending: 0, approved: 0, active: 0, completed: 0 })
  const [customers, setCustomers] = useState<Customer[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [inlineCustomer, setInlineCustomer] = useState(false)
  const [newCustomer, setNewCustomer] = useState({ full_name: '', phone: '', birth_date: '1990-01-01', license_number: '', license_expires_on: '2030-01-01' })
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    customer_id: '', vehicle_id: '', starts_at: '', planned_ends_at: '',
    daily_rate: '', deposit: '', min_days: '', notes: '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function load() {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (search.trim()) qs.set('search', search.trim())
      if (chip !== 'all') qs.set('status', chip)
      const res = await api<{ data: Contract[]; summary: typeof summary }>(`/contracts?${qs}`)
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

  async function openSheet() {
    setOpen(true)
    const [c, v] = await Promise.all([
      api<{ data: Customer[] }>('/customers?per_page=100'),
      api<{ data: Vehicle[] }>('/vehicles?per_page=100'),
    ])
    setCustomers(c.data)
    setVehicles(v.data)
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
      setOpen(false)
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

  async function approve(id: number) {
    try {
      await api(`/contracts/${id}/approve`, { method: 'POST' })
      toast.success('تم اعتماد العقد وتثبيت الأسعار.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العقود والحجوزات"
        subtitle="تمديد = عقد جديد · تبديل عطل = عقد جديد · بلا رصيد دائن"
        action={<Button onClick={openSheet}>إضافة حجز</Button>}
      />

      <SummaryStrip
        items={[
          { label: 'الإجمالي', value: String(summary.total) },
          { label: 'معلّق', value: String(summary.pending), tone: 'warning' },
          { label: 'نشط', value: String(summary.active), tone: 'success' },
          { label: 'معتمد', value: String(summary.approved) },
        ]}
      />

      <FilterBar search={search} onSearchChange={setSearch} chips={chips} activeChip={chip} onChipChange={setChip} placeholder="بحث برقم العقد أو العميل…" />

      {loading ? <SkeletonBlock rows={6} /> : null}
      {!loading && error ? <ErrorState title="تعذّر تحميل العقود" description={error} /> : null}
      {!loading && !error && rows.length === 0 ? <EmptyState title="لا توجد عقود مطابقة" /> : null}

      {!loading && !error && rows.length > 0 ? (
        <div className="rpm-card-list">
          {rows.map((b) => (
            <article key={b.id} className="rpm-card">
              <div className="rpm-card__row">
                <strong>{b.number}</strong>
                <StatusPill status={b.status} label={statusLabel[b.status] || b.status} />
              </div>
              <p>{b.customer?.full_name}</p>
              <p className="muted">{b.vehicle?.brand} {b.vehicle?.model} · {b.vehicle?.plate}</p>
              <p className="muted">{formatDateTime(b.starts_at)} → {formatDateTime(b.planned_ends_at)}</p>
              <div className="rpm-card__meta">
                <span>{formatMoney(b.frozen_daily_rate)} / يوم</span>
                {b.status === 'pending' ? (
                  <Button size="sm" onClick={() => approve(b.id)}>اعتماد</Button>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      ) : null}

      <Sheet
        open={open}
        title="إضافة حجز / عقد"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>حفظ</Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button>
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
    </div>
  )
}
