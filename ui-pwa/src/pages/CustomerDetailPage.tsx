import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, DetailList, TextField, TextAreaField, MediaUpload,
  useToast, SkeletonBlock, ErrorState, StatusPill,
} from '@/shared/ui'
import './pages.css'

type ContractRow = {
  id: number
  code: string
  status: string
  starts_at?: string | null
  planned_ends_at?: string | null
  daily_rate?: number
  deposit?: number
  vehicle?: { brand: string; model: string; plate: string } | null
}

type ViolationRow = {
  id: number
  amount: number
  occurred_at: string
  status: string
  location?: string
  vehicle?: { brand: string; model: string; plate: string }
  contract?: { id: number; code: string } | null
}

type Customer = {
  id: number
  full_name: string
  phone: string
  phone_alt?: string | null
  email?: string | null
  license_number: string
  license_expires_on: string
  birth_date?: string
  national_id?: string | null
  national_id_image_path?: string | null
  national_id_back_path?: string | null
  license_image_path?: string | null
  license_back_path?: string | null
  status: string
  notes?: string | null
  blacklist_reason?: string | null
  contracts?: ContractRow[]
  violations?: ViolationRow[]
}

const emptyForm = {
  full_name: '', phone: '', phone_alt: '', birth_date: '1990-01-01', license_number: '', license_expires_on: '2030-01-01',
  national_id: '', notes: '',
  national_id_image_path: '', national_id_back_path: '', license_image_path: '', license_back_path: '',
}

