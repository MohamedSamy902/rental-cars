import { useMemo, useState } from 'react'
import {
  PageHeader,
  SummaryStrip,
  FilterBar,
  Button,
  StatusPill,
  Sheet,
  TextField,
  SelectField,
  NumberField,
  MoneyField,
  TextAreaField,
  useToast,
  EmptyState,
} from '@/shared/ui'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  bookingStatusLabel,
  bookings as seedBookings,
  vehicles,
  type Booking,
} from '@/shared/lib/mock-data'
import './pages.css'

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'pending', label: 'معلّق' },
  { id: 'approved', label: 'معتمد' },
  { id: 'active', label: 'نشط' },
  { id: 'completed', label: 'مكتمل' },
]

type FormState = {
  customer: string
  vehicleId: string
  startAt: string
  endAt: string
  dailyRate: string
  minDays: string
  deposit: string
  notes: string
}

const emptyForm: FormState = {
  customer: '',
  vehicleId: '',
  startAt: '',
  endAt: '',
  dailyRate: '',
  minDays: '',
  deposit: '',
  notes: '',
}

export function BookingsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Booking[]>(seedBookings)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [open, setOpen] = useState(false)
  const [inlineCustomer, setInlineCustomer] = useState(false)
  const [newCustomerName, setNewCustomerName] = useState('')
  const [newCustomerPhone, setNewCustomerPhone] = useState('')
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({})

  const vehicleOptions = vehicles.map((v) => ({
    value: v.id,
    label: `${v.brand} ${v.model} · ${v.plate}`,
  }))

  const filtered = useMemo(() => {
    return rows.filter((b) => {
      const matchesChip = chip === 'all' || b.status === chip
      const q = search.trim()
      const matchesSearch =
        !q || [b.number, b.customer, b.vehicle].some((x) => x.includes(q))
      return matchesChip && matchesSearch
    })
  }, [rows, chip, search])

  function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
    setErrors((prev) => ({ ...prev, [key]: undefined }))
  }

  function validate(): boolean {
    const next: Partial<Record<keyof FormState, string>> = {}
    if (!form.customer.trim()) next.customer = 'العميل مطلوب.'
    if (!form.vehicleId) next.vehicleId = 'السيارة مطلوبة.'
    if (!form.startAt) next.startAt = 'وقت البداية مطلوب.'
    if (!form.endAt) next.endAt = 'وقت النهاية مطلوب.'
    if (!form.dailyRate.trim()) next.dailyRate = 'السعر اليومي مطلوب.'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  function addInlineCustomer() {
    if (!newCustomerName.trim() || !newCustomerPhone.trim()) {
      toast.error('أدخل اسم العميل ورقم الهاتف.')
      return
    }
    setField('customer', newCustomerName.trim())
    setInlineCustomer(false)
    setNewCustomerName('')
    setNewCustomerPhone('')
    toast.success('تم تسجيل العميل وربطه بالحجز.')
  }

  async function onSave() {
    if (!validate()) {
      toast.error('تعذّر الحفظ. راجع الحقول.')
      return
    }
    setSaving(true)
    await new Promise((r) => setTimeout(r, 450))
    const vehicle = vehicles.find((v) => v.id === form.vehicleId)
    const created: Booking = {
      id: `b-${Date.now()}`,
      number: `CNT-${String(Math.floor(Math.random() * 90000) + 10000)}`,
      customer: form.customer.trim(),
      vehicle: vehicle ? `${vehicle.brand} ${vehicle.model} · ${vehicle.plate}` : form.vehicleId,
      startAt: form.startAt,
      endAt: form.endAt,
      dailyRate: Number(form.dailyRate),
      status: 'pending',
      salesRep: 'أنت',
    }
    setRows((prev) => [created, ...prev])
    setSaving(false)
    setOpen(false)
    setForm(emptyForm)
    toast.success('تم إنشاء الحجز.')
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الحجوزات"
        subtitle="عقود التأجير — إضافة بلا رفريش مع تسجيل عميل مضمّن"
        action={<Button onClick={() => setOpen(true)}>إضافة حجز</Button>}
      />

      <SummaryStrip
        items={[
          { label: 'الإجمالي', value: String(rows.length) },
          {
            label: 'معلّق',
            value: String(rows.filter((b) => b.status === 'pending').length),
            tone: 'warning',
          },
          {
            label: 'نشط',
            value: String(rows.filter((b) => b.status === 'active').length),
            tone: 'success',
          },
          {
            label: 'معتمد',
            value: String(rows.filter((b) => b.status === 'approved').length),
          },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث برقم العقد أو العميل…"
      />

      {filtered.length === 0 ? (
        <EmptyState title="لا توجد حجوزات مطابقة" />
      ) : (
        <>
          <div className="rpm-card-list rpm-mobile-only">
            {filtered.map((b) => (
              <article key={b.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{b.number}</strong>
                  <StatusPill status={b.status} label={bookingStatusLabel[b.status]} />
                </div>
                <p>{b.customer}</p>
                <p className="muted">{b.vehicle}</p>
                <p className="muted">
                  {formatDateTime(b.startAt)} → {formatDateTime(b.endAt)}
                </p>
                <div className="rpm-card__meta">
                  <span>{formatMoney(b.dailyRate)} / يوم</span>
                  <span>{b.salesRep}</span>
                </div>
              </article>
            ))}
          </div>

          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>رقم العقد</th>
                  <th>العميل</th>
                  <th>السيارة</th>
                  <th>البداية</th>
                  <th>النهاية</th>
                  <th>الحالة</th>
                  <th>اليومي</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((b) => (
                  <tr key={b.id}>
                    <td>{b.number}</td>
                    <td>{b.customer}</td>
                    <td>{b.vehicle}</td>
                    <td>{formatDateTime(b.startAt)}</td>
                    <td>{formatDateTime(b.endAt)}</td>
                    <td>
                      <StatusPill status={b.status} label={bookingStatusLabel[b.status]} />
                    </td>
                    <td>{formatMoney(b.dailyRate)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <Sheet
        open={open}
        title="إضافة حجز"
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
          <div className="rpm-inline-create">
            <TextField
              label="العميل"
              required
              value={form.customer}
              error={errors.customer}
              onChange={(e) => setField('customer', e.target.value)}
              placeholder="اسم العميل المسجّل"
            />
            <Button type="button" variant="secondary" size="sm" onClick={() => setInlineCustomer((v) => !v)}>
              + إضافة من هنا
            </Button>
          </div>

          {inlineCustomer ? (
            <div className="rpm-inline-box">
              <p className="rpm-inline-box__title">تسجيل عميل سريع</p>
              <TextField label="الاسم الكامل" required value={newCustomerName} onChange={(e) => setNewCustomerName(e.target.value)} />
              <TextField label="الهاتف" required value={newCustomerPhone} onChange={(e) => setNewCustomerPhone(e.target.value)} />
              <Button type="button" size="sm" onClick={addInlineCustomer}>
                حفظ واختيار
              </Button>
            </div>
          ) : null}

          <SelectField
            label="السيارة"
            required
            options={vehicleOptions}
            value={form.vehicleId}
            error={errors.vehicleId}
            onChange={(e) => {
              const id = e.target.value
              setField('vehicleId', id)
              const v = vehicles.find((x) => x.id === id)
              if (v) {
                setField('dailyRate', String(v.dailyRate))
                setField('deposit', String(v.deposit))
              }
            }}
          />
          <TextField
            label="وقت البداية"
            required
            type="datetime-local"
            value={form.startAt}
            error={errors.startAt}
            onChange={(e) => setField('startAt', e.target.value)}
          />
          <TextField
            label="وقت النهاية المخطط"
            required
            type="datetime-local"
            value={form.endAt}
            error={errors.endAt}
            onChange={(e) => setField('endAt', e.target.value)}
          />
          <MoneyField
            label="السعر اليومي"
            required
            value={form.dailyRate}
            error={errors.dailyRate}
            onChange={(e) => setField('dailyRate', e.target.value)}
          />
          <NumberField label="الحد الأدنى للأيام" value={form.minDays} onChange={(e) => setField('minDays', e.target.value)} />
          <MoneyField label="التأمين النقدي المتفق" value={form.deposit} onChange={(e) => setField('deposit', e.target.value)} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setField('notes', e.target.value)} hint="نص حر فقط — لا ينشئ قواعد مالية" />
        </div>
      </Sheet>
    </div>
  )
}
