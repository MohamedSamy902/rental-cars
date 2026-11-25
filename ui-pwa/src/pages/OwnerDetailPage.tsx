import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { todayCivilIso } from '@/shared/lib/dates'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { DEFAULT_NATIONALITY } from '@/shared/lib/nationalities'
import { NationalityField } from '@/shared/forms/NationalityField'
import {
  PageHeader, Button, BackNavButton, Sheet, DetailList, TextField, TextAreaField, MoneyField, SelectField, MediaUpload,
  SwitchField, useToast, SkeletonBlock, ErrorState, StatusPill, MetricCardGrid, IconCash, IconKey, IconTrend,
  PhoneActions,
} from '@/shared/ui'
import './pages.css'

type Balance = {
  accrued_total: number
  earned_total?: number
  paid_total: number
  overdue?: number
  remaining: number
  vehicles_monthly_total: number
  dues_counts_from?: string | null
}

type OwnerVehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  owner_monthly_amount?: number | null
  owner_since?: string | null
  owner_agreement_starts_on?: string | null
  owner_agreement_ends_on?: string | null
  earned_completed?: number
  earned_shares?: Record<string, number>
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

type Owner = {
  id: number
  full_name: string
  phone?: string | null
  phone_alt?: string | null
  nationality?: string | null
  national_id?: string | null
  id_front_path?: string | null
  id_back_path?: string | null
  notes?: string | null
  is_active: boolean
  balance?: Balance | null
  vehicles?: OwnerVehicle[]
  payouts?: Payout[]
}

const emptyForm = {
  full_name: '', phone: '', phone_alt: '', nationality: DEFAULT_NATIONALITY, national_id: '', notes: '', is_active: true,
  id_front_path: '', id_back_path: '',
}

