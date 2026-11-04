import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import { useAttentionSignals } from '@/shared/lib/useAttentionSignals'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, DataTable, TextField, TextAreaField, SwitchField,
  StatusPill, useToast, IconEye, MediaUpload, PhoneActions, type DataColumn,
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
  id_front_path?: string | null
  id_back_path?: string | null
  notes?: string | null
  is_active: boolean
  vehicles_count?: number
  balance?: Balance | null
}

const emptyForm = {
  full_name: '', phone: '', phone_alt: '', national_id: '', id_front_path: '', id_back_path: '',
  notes: '', is_active: true,
}

const OwnersPageTabs = ['all', 'due'] as const

export function OwnersPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Owner[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [chip, setChip] = useQueryTab({ allowed: OwnersPageTabs, defaultValue: 'all' })
  const { counts: attention } = useAttentionSignals(true)

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'due',
        label: 'مستحق',
        attention: attention.owners_unpaid > 0 ? ('warning' as const) : null,
        attentionLabel: `${attention.owners_unpaid} مالك له رصيد متبقٍ`,
      },
    ],
    [attention.owners_unpaid],
  )
  const visible = useMemo(
    () => (chip === 'due' ? rows.filter((o) => Number(o.balance?.remaining || 0) > 0) : rows),
    [rows, chip],
  )

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

  function mapApiErrors(e: unknown) {
    const apiErr = e as ApiError
    const next: Record<string, string> = {}
    if (apiErr.errors) {
      for (const [k, v] of Object.entries(apiErr.errors)) next[k] = v[0]
    }
    setFieldErrors(next)
    toast.error(apiErr.message)
  }

  async function onSave() {
    setSaving(true)
    setFieldErrors({})
    try {
      await api('/owners', { method: 'POST', json: form })
      toast.success('تم تسجيل المالك.')
      setOpen(false)
      setForm(emptyForm)
      await load()
    } catch (e) {
      mapApiErrors(e)
    } finally {
      setSaving(false)
    }
  }

  const formFields = (
    <div className="rpm-form-grid">
      <TextField label="اسم المالك" required error={fieldErrors.full_name} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
      <TextField label="الهاتف" error={fieldErrors.phone} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
      <TextField label="هاتف بديل" value={form.phone_alt} onChange={(e) => setForm({ ...form, phone_alt: e.target.value })} />
      <TextField label="الرقم القومي" error={fieldErrors.national_id} value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
      <MediaUpload
        label="بطاقة الرقم القومي — الوجه (وش)"
        value={form.id_front_path || null}
        collection="owner_ids"
        purpose="national_id"
        accept="both"
        onChange={(path) => setForm({ ...form, id_front_path: path || '' })}
      />
      <MediaUpload
        label="بطاقة الرقم القومي — الظهر (ضهر)"
        value={form.id_back_path || null}
        collection="owner_ids"
        purpose="national_id"
        accept="both"
        onChange={(path) => setForm({ ...form, id_back_path: path || '' })}
      />
      <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <SwitchField label="نشط" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
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
    {
      id: 'phone',
      header: 'الهاتف',
      cell: (o) => <PhoneActions phone={o.phone} name={o.full_name} compact />,
      sortValue: (o) => o.phone,
      mobile: 'subtitle',
    },
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

  return (
    <div className="rpm-stack">
      <PageHeader
        title="ملاك العربيات"
        subtitle="عربيات الغير · مبلغ شهري · سحب جزئي ورصيد متبقي"
        action={<Button onClick={() => { setForm(emptyForm); setFieldErrors({}); setOpen(true) }}>إضافة مالك</Button>}
        mobileFab
      />
      <Fab label="إضافة مالك" onClick={() => { setForm(emptyForm); setFieldErrors({}); setOpen(true) }} />
      <FilterBar search={search} onSearchChange={setSearch} placeholder="بحث بالاسم أو الهاتف…" chips={chips} activeChip={chip} onChipChange={setChip} />
      <DataTable
        label="الملاك"
        rows={visible}
        columns={ownerColumns}
        rowKey={(o) => o.id}
        rowLabel={(o) => o.full_name}
        loading={loading}
        onRowClick={(o) => navigate(`/owners/${o.id}`)}
        rowActions={(o) => [
          { id: 'view', label: 'عرض الملف', icon: <IconEye size={16} />, onSelect: () => navigate(`/owners/${o.id}`) },
          { id: 'edit', label: 'تعديل', onSelect: () => navigate(`/owners/${o.id}?edit=1`) },
        ]}
        emptyTitle="لا ملاك بعد"
        emptyDescription="أضف مالكاً ثم اربطه من شاشة العربيات مع المبلغ الشهري"
      />

      <Sheet open={open} title="إضافة مالك" onClose={() => setOpen(false)} footer={<><Button loading={saving} onClick={() => void onSave()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        {formFields}
      </Sheet>
    </div>
  )
}
