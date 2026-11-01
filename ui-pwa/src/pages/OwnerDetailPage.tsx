import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, DetailList, TextField, TextAreaField, MoneyField, SelectField, MediaUpload,
  useToast, SkeletonBlock, ErrorState, StatusPill, MetricCardGrid, IconWallet, IconCash, IconTrend, IconKey,
} from '@/shared/ui'
import './pages.css'

type Balance = {
  accrued_total: number
  paid_total: number
  remaining: number
  vehicles_monthly_total: number
}

type OwnerVehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  owner_monthly_amount?: number | null
  owner_since?: string | null
  is_active: boolean
}

type Payout = {
  id: number
  amount: number
  paid_on: string
  payment_method: string
  notes?: string | null
  vehicle?: { id: number; code: string; plate: string } | null
}

type Due = {
  id: number
  year_month: string
  amount: number
  vehicle?: { id: number; code: string; plate: string; brand?: string; model?: string } | null
}

type Owner = {
  id: number
  full_name: string
  phone?: string | null
  phone_alt?: string | null
  national_id?: string | null
  id_front_path?: string | null
  id_back_path?: string | null
  license_front_path?: string | null
  license_back_path?: string | null
  notes?: string | null
  is_active: boolean
  balance?: Balance | null
  vehicles?: OwnerVehicle[]
  dues?: Due[]
  payouts?: Payout[]
}

const emptyForm = {
  full_name: '', phone: '', phone_alt: '', national_id: '', notes: '', is_active: true,
  id_front_path: '', id_back_path: '', license_front_path: '', license_back_path: '',
}

