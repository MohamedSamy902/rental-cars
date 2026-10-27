import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, FilterBar, Button, StatusPill, Sheet,
  TextField, SelectField, NumberField, MoneyField, TextAreaField,
  useToast, EmptyState, SkeletonBlock, ErrorState,
} from '@/shared/ui'
import './pages.css'

type Deposit = { id: number; original_amount: number; status: string; deductions_total: number }
type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  odometer_out?: number
  final_total?: number
  vehicle_id?: number
  customer?: { id: number; full_name: string }
  vehicle?: { id: number; brand: string; model: string; plate: string; odometer?: number }
  deposit?: Deposit | null
}

type Customer = { id: number; full_name: string; phone: string }
type Vehicle = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number; odometer?: number }

type ActionKind = 'handover' | 'return' | 'cancel' | 'extend' | 'swap' | 'deposit' | null

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
  const [searchParams, setSearchParams] = useSearchParams()
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
  const [action, setAction] = useState<ActionKind>(null)
  const [selected, setSelected] = useState<Contract | null>(null)
  const [actionForm, setActionForm] = useState({
    odometer_out: '', odometer_in: '', damage_fee: '', reason: '', planned_ends_at: '',
    replacement_vehicle_id: '', method: 'cash', receipt_number: '',
  })

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

  useEffect(() => {
    if (searchParams.get('new') !== '1') return
    void openSheet()
    const next = new URLSearchParams(searchParams)
    next.delete('new')
    setSearchParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- open once when ?new=1 arrives
  }, [searchParams])

  async function openAction(kind: ActionKind, contract: Contract) {
    setSelected(contract)
    setAction(kind)
    setActionForm({
      odometer_out: String(contract.vehicle?.odometer ?? contract.odometer_out ?? ''),
      odometer_in: String(contract.odometer_out ?? contract.vehicle?.odometer ?? ''),
      damage_fee: '',
      reason: '',
      planned_ends_at: '',
      replacement_vehicle_id: '',
      method: 'cash',
      receipt_number: `R-${contract.number}`,
    })
    if (kind === 'swap' || kind === 'extend') {
      const v = await api<{ data: Vehicle[] }>('/vehicles?per_page=100')
      setVehicles(v.data)
    }
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

  async function runAction() {
    if (!selected || !action) return
    setSaving(true)
    try {
      if (action === 'handover') {
        await api(`/contracts/${selected.id}/handover`, {
          method: 'POST',
          json: { odometer_out: Number(actionForm.odometer_out) },
        })
        toast.success('تم التسليم وتفعيل العقد.')
      } else if (action === 'return') {
        await api(`/contracts/${selected.id}/return`, {
          method: 'POST',
          json: {
            odometer_in: Number(actionForm.odometer_in),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
          },
        })
        toast.success('تم الاستلام وإقفال الإجمالي.')
      } else if (action === 'cancel') {
        await api(`/contracts/${selected.id}/cancel`, {
          method: 'POST',
          json: { reason: actionForm.reason },
        })
        toast.success('تم إلغاء العقد.')
      } else if (action === 'extend') {
        await api(`/contracts/${selected.id}/extend`, {
          method: 'POST',
          json: { planned_ends_at: actionForm.planned_ends_at },
        })
        toast.success('تم إنشاء عقد التمديد.')
      } else if (action === 'swap') {
        await api(`/contracts/${selected.id}/swap`, {
          method: 'POST',
          json: {
            replacement_vehicle_id: Number(actionForm.replacement_vehicle_id),
            odometer_in: Number(actionForm.odometer_in),
            odometer_out: Number(actionForm.odometer_out),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
          },
        })
        toast.success('تم التبديل بسبب عطل.')
      } else if (action === 'deposit') {
        await api(`/contracts/${selected.id}/deposit`, {
          method: 'POST',
          json: {
            method: actionForm.method,
            receipt_number: actionForm.receipt_number,
            amount: selected.frozen_agreed_deposit,
          },
        })
        toast.success('تم قبض التأمين النقدي.')
      }
      setAction(null)
      setSelected(null)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const actionTitle: Record<Exclude<ActionKind, null>, string> = {
    handover: 'تسليم السيارة',
    return: 'استلام وإقفال',
    cancel: 'إلغاء العقد',
    extend: 'تمديد (عقد جديد)',
    swap: 'تبديل بسبب عطل',
    deposit: 'قبض التأمين النقدي',
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
                {b.final_total ? <span>الإجمالي: {formatMoney(b.final_total)}</span> : null}
                {b.deposit ? <span>تأمين: {b.deposit.status}</span> : null}
              </div>
              <div className="rpm-card__actions">
                {b.status === 'pending' ? (
                  <>
                    <Button type="button" size="sm" onClick={() => void approve(b.id)}>اعتماد</Button>
                    <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('cancel', b)}>إلغاء</Button>
                  </>
                ) : null}
                {b.status === 'approved' ? (
                  <>
                    <Button type="button" size="sm" onClick={() => void openAction('handover', b)}>تسليم</Button>
                    {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('deposit', b)}>قبض تأمين</Button> : null}
                    <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('cancel', b)}>إلغاء</Button>
                    <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
                  </>
                ) : null}
                {b.status === 'active' ? (
                  <>
                    <Button type="button" size="sm" onClick={() => void openAction('return', b)}>استلام</Button>
                    {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('deposit', b)}>قبض تأمين</Button> : null}
                    <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
                    <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('swap', b)}>تبديل عطل</Button>
                  </>
                ) : null}
                {b.status === 'completed' ? (
                  <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
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

      <Sheet
        open={!!action && !!selected}
        title={action ? `${actionTitle[action]} · ${selected?.number}` : ''}
        onClose={() => { setAction(null); setSelected(null) }}
        footer={
          <>
            <Button loading={saving} onClick={runAction}>تأكيد</Button>
            <Button variant="secondary" onClick={() => { setAction(null); setSelected(null) }}>إغلاق</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          {action === 'handover' || action === 'swap' ? (
            <NumberField label={action === 'swap' ? 'عداد السيارة البديلة (خروج)' : 'عداد الخروج'} required value={actionForm.odometer_out} onChange={(e) => setActionForm({ ...actionForm, odometer_out: e.target.value })} />
          ) : null}
          {action === 'return' || action === 'swap' ? (
            <NumberField label={action === 'swap' ? 'عداد السيارة القديمة (عودة)' : 'عداد العودة'} required value={actionForm.odometer_in} onChange={(e) => setActionForm({ ...actionForm, odometer_in: e.target.value })} />
          ) : null}
          {action === 'return' || action === 'swap' ? (
            <MoneyField label="رسوم أضرار" value={actionForm.damage_fee} onChange={(e) => setActionForm({ ...actionForm, damage_fee: e.target.value })} />
          ) : null}
          {action === 'cancel' ? (
            <TextAreaField label="سبب الإلغاء" required value={actionForm.reason} onChange={(e) => setActionForm({ ...actionForm, reason: e.target.value })} />
          ) : null}
          {action === 'extend' ? (
            <TextField label="نهاية عقد التمديد" required type="datetime-local" value={actionForm.planned_ends_at} onChange={(e) => setActionForm({ ...actionForm, planned_ends_at: e.target.value })} />
          ) : null}
          {action === 'swap' ? (
            <SelectField
              label="السيارة البديلة"
              required
              options={vehicles
                .filter((v) => v.id !== selected?.vehicle_id)
                .map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
              value={actionForm.replacement_vehicle_id}
              onChange={(e) => setActionForm({ ...actionForm, replacement_vehicle_id: e.target.value })}
            />
          ) : null}
          {action === 'deposit' ? (
            <>
              <SelectField
                label="طريقة التحصيل"
                required
                options={[
                  { value: 'cash', label: 'نقدي' },
                  { value: 'card', label: 'بطاقة' },
                  { value: 'transfer', label: 'تحويل' },
                ]}
                value={actionForm.method}
                onChange={(e) => setActionForm({ ...actionForm, method: e.target.value })}
              />
              <TextField label="رقم الإيصال" required value={actionForm.receipt_number} onChange={(e) => setActionForm({ ...actionForm, receipt_number: e.target.value })} />
              <p className="muted">المبلغ: {formatMoney(selected?.frozen_agreed_deposit || 0)} (خارج إجمالي العقد)</p>
            </>
          ) : null}
        </div>
      </Sheet>
    </div>
  )
}
