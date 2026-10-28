import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, DetailList, DataTable, TextField, MoneyField,
  SelectField, TextAreaField, StatusPill, useToast, SkeletonBlock, IconEye,
  type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Balance = {
  accrued_total: number
  paid_total: number
  remaining: number
  month: string
  month_accrued: number
  month_paid: number
  vehicles_monthly_total: number
}

type Owner = {
  id: number
  full_name: string
  phone?: string | null
  phone_alt?: string | null
  national_id?: string | null
  notes?: string | null
  is_active: boolean
  vehicles_count?: number
  balance?: Balance | null
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

const emptyForm = {
  full_name: '', phone: '', national_id: '', notes: '', is_active: true,
}

export function OwnersPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Owner[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [detail, setDetail] = useState<(Owner & { vehicles?: OwnerVehicle[]; dues?: Due[]; payouts?: Payout[] }) | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [editing, setEditing] = useState(false)
  const [payoutOpen, setPayoutOpen] = useState(false)
  const [payoutForm, setPayoutForm] = useState({
    amount: '', paid_on: new Date().toISOString().slice(0, 10), payment_method: 'cash', vehicle_id: '', notes: '',
  })

  async function load() {
    setLoading(true)
    try {
      const qs = new URLSearchParams()
      if (search.trim()) qs.set('search', search.trim())
      const res = await api<{ data: Owner[] }>(`/owners?${qs}`)
      setRows(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => void load(), 250)
    return () => window.clearTimeout(t)
  }, [search])

  async function openDetail(id: number) {
    setDetailLoading(true)
    setEditing(false)
    setPayoutOpen(false)
    try {
      const res = await api<{ data: Owner & { vehicles: OwnerVehicle[]; dues: Due[]; payouts: Payout[] } }>(`/owners/${id}`)
      setDetail(res.data)
      setForm({
        full_name: res.data.full_name,
        phone: res.data.phone || '',
        national_id: res.data.national_id || '',
        notes: res.data.notes || '',
        is_active: res.data.is_active !== false,
      })
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setDetailLoading(false)
    }
  }

  async function onSave() {
    setSaving(true)
    try {
      await api('/owners', { method: 'POST', json: form })
      toast.success('تم تسجيل المالك.')
      setOpen(false)
      setForm(emptyForm)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function onUpdate() {
    if (!detail) return
    setSaving(true)
    try {
      const res = await api<{ data: Owner; message: string }>(`/owners/${detail.id}`, {
        method: 'PUT',
        json: form,
      })
      toast.success(res.message || 'تم التحديث.')
      setEditing(false)
      await openDetail(detail.id)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function onPayout() {
    if (!detail) return
    setSaving(true)
    try {
      const res = await api<{ message: string; balance: Balance }>(`/owners/${detail.id}/payouts`, {
        method: 'POST',
        json: {
          amount: Number(payoutForm.amount),
          paid_on: payoutForm.paid_on,
          payment_method: payoutForm.payment_method,
          vehicle_id: payoutForm.vehicle_id ? Number(payoutForm.vehicle_id) : undefined,
          notes: payoutForm.notes || undefined,
        },
      })
      toast.success(res.message || 'تم الصرف.')
      setPayoutOpen(false)
      setPayoutForm({
        amount: '', paid_on: new Date().toISOString().slice(0, 10), payment_method: 'cash', vehicle_id: '', notes: '',
      })
      await openDetail(detail.id)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const formFields = (
    <div className="rpm-form-grid">
      <TextField label="اسم المالك" required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
      <TextField label="الهاتف" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
      <TextField label="الرقم القومي" value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
      <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
    </div>
  )

  const ownerColumns: DataColumn<Owner>[] = [
    {
      id: 'name',
      header: 'المالك',
      cell: (o) => <strong className="rpm-table__primary">{o.full_name}</strong>,
      sortValue: (o) => o.full_name,
      mobile: 'title',
    },
    { id: 'phone', header: 'الهاتف', cell: (o) => <span dir="ltr">{o.phone || '—'}</span>, sortValue: (o) => o.phone, mobile: 'subtitle' },
    { id: 'vehicles', header: 'العربيات', cell: (o) => o.vehicles_count ?? 0, sortValue: (o) => o.vehicles_count ?? 0 },
    {
      id: 'monthly',
      header: 'الشهري',
      cell: (o) => formatMoney(o.balance?.vehicles_monthly_total || 0),
      sortValue: (o) => Number(o.balance?.vehicles_monthly_total || 0),
    },
    {
      id: 'remaining',
      header: 'المتبقي',
      cell: (o) => formatMoney(o.balance?.remaining || 0),
      sortValue: (o) => Number(o.balance?.remaining || 0),
    },
    {
      id: 'status',
      header: 'الحالة',
      cell: (o) => <StatusPill status={o.is_active ? 'active' : 'cancelled'} tone={o.is_active ? 'success' : 'muted'} label={o.is_active ? 'نشط' : 'موقوف'} />,
      sortValue: (o) => (o.is_active ? 1 : 0),
      mobile: 'badge',
    },
  ]

  const bal = detail?.balance

  return (
    <div className="rpm-stack">
      <PageHeader
        title="ملاك العربيات"
        subtitle="عربيات الغير · مبلغ شهري · سحب جزئي ورصيد متبقي"
        action={<Button onClick={() => setOpen(true)}>إضافة مالك</Button>}
        mobileFab
      />
      <Fab label="إضافة مالك" onClick={() => setOpen(true)} />
      <FilterBar search={search} onSearchChange={setSearch} placeholder="بحث بالاسم أو الهاتف…" chips={[]} activeChip="" onChipChange={() => undefined} />
      <DataTable
        label="الملاك"
        rows={rows}
        columns={ownerColumns}
        rowKey={(o) => o.id}
        rowLabel={(o) => o.full_name}
        loading={loading}
        onRowClick={(o) => void openDetail(o.id)}
        rowActions={(o) => [
          { id: 'view', label: 'كشف الحساب', icon: <IconEye size={16} />, onSelect: () => void openDetail(o.id) },
        ]}
        emptyTitle="لا ملاك بعد"
        emptyDescription="أضف مالكاً ثم اربطه من شاشة المركبات مع المبلغ الشهري"
      />

      <Sheet open={open} title="إضافة مالك" onClose={() => setOpen(false)} footer={<><Button loading={saving} onClick={() => void onSave()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        {formFields}
      </Sheet>

      <Sheet
        open={!!detail || detailLoading}
        title={detail?.full_name || 'تفاصيل المالك'}
        onClose={() => setDetail(null)}
        closeLabel="إغلاق"
        footer={
          detail ? (
            <>
              {editing ? (
                <Button loading={saving} onClick={() => void onUpdate()}>حفظ التعديل</Button>
              ) : (
                <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
              )}
              <Button onClick={() => setPayoutOpen(true)}>صرف للمالك</Button>
            </>
          ) : null
        }
      >
        {detailLoading && !detail ? <SkeletonBlock rows={5} /> : null}
        {detail && editing ? formFields : null}
        {detail && !editing ? (
          <>
            <DetailList
              rows={[
                { label: 'الهاتف', value: detail.phone || '—' },
                { label: 'الرقم القومي', value: detail.national_id || '—' },
                { label: 'المبلغ الشهري (كل العربيات)', value: formatMoney(bal?.vehicles_monthly_total || 0) },
                { label: 'مستحق إجمالي', value: formatMoney(bal?.accrued_total || 0) },
                { label: 'مصروف إجمالي', value: formatMoney(bal?.paid_total || 0) },
                { label: 'المتبقي', value: formatMoney(bal?.remaining || 0) },
                { label: `استحقاق ${bal?.month || ''}`, value: formatMoney(bal?.month_accrued || 0) },
                { label: `صرف ${bal?.month || ''}`, value: formatMoney(bal?.month_paid || 0) },
                { label: 'ملاحظات', value: detail.notes || '—' },
              ]}
            />

            <section className="rpm-panel" style={{ marginTop: '0.75rem' }}>
              <div className="rpm-panel__head"><h3>العربيات</h3></div>
              {(detail.vehicles || []).length === 0 ? <p className="muted">لا عربيات مربوطة — اربط من شاشة المركبات</p> : null}
              <div className="rpm-dense-list">
                {(detail.vehicles || []).map((v) => (
                  <div key={v.id} className="rpm-dense-row">
                    <div>
                      <strong>{v.brand} {v.model} · {v.plate}</strong>
                      <p className="muted">{v.code} · شهري {formatMoney(v.owner_monthly_amount || 0)} · من {v.owner_since || '—'}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="rpm-panel" style={{ marginTop: '0.75rem' }}>
              <div className="rpm-panel__head"><h3>سجل الصرف</h3></div>
              {(detail.payouts || []).length === 0 ? <p className="muted">لا سحوبات بعد</p> : null}
              <div className="rpm-dense-list">
                {(detail.payouts || []).map((p) => (
                  <div key={p.id} className="rpm-dense-row">
                    <div>
                      <strong>{formatMoney(p.amount)}</strong>
                      <p className="muted">{p.paid_on} · {p.payment_method}{p.vehicle ? ` · ${p.vehicle.plate}` : ''}{p.notes ? ` · ${p.notes}` : ''}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="rpm-panel" style={{ marginTop: '0.75rem' }}>
              <div className="rpm-panel__head"><h3>الاستحقاقات الشهرية</h3></div>
              <div className="rpm-dense-list">
                {(detail.dues || []).map((d) => (
                  <div key={d.id} className="rpm-dense-row">
                    <div>
                      <strong>{d.year_month} · {formatMoney(d.amount)}</strong>
                      <p className="muted">{d.vehicle ? `${d.vehicle.code} · ${d.vehicle.plate}` : '—'}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>
          </>
        ) : null}
      </Sheet>

      <Sheet
        open={payoutOpen}
        title="صرف للمالك"
        onClose={() => setPayoutOpen(false)}
        footer={<><Button loading={saving} onClick={() => void onPayout()}>تأكيد الصرف</Button><Button variant="secondary" onClick={() => setPayoutOpen(false)}>إلغاء</Button></>}
      >
        <p className="muted" style={{ marginBottom: '0.75rem' }}>
          المتبقي حالياً: {formatMoney(detail?.balance?.remaining || 0)} — يمكن سحب جزء في أي يوم من الشهر.
        </p>
        <div className="rpm-form-grid">
          <MoneyField label="المبلغ" required value={payoutForm.amount} onChange={(e) => setPayoutForm({ ...payoutForm, amount: e.target.value })} />
          <TextField label="تاريخ الصرف" type="date" required value={payoutForm.paid_on} onChange={(e) => setPayoutForm({ ...payoutForm, paid_on: e.target.value })} />
          <SelectField
            label="طريقة الدفع"
            value={payoutForm.payment_method}
            onChange={(e) => setPayoutForm({ ...payoutForm, payment_method: e.target.value })}
            options={[
              { value: 'cash', label: 'نقدي' },
              { value: 'transfer', label: 'تحويل بنكي' },
              { value: 'card', label: 'بطاقة' },
            ]}
          />
          <SelectField
            label="عربية (اختياري)"
            value={payoutForm.vehicle_id}
            onChange={(e) => setPayoutForm({ ...payoutForm, vehicle_id: e.target.value })}
            options={[
              { value: '', label: '— بدون ربط —' },
              ...(detail?.vehicles || []).map((v) => ({
                value: String(v.id),
                label: `${v.plate} · ${v.brand} ${v.model}`,
              })),
            ]}
          />
          <TextAreaField label="ملاحظات" value={payoutForm.notes} onChange={(e) => setPayoutForm({ ...payoutForm, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