/** ملف المالك = بيانات + عربيات مربوطة + صرف شهري. ممنوع عقود تأجير / صيانة / مصروف / ترحيل محاسبي. */
export function OwnerDetailPage() {
  const { id } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const toast = useToast()
  const { can, isIntegrator } = useAuth()
  const canPayout = can('owners.payout') || isIntegrator
  const [owner, setOwner] = useState<Owner | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [voidingPayoutId, setVoidingPayoutId] = useState<number | null>(null)
  const [printingAgreementId, setPrintingAgreementId] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [payoutOpen, setPayoutOpen] = useState(false)
  const [payoutForm, setPayoutForm] = useState({
    amount: '', paid_on: todayCivilIso(), payment_method: 'cash', vehicle_id: '', notes: '',
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
        nationality: res.data.nationality || DEFAULT_NATIONALITY,
        national_id: res.data.national_id || '',
        notes: res.data.notes || '',
        is_active: res.data.is_active !== false,
        id_front_path: res.data.id_front_path || '',
        id_back_path: res.data.id_back_path || '',
      })
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id])

  useEffect(() => {
    if (searchParams.get('edit') === '1' && owner && !loading) {
      setEditing(true)
      const next = new URLSearchParams(searchParams)
      next.delete('edit')
      setSearchParams(next, { replace: true })
    }
  }, [searchParams, owner, loading, setSearchParams])

  async function onUpdate() {
    if (!owner) return
    if (!form.phone.trim() || !form.nationality.trim() || !form.national_id.trim() || !form.id_front_path.trim() || !form.id_back_path.trim()) {
      toast.error('الموبايل والجنسية ورقم الهوية وصور البطاقة (وش وضهر) مطلوبة')
      return
    }
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

  async function printOwnerAgreement(vehicleId: number) {
    setPrintingAgreementId(vehicleId)
    try {
      const res = await api<{ data: { html: string } }>(`/vehicles/${vehicleId}/owner-agreement/print`)
      const { printHtmlDocument } = await import('@/utils/printHtml')
      const { shellOwnerAgreementHtml } = await import('@/shared/contracts/ownerAgreementPrint')
      printHtmlDocument(shellOwnerAgreementHtml(res.data.html))
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّرت طباعة عقد المالك.')
    } finally {
      setPrintingAgreementId(null)
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

  async function onVoidPayout(p: Payout) {
    if (!owner || !canPayout) return
    const ok = window.confirm(
      `حذف صرف ${formatMoney(p.amount)} بتاريخ ${formatDate(p.paid_on)}؟\nسيتم عكسه من الحسابات وإعادة المتأخر كما قبل التسجيل.`,
    )
    if (!ok) return
    setVoidingPayoutId(p.id)
    try {
      const res = await api<{ message?: string }>(`/owners/${owner.id}/payouts/${p.id}`, { method: 'DELETE' })
      toast.success(res.message || 'تم حذف الصرف وعكسه من الحسابات.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّر حذف الصرف.')
    } finally {
      setVoidingPayoutId(null)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !owner) {
    return (
      <div className="rpm-stack">
        <ErrorState title="تعذّر تحميل المالك" description={error || 'غير موجود'} action={<BackNavButton to="/owners" label="رجوع للملاك" />} />
      </div>
    )
  }

  const balance = owner.balance
  const overdue = balance?.overdue ?? balance?.remaining ?? 0

  return (
    <div className="rpm-stack">
      <PageHeader
        title={owner.full_name}
        subtitle={
          balance?.dues_counts_from
            ? `مستحقات من ${formatDate(balance.dues_counts_from)} فصاعداً · ما قبل كده مش محسوب · بلا عقود تأجير`
            : 'ملف مالك · مستحقات عقد الإدارة · الصرف فقط (بلا عقود تأجير ولا حسابات)'
        }
        action={
          <>
            <BackNavButton to="/owners" label="رجوع للملاك" />
            <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
            {canPayout ? (
              <Button onClick={() => {
                setPayoutForm({
                  amount: String(balance?.vehicles_monthly_total || ''),
                  paid_on: todayCivilIso(),
                  payment_method: 'cash',
                  vehicle_id: '',
                  notes: '',
                })
                setPayoutOpen(true)
              }}>تسجيل صرف</Button>
            ) : null}
          </>
        }
      />

      <MetricCardGrid
        label="مستحقات المالك (عقد الإدارة)"
        items={[
          { id: 'monthly', label: 'المبلغ الشهري', value: balance?.vehicles_monthly_total || 0, format: 'money', tone: 'success', icon: <IconKey size={16} /> },
          { id: 'earned', label: 'مستحق شهور مكتملة', value: balance?.earned_total ?? balance?.accrued_total ?? 0, format: 'money', icon: <IconCash size={16} /> },
          { id: 'paid', label: 'إجمالي المصروف للمالك', value: balance?.paid_total || 0, format: 'money', icon: <IconCash size={16} /> },
          {
            id: 'overdue',
            label: 'متأخر للمالك',
            value: overdue,
            format: 'money',
            tone: overdue > 0 ? 'warning' : 'success',
            icon: <IconTrend size={16} />,
          },
        ]}
      />

      <DetailList
        rows={[
          { label: 'الهاتف', value: owner.phone ? <PhoneActions phone={owner.phone} name={owner.full_name} compact /> : '—' },
          { label: 'هاتف بديل', value: owner.phone_alt ? <PhoneActions phone={owner.phone_alt} name={owner.full_name} compact /> : '—' },
          { label: 'الجنسية', value: owner.nationality || '—' },
          { label: 'الرقم القومي / رقم الهوية', value: owner.national_id || '—' },
          { label: 'الحالة', value: <StatusPill status={owner.is_active ? 'active' : 'cancelled'} label={owner.is_active ? 'نشط' : 'موقوف'} /> },
          { label: 'ملاحظات', value: owner.notes || '—', wide: true },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستندات</h3></div>
        <div className="rpm-form-grid">
          <MediaUpload label="بطاقة — وش" value={owner.id_front_path || null} collection="owners_id" purpose="id_front" disabled onChange={() => undefined} />
          <MediaUpload label="بطاقة — ضهر" value={owner.id_back_path || null} collection="owners_id" purpose="id_back" disabled onChange={() => undefined} />
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>العربيات المربوطة</h3></div>
        {(owner.vehicles || []).length === 0 ? <p className="muted">لا عربيات مربوطة — اربط من شاشة العربيات.</p> : (
          <div className="rpm-card-list">
            {(owner.vehicles || []).map((v) => {
              const startOn = v.owner_agreement_starts_on || v.owner_since
              const shares = Object.entries(v.earned_shares || {})
              return (
                <article key={v.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong><Link to={`/vehicles/${v.id}`}>{v.brand} {v.model}</Link></strong>
                    <strong className="tabular-nums">{v.owner_monthly_amount != null ? formatMoney(v.owner_monthly_amount) : '—'}</strong>
                  </div>
                  <p className="muted">
                    {v.code} · {v.plate}
                    {' · '}بداية عقد المالك {startOn ? formatDate(startOn) : '—'}
                    {v.owner_agreement_ends_on ? ` · نهاية ${formatDate(v.owner_agreement_ends_on)}` : ''}
                  </p>
                  <p className="muted">
                    مستحق شهور مكتملة: {formatMoney(v.earned_completed || 0)}
                    {shares.length > 0
                      ? ` · ${shares.map(([ym, amt]) => `${ym}: ${formatMoney(amt)}`).join(' · ')}`
                      : ' · لا شهر مكتمل بعد'}
                  </p>
                  <div className="rpm-card__actions">
                    <Button
                      size="sm"
                      variant="secondary"
                      loading={printingAgreementId === v.id}
                      onClick={() => void printOwnerAgreement(v.id)}
                    >
                      طباعة عقد المالك
                    </Button>
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>سجل الصرف للمالك</h3></div>
        {(owner.payouts || []).length === 0 ? <p className="muted">لا مدفوعات بعد.</p> : (
          <div className="rpm-card-list">
            {(owner.payouts || []).map((p) => (
              <article key={p.id} className="rpm-card">
                <div className="rpm-card__row" style={{ alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                  <strong>{formatMoney(p.amount)}</strong>
                  <span>{formatDate(p.paid_on)}</span>
                  {canPayout ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      loading={voidingPayoutId === p.id}
                      disabled={voidingPayoutId != null && voidingPayoutId !== p.id}
                      onClick={() => void onVoidPayout(p)}
                      style={{ marginInlineStart: 'auto' }}
                    >
                      حذف الصرف
                    </Button>
                  ) : null}
                </div>
                <p className="muted">{p.payment_method}{p.vehicle ? ` · ${p.vehicle.plate}` : ''}{p.notes ? ` · ${p.notes}` : ''}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <Sheet open={editing} title="تعديل المالك" onClose={() => setEditing(false)} footer={<><Button loading={saving} onClick={() => void onUpdate()}>حفظ</Button><Button variant="secondary" onClick={() => setEditing(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="الاسم" required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          <TextField label="الهاتف" required value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <TextField label="هاتف بديل" value={form.phone_alt} onChange={(e) => setForm({ ...form, phone_alt: e.target.value })} />
          <NationalityField
            value={form.nationality}
            onChange={(nationality) => setForm({ ...form, nationality })}
          />
          <TextField label="الرقم القومي / رقم الهوية" required value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <SwitchField label="نشط" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
          <MediaUpload label="بطاقة — وش" required value={form.id_front_path || null} collection="owners_id" purpose="id_front" accept="image" onChange={(path) => setForm({ ...form, id_front_path: path || '' })} />
          <MediaUpload label="بطاقة — ضهر" required value={form.id_back_path || null} collection="owners_id" purpose="id_back" accept="image" onChange={(path) => setForm({ ...form, id_back_path: path || '' })} />
        </div>
      </Sheet>

      <Sheet open={payoutOpen} title="تسجيل صرف للمالك" onClose={() => setPayoutOpen(false)} footer={<><Button loading={saving} onClick={() => void onPayout()}>تأكيد الصرف</Button><Button variant="secondary" onClick={() => setPayoutOpen(false)}>إلغاء</Button></>}>
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
