import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney, formatMonthYear, toDateOnly } from '@/shared/lib/format'
import { DEFAULT_NATIONALITY } from '@/shared/lib/nationalities'
import { NationalityField } from '@/shared/forms/NationalityField'
import { useNewContract } from '@/shared/contracts/newContractContext'
import { statusLabel } from '@/shared/contracts/contractModel'
import {
  PageHeader, Button, BackNavButton, Sheet, DetailList, TextField, TextAreaField, MediaUpload,
  useToast, SkeletonBlock, ErrorState, StatusPill, PhoneActions, MetricCardGrid,
  IconCash, IconKey, IconTrend, IconWallet,
} from '@/shared/ui'
import './pages.css'

type ContractRow = {
  id: number
  code: string
  status: string
  starts_at?: string | null
  planned_ends_at?: string | null
  actual_ends_at?: string | null
  daily_rate?: number
  deposit?: number
  period_rent?: number
  final_total?: number
  total?: number
  paid?: number
  remaining?: number
  balance_due_on?: string | null
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

type CustomerAccounts = {
  contracts_total: number
  payments_in: number
  open_receivable: number
  credit_balance: number
}

type CreditLedgerRow = {
  id: number
  direction: string
  amount: number
  reason?: string | null
  contract_id?: number | null
  moved_at?: string | null
  notes?: string | null
}

type Customer = {
  id: number
  full_name: string
  phone: string
  phone_alt?: string | null
  email?: string | null
  license_number: string
  license_issued_from?: string | null
  license_expires_on: string
  birth_date?: string
  nationality?: string | null
  national_id?: string | null
  national_id_issued_from?: string | null
  national_id_issued_on?: string | null
  address?: string | null
  national_id_image_path?: string | null
  national_id_back_path?: string | null
  license_image_path?: string | null
  license_back_path?: string | null
  status: string
  notes?: string | null
  blacklist_reason?: string | null
  credit_balance?: number
  accounts?: CustomerAccounts
  credit_ledger?: CreditLedgerRow[]
  contracts?: ContractRow[]
  violations?: ViolationRow[]
}

function creditReasonLabel(reason?: string | null) {
  if (reason === 'contract_payment') return 'سداد عقد من الرصيد'
  if (reason === 'overpayment') return 'زيادة دفع'
  if (reason === 'deposit_refund') return 'رد تأمين'
  if (reason === 'manual') return 'يدوي'
  return reason || 'حركة رصيد'
}

function creditDirectionLabel(direction: string) {
  if (direction === 'credit') return 'إضافة'
  if (direction === 'debit') return 'خصم'
  return direction
}

const emptyForm = {
  full_name: '', phone: '', phone_alt: '', birth_date: '1990-01-01', license_number: '', license_issued_from: '',
  license_expires_on: '2030-01-01',
  nationality: DEFAULT_NATIONALITY, national_id: '', national_id_issued_from: '', national_id_issued_on: '',
  address: '', notes: '',
  national_id_image_path: '', national_id_back_path: '', license_image_path: '', license_back_path: '',
}

export function CustomerDetailPage() {
  const { id } = useParams()
  const toast = useToast()
  const { openNewContract } = useNewContract()
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
        birth_date: toDateOnly(res.data.birth_date) || '1990-01-01',
        license_number: res.data.license_number,
        license_issued_from: res.data.license_issued_from || '',
        license_expires_on: toDateOnly(res.data.license_expires_on) || '2030-01-01',
        nationality: res.data.nationality || DEFAULT_NATIONALITY,
        national_id: res.data.national_id || '',
        national_id_issued_from: res.data.national_id_issued_from || '',
        national_id_issued_on: toDateOnly(res.data.national_id_issued_on) || '',
        address: res.data.address || '',
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
        <ErrorState title="تعذّر تحميل العميل" description={error || 'غير موجود'} action={<BackNavButton to="/customers" label="رجوع للعملاء" />} />
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

  const accounts: CustomerAccounts = customer.accounts ?? {
    contracts_total: 0,
    payments_in: 0,
    open_receivable: 0,
    credit_balance: customer.credit_balance ?? 0,
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title={customer.full_name}
        subtitle="ملف العميل · الحسابات · العقود · المخالفات · المستندات"
        action={
          <>
            <BackNavButton to="/customers" label="رجوع للعملاء" />
            <Button variant="accent" onClick={() => openNewContract({ customerId: customer.id })}>عقد جديد</Button>
            <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
            {customer.status === 'blacklist' ? (
              <Button loading={saving} onClick={() => void setBlacklist(false)}>إزالة من السوداء</Button>
            ) : (
              <Button variant="danger" loading={saving} onClick={() => void setBlacklist(true)}>قائمة سوداء</Button>
            )}
          </>
        }
      />

      <MetricCardGrid
        label="حسابات العميل"
        mobile="wrap"
        items={[
          {
            id: 'contracts_total',
            label: 'إجمالي العقود',
            value: accounts.contracts_total,
            format: 'money',
            icon: <IconCash size={16} />,
          },
          {
            id: 'payments_in',
            label: 'المدفوع',
            value: accounts.payments_in,
            format: 'money',
            tone: 'success',
            icon: <IconWallet size={16} />,
          },
          {
            id: 'open_receivable',
            label: 'ذمم مفتوحة',
            value: accounts.open_receivable,
            format: 'money',
            tone: accounts.open_receivable > 0 ? 'warning' : 'success',
            icon: <IconTrend size={16} />,
          },
          {
            id: 'credit_balance',
            label: 'رصيد دائن',
            value: accounts.credit_balance,
            format: 'money',
            tone: 'info',
            icon: <IconKey size={16} />,
          },
        ]}
      />

      <DetailList
        rows={[
          { label: 'الحالة', value: statusPill },
          { label: 'الهاتف', value: <PhoneActions phone={customer.phone} name={customer.full_name} compact /> },
          { label: 'هاتف بديل', value: customer.phone_alt ? <PhoneActions phone={customer.phone_alt} name={customer.full_name} compact /> : '—' },
          { label: 'البريد', value: customer.email || '—' },
          { label: 'الجنسية', value: customer.nationality || '—' },
          { label: 'الرقم القومي / رقم الهوية', value: customer.national_id || '—' },
          { label: 'صادرة من (البطاقة)', value: customer.national_id_issued_from || '—' },
          { label: 'تاريخ صدور البطاقة', value: formatMonthYear(customer.national_id_issued_on) },
          { label: 'محل الإقامة', value: customer.address || '—', wide: true },
          { label: 'تاريخ الميلاد', value: formatDate(customer.birth_date) },
          { label: 'رقم الرخصة', value: customer.license_number },
          { label: 'صادرة من (الرخصة)', value: customer.license_issued_from || '—' },
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
        <div className="rpm-panel__head">
          <h3>العقود</h3>
          <Button size="sm" onClick={() => openNewContract({ customerId: customer.id })}>عقد جديد</Button>
        </div>
        {(customer.contracts || []).length === 0 ? <p className="muted">لا عقود بعد.</p> : (
          <div className="rpm-card-list">
            {(customer.contracts || []).map((c) => {
              const total = c.total ?? c.final_total ?? c.period_rent ?? 0
              const paid = c.paid ?? 0
              const remaining = c.remaining ?? Math.max(0, total - paid)
              return (
                <article key={c.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong><Link to={`/contracts/${c.id}`}>{c.code}</Link></strong>
                    <StatusPill status={c.status} label={statusLabel[c.status] || c.status} />
                  </div>
                  <p className="muted">
                    {c.vehicle ? `${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}` : '—'}
                    {' · '}
                    {formatDate(c.starts_at)} → {formatDate(c.planned_ends_at || c.actual_ends_at)}
                    {c.daily_rate != null ? ` · ${formatMoney(c.daily_rate)}/يوم` : ''}
                  </p>
                  <div className="rpm-card__row">
                    <span className="muted">الإجمالي <strong className="tabular-nums">{formatMoney(total)}</strong></span>
                    <span className="muted">مدفوع <strong className="tabular-nums">{formatMoney(paid)}</strong></span>
                    <span className="muted">متبقي <strong className="tabular-nums">{formatMoney(remaining)}</strong></span>
                  </div>
                  {c.balance_due_on ? (
                    <p className="muted">استحقاق المتبقي {formatDate(c.balance_due_on)}</p>
                  ) : null}
                </article>
              )
            })}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>سجل الرصيد الدائن</h3></div>
        {(customer.credit_ledger || []).length === 0 ? <p className="muted">لا حركات رصيد دائن.</p> : (
          <div className="rpm-card-list">
            {(customer.credit_ledger || []).map((m) => (
              <article key={m.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong className="tabular-nums">{formatMoney(m.amount)}</strong>
                  <StatusPill
                    status={m.direction === 'credit' ? 'active' : 'cancelled'}
                    label={creditDirectionLabel(m.direction)}
                  />
                </div>
                <p className="muted">
                  {creditReasonLabel(m.reason)}
                  {m.contract_id ? ` · عقد #${m.contract_id}` : ''}
                  {m.moved_at ? ` · ${formatDate(m.moved_at)}` : ''}
                </p>
                {m.notes ? <p className="muted">{m.notes}</p> : null}
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
                {!['paid', 'confirmed'].includes(v.status) ? (
                  <div className="rpm-card__actions">
                    <Button
                      size="sm"
                      onClick={() => {
                        void api(`/violations/${v.id}/confirm`, { method: 'POST' })
                          .then(() => { toast.success('تم تأكيد السداد.'); return load() })
                          .catch((e) => toast.error((e as ApiError).message))
                      }}
                    >
                      تأكيد السداد
                    </Button>
                  </div>
                ) : null}
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
          <NationalityField
            error={fieldErrors.nationality}
            value={form.nationality}
            onChange={(nationality) => setForm({ ...form, nationality })}
          />
          <TextField label="الرقم القومي / رقم الهوية" error={fieldErrors.national_id} value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
          <TextField label="صادرة من (البطاقة)" error={fieldErrors.national_id_issued_from} value={form.national_id_issued_from} onChange={(e) => setForm({ ...form, national_id_issued_from: e.target.value })} />
          <TextField label="تاريخ صدور البطاقة" error={fieldErrors.national_id_issued_on} type="month" value={form.national_id_issued_on} onChange={(e) => setForm({ ...form, national_id_issued_on: e.target.value })} />
          <TextField label="محل الإقامة" error={fieldErrors.address} value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          <TextField label="تاريخ الميلاد" required type="date" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          <TextField label="رقم الرخصة" required value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value })} />
          <TextField label="صادرة من (الرخصة)" error={fieldErrors.license_issued_from} value={form.license_issued_from} onChange={(e) => setForm({ ...form, license_issued_from: e.target.value })} />
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