export function OwnerDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const [owner, setOwner] = useState<Owner | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [payoutOpen, setPayoutOpen] = useState(false)
  const [payoutForm, setPayoutForm] = useState({
    amount: '', paid_on: new Date().toISOString().slice(0, 10), payment_method: 'cash', vehicle_id: '', notes: '',
  })

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Owner }>(`/owners/${id}`)
      setOwner(res.data)
      setForm({
        full_name: res.data.full_name,
        phone: res.data.phone || '',
        phone_alt: res.data.phone_alt || '',
        national_id: res.data.national_id || '',
        notes: res.data.notes || '',
        is_active: res.data.is_active !== false,
        id_front_path: res.data.id_front_path || '',
        id_back_path: res.data.id_back_path || '',
        license_front_path: res.data.license_front_path || '',
        license_back_path: res.data.license_back_path || '',
      })
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id])

  async function onUpdate() {
    if (!owner) return
    setSaving(true)
    try {
      await api(`/owners/${owner.id}`, { method: 'PUT', json: form })
      toast.success('تم التحديث.')
      setEditing(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function onPayout() {
    if (!owner) return
    setSaving(true)
    try {
      await api(`/owners/${owner.id}/payouts`, {
        method: 'POST',
        json: {
          amount: Number(payoutForm.amount),
          paid_on: payoutForm.paid_on,
          payment_method: payoutForm.payment_method,
          vehicle_id: payoutForm.vehicle_id ? Number(payoutForm.vehicle_id) : null,
          notes: payoutForm.notes || null,
        },
      })
      toast.success('تم تسجيل الصرف.')
      setPayoutOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !owner) {
    return (
      <div className="rpm-stack">
        <ErrorState title="تعذّر تحميل المالك" description={error || 'غير موجود'} action={<Button onClick={() => navigate('/owners')}>رجوع</Button>} />
      </div>
    )
  }

  const balance = owner.balance

  return (
    <div className="rpm-stack">
      <PageHeader
        title={owner.full_name}
        subtitle="ملف مالك العربيات · المستحقات · الصرف · المستندات"
        action={
          <>
            <Button variant="secondary" onClick={() => navigate('/owners')}>رجوع</Button>
            <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
            <Button onClick={() => setPayoutOpen(true)}>صرف مستحقات</Button>
          </>
        }
      />

      <MetricCardGrid
        label="ملخص المستحقات"
        items={[
          { id: 'monthly', label: 'إجمالي الشهري', value: balance?.vehicles_monthly_total || 0, format: 'money', icon: <IconKey size={16} /> },
          { id: 'accrued', label: 'إجمالي المستحق', value: balance?.accrued_total || 0, format: 'money', icon: <IconWallet size={16} /> },
          { id: 'paid', label: 'إجمالي المصروف', value: balance?.paid_total || 0, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'left', label: 'المتبقي', value: balance?.remaining || 0, format: 'money', tone: 'warning', icon: <IconTrend size={16} /> },
        ]}
      />

      <DetailList
        rows={[
          { label: 'الهاتف', value: owner.phone ? <span dir="ltr">{owner.phone}</span> : '—' },
          { label: 'هاتف بديل', value: owner.phone_alt || '—' },
          { label: 'الرقم القومي', value: owner.national_id || '—' },
          { label: 'الحالة', value: <StatusPill status={owner.is_active ? 'active' : 'cancelled'} label={owner.is_active ? 'نشط' : 'موقوف'} /> },
          { label: 'ملاحظات', value: owner.notes || '—', wide: true },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستندات</h3></div>
        <div className="rpm-form-grid">
          <MediaUpload label="بطاقة — وش" value={owner.id_front_path || null} collection="owners_id" purpose="id_front" disabled onChange={() => undefined} />
          <MediaUpload label="بطاقة — ضهر" value={owner.id_back_path || null} collection="owners_id" purpose="id_back" disabled onChange={() => undefined} />
          <MediaUpload label="رخصة — وش" value={owner.license_front_path || null} collection="owners_id" purpose="license_front" disabled onChange={() => undefined} />
          <MediaUpload label="رخصة — ضهر" value={owner.license_back_path || null} collection="owners_id" purpose="license_back" disabled onChange={() => undefined} />
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>العربيات المربوطة</h3></div>
        {(owner.vehicles || []).length === 0 ? <p className="muted">لا عربيات مربوطة — اربط من شاشة العربيات.</p> : (
          <div className="rpm-card-list">
            {(owner.vehicles || []).map((v) => (
              <article key={v.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong><Link to={`/vehicles/${v.id}`}>{v.brand} {v.model}</Link></strong>
                  <strong className="tabular-nums">{v.owner_monthly_amount != null ? formatMoney(v.owner_monthly_amount) : '—'}</strong>
                </div>
                <p className="muted">{v.code} · {v.plate} · منذ {formatDate(v.owner_since)}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>سجل الصرف</h3></div>
        {(owner.payouts || []).length === 0 ? <p className="muted">لا مدفوعات بعد.</p> : (
          <div className="rpm-card-list">
            {(owner.payouts || []).map((p) => (
              <article key={p.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{formatMoney(p.amount)}</strong>
                  <span>{formatDate(p.paid_on)}</span>
                </div>
                <p className="muted">{p.payment_method}{p.vehicle ? ` · ${p.vehicle.plate}` : ''}{p.notes ? ` · ${p.notes}` : ''}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستحقات الشهرية</h3></div>
        {(owner.dues || []).length === 0 ? <p className="muted">لا مستحقات مسجّلة.</p> : (
          <div className="rpm-card-list">
            {(owner.dues || []).slice(0, 24).map((d) => (
              <article key={d.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{d.year_month}</strong>
                  <strong className="tabular-nums">{formatMoney(d.amount)}</strong>
                </div>
                <p className="muted">{d.vehicle ? `${d.vehicle.brand || ''} ${d.vehicle.model || ''} · ${d.vehicle.plate}` : '—'}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <Sheet open={editing} title="تعديل المالك" onClose={() => setEditing(false)} footer={<><Button loading={saving} onClick={() => void onUpdate()}>حفظ</Button><Button variant="secondary" onClick={() => setEditing(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          <TextField label="الهاتف" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <TextField label="هاتف بديل" value={form.phone_alt} onChange={(e) => setForm({ ...form, phone_alt: e.target.value })} />
          <TextField label="الرقم القومي" value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <MediaUpload label="بطاقة — وش" value={form.id_front_path || null} collection="owners_id" purpose="id_front" accept="image" onChange={(path) => setForm({ ...form, id_front_path: path || '' })} />
          <MediaUpload label="بطاقة — ضهر" value={form.id_back_path || null} collection="owners_id" purpose="id_back" accept="image" onChange={(path) => setForm({ ...form, id_back_path: path || '' })} />
          <MediaUpload label="رخصة — وش" value={form.license_front_path || null} collection="owners_id" purpose="license_front" accept="image" onChange={(path) => setForm({ ...form, license_front_path: path || '' })} />
          <MediaUpload label="رخصة — ضهر" value={form.license_back_path || null} collection="owners_id" purpose="license_back" accept="image" onChange={(path) => setForm({ ...form, license_back_path: path || '' })} />
        </div>
      </Sheet>

      <Sheet open={payoutOpen} title="صرف مستحقات" onClose={() => setPayoutOpen(false)} footer={<><Button loading={saving} onClick={() => void onPayout()}>تأكيد الصرف</Button><Button variant="secondary" onClick={() => setPayoutOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <MoneyField label="المبلغ" required value={payoutForm.amount} onChange={(e) => setPayoutForm({ ...payoutForm, amount: e.target.value })} />
          <TextField label="تاريخ الصرف" type="date" required value={payoutForm.paid_on} onChange={(e) => setPayoutForm({ ...payoutForm, paid_on: e.target.value })} />
          <SelectField
            label="طريقة الدفع"
            value={payoutForm.payment_method}
            onChange={(e) => setPayoutForm({ ...payoutForm, payment_method: e.target.value })}
            options={[{ value: 'cash', label: 'نقدي' }, { value: 'transfer', label: 'تحويل' }, { value: 'check', label: 'شيك' }]}
          />
          <SelectField
            label="العربية (اختياري)"
            value={payoutForm.vehicle_id}
            onChange={(e) => setPayoutForm({ ...payoutForm, vehicle_id: e.target.value })}
            options={[
              { value: '', label: '— عام —' },
              ...(owner.vehicles || []).map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` })),
            ]}
          />
          <TextAreaField label="ملاحظات" value={payoutForm.notes} onChange={(e) => setPayoutForm({ ...payoutForm, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