export function CustomerDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [blacklistReason, setBlacklistReason] = useState('')

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Customer }>(`/customers/${id}`)
      setCustomer(res.data)
      setBlacklistReason(res.data.blacklist_reason || '')
      setForm({
        full_name: res.data.full_name,
        phone: res.data.phone,
        phone_alt: res.data.phone_alt || '',
        birth_date: formatDate(res.data.birth_date) === '—' ? '1990-01-01' : formatDate(res.data.birth_date),
        license_number: res.data.license_number,
        license_expires_on: formatDate(res.data.license_expires_on) === '—' ? '2030-01-01' : formatDate(res.data.license_expires_on),
        national_id: res.data.national_id || '',
        notes: res.data.notes || '',
        national_id_image_path: res.data.national_id_image_path || '',
        national_id_back_path: res.data.national_id_back_path || '',
        license_image_path: res.data.license_image_path || '',
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
    if (!customer) return
    setSaving(true)
    setFieldErrors({})
    try {
      const res = await api<{ data: Customer; message: string }>(`/customers/${customer.id}`, {
        method: 'PUT',
        json: form,
      })
      toast.success(res.message || 'تم التحديث.')
      setEditing(false)
      await load()
    } catch (e) {
      const apiErr = e as ApiError
      const next: Record<string, string> = {}
      if (apiErr.errors) for (const [k, v] of Object.entries(apiErr.errors)) next[k] = v[0]
      setFieldErrors(next)
      toast.error(apiErr.message)
    } finally {
      setSaving(false)
    }
  }

  async function setBlacklist(on: boolean) {
    if (!customer) return
    setSaving(true)
    try {
      await api(`/customers/${customer.id}`, {
        method: 'PUT',
        json: {
          status: on ? 'blacklist' : 'active',
          blacklist_reason: on ? (blacklistReason || 'إدراج يدوي') : null,
        },
      })
      toast.success(on ? 'أُدرج في القائمة السوداء.' : 'أُزيل من القائمة السوداء.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !customer) {
    return (
      <div className="rpm-stack">
        <ErrorState title="تعذّر تحميل العميل" description={error || 'غير موجود'} action={<Button onClick={() => navigate('/customers')}>رجوع</Button>} />
      </div>
    )
  }

  const statusPill = (
    <StatusPill
      status={customer.status === 'blacklist' ? 'cancelled' : 'active'}
      tone={customer.status === 'blacklist' ? 'danger' : 'success'}
      label={customer.status === 'blacklist' ? 'قائمة سوداء' : 'نشط'}
    />
  )

  return (
    <div className="rpm-stack">
      <PageHeader
        title={customer.full_name}
        subtitle="ملف العميل · العقود · المخالفات · المستندات"
        action={
          <>
            <Button variant="secondary" onClick={() => navigate('/customers')}>رجوع</Button>
            <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
            {customer.status === 'blacklist' ? (
              <Button loading={saving} onClick={() => void setBlacklist(false)}>إزالة من السوداء</Button>
            ) : (
              <Button variant="danger" loading={saving} onClick={() => void setBlacklist(true)}>قائمة سوداء</Button>
            )}
          </>
        }
      />

      <DetailList
        rows={[
          { label: 'الحالة', value: statusPill },
          { label: 'الهاتف', value: <span dir="ltr">{customer.phone}</span> },
          { label: 'هاتف بديل', value: customer.phone_alt || '—' },
          { label: 'البريد', value: customer.email || '—' },
          { label: 'الرقم القومي', value: customer.national_id || '—' },
          { label: 'تاريخ الميلاد', value: formatDate(customer.birth_date) },
          { label: 'رقم الرخصة', value: customer.license_number },
          { label: 'انتهاء الرخصة', value: formatDate(customer.license_expires_on) },
          { label: 'سبب القائمة السوداء', value: customer.blacklist_reason || '—', wide: true },
          { label: 'ملاحظات', value: customer.notes || '—', wide: true },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستندات</h3></div>
        <div className="rpm-form-grid">
          <MediaUpload label="بطاقة — وش" value={customer.national_id_image_path || null} collection="customers_id" purpose="national_id" disabled onChange={() => undefined} />
          <MediaUpload label="بطاقة — ضهر" value={customer.national_id_back_path || null} collection="customers_id" purpose="national_id_back" disabled onChange={() => undefined} />
          <MediaUpload label="رخصة — وش" value={customer.license_image_path || null} collection="customers_id" purpose="license" disabled onChange={() => undefined} />
          <MediaUpload label="رخصة — ضهر" value={customer.license_back_path || null} collection="customers_id" purpose="license_back" disabled onChange={() => undefined} />
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>العقود</h3></div>
        {(customer.contracts || []).length === 0 ? <p className="muted">لا عقود بعد.</p> : (
          <div className="rpm-card-list">
            {(customer.contracts || []).map((c) => (
              <article key={c.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong><Link to={`/bookings/${c.id}`}>{c.code}</Link></strong>
                  <StatusPill status={c.status} label={c.status} />
                </div>
                <p className="muted">
                  {c.vehicle ? `${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}` : '—'}
                  {' · '}
                  {formatDate(c.starts_at)} → {formatDate(c.planned_ends_at)}
                  {c.daily_rate != null ? ` · ${formatMoney(c.daily_rate)}/يوم` : ''}
                </p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المخالفات</h3></div>
        {(customer.violations || []).length === 0 ? <p className="muted">لا مخالفات.</p> : (
          <div className="rpm-card-list">
            {(customer.violations || []).map((v) => (
              <article key={v.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{formatMoney(v.amount)}</strong>
                  <span>{formatDate(v.occurred_at)}</span>
                </div>
                <p className="muted">
                  {v.vehicle ? `${v.vehicle.brand} ${v.vehicle.model}` : '—'}
                  {v.contract?.code ? ` · ${v.contract.code}` : ''}
                  {v.location ? ` · ${v.location}` : ''}
                </p>
              </article>
            ))}
          </div>
        )}
      </section>

      {customer.status !== 'blacklist' ? (
        <div className="rpm-form-grid">
          <TextAreaField label="سبب الإدراج في القائمة السوداء" value={blacklistReason} onChange={(e) => setBlacklistReason(e.target.value)} />
        </div>
      ) : null}

      <Sheet
        open={editing}
        title="تعديل العميل"
        onClose={() => setEditing(false)}
        footer={<><Button loading={saving} onClick={() => void onUpdate()}>حفظ</Button><Button variant="secondary" onClick={() => setEditing(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <TextField label="الاسم الكامل" required error={fieldErrors.full_name} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          <TextField label="الهاتف" required error={fieldErrors.phone} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <TextField label="هاتف بديل" value={form.phone_alt} onChange={(e) => setForm({ ...form, phone_alt: e.target.value })} />
          <TextField label="الرقم القومي" error={fieldErrors.national_id} value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
          <TextField label="تاريخ الميلاد" required type="date" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          <TextField label="رقم الرخصة" required value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value })} />
          <TextField label="انتهاء الرخصة" required type="date" value={form.license_expires_on} onChange={(e) => setForm({ ...form, license_expires_on: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <MediaUpload label="بطاقة — وش" value={form.national_id_image_path || null} collection="customers_id" purpose="national_id" accept="image" attachableType="customer" attachableId={customer.id} onChange={(path) => setForm({ ...form, national_id_image_path: path || '' })} />
          <MediaUpload label="بطاقة — ضهر" value={form.national_id_back_path || null} collection="customers_id" purpose="national_id_back" accept="image" attachableType="customer" attachableId={customer.id} onChange={(path) => setForm({ ...form, national_id_back_path: path || '' })} />
          <MediaUpload label="رخصة — وش" value={form.license_image_path || null} collection="customers_id" purpose="license" accept="image" attachableType="customer" attachableId={customer.id} onChange={(path) => setForm({ ...form, license_image_path: path || '' })} />
          <MediaUpload label="رخصة — ضهر" value={form.license_back_path || null} collection="customers_id" purpose="license_back" accept="image" attachableType="customer" attachableId={customer.id} onChange={(path) => setForm({ ...form, license_back_path: path || '' })} />
        </div>
      </Sheet>
    </div>
  )
}
