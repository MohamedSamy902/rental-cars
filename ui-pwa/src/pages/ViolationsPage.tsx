import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, SelectField, TextField, MoneyField, StatusPill, useToast, DataTable, IconCheck,
  MediaUpload, type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Violation = {
  id: number
  amount: number
  occurred_at: string
  status: string
  location?: string
  vehicle?: { brand: string; model: string; plate: string }
  customer?: { full_name: string }
}

type Vehicle = { id: number; brand: string; model: string; plate: string }

const statusLabel: Record<string, string> = {
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

const columns: DataColumn<Violation>[] = [
  {
    id: 'vehicle',
    header: 'المركبة',
    cell: (r) => (
      <>
        <strong className="rpm-table__primary">{r.vehicle?.brand} {r.vehicle?.model}</strong>
        <span className="rpm-table__sub">{r.vehicle?.plate}</span>
      </>
    ),
    sortValue: (r) => `${r.vehicle?.brand ?? ''} ${r.vehicle?.model ?? ''}`,
    mobile: 'title',
  },
  { id: 'customer', header: 'العميل', cell: (r) => r.customer?.full_name || 'بلا إسناد بعد', sortValue: (r) => r.customer?.full_name, mobile: 'subtitle' },
  { id: 'date', header: 'الوقت', cell: (r) => formatDateTime(r.occurred_at), sortValue: (r) => r.occurred_at },
  { id: 'location', header: 'المكان', cell: (r) => r.location || '—', sortValue: (r) => r.location },
  { id: 'amount', header: 'المبلغ', cell: (r) => formatMoney(r.amount), sortValue: (r) => Number(r.amount) },
  {
    id: 'status',
    header: 'الحالة',
    cell: (r) => <StatusPill status={r.status === 'confirmed' ? 'completed' : 'pending'} label={statusLabel[r.status] || r.status} />,
    sortValue: (r) => r.status,
    mobile: 'badge',
  },
]

export function ViolationsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Violation[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ vehicle_id: '', occurred_at: '', amount: '', location: '', photo_path: '' })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const openCount = useMemo(
    () => rows.filter((r) => !['paid', 'confirmed'].includes(r.status)).length,
    [rows],
  )
  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'open',
        label: 'تحتاج متابعة',
        attention: openCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${openCount} مخالفة مفتوحة`,
      },
      { id: 'new', label: 'جديدة' },
      { id: 'assigned', label: 'مسندة' },
      { id: 'confirmed', label: 'مؤكّدة' },
      { id: 'paid', label: 'مدفوعة' },
    ],
    [openCount],
  )
  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter((r) => {
      if (chip === 'open' && ['paid', 'confirmed'].includes(r.status)) return false
      if (chip !== 'all' && chip !== 'open' && r.status !== chip) return false
      if (!q) return true
      return (
        (r.vehicle?.plate || '').toLowerCase().includes(q)
        || (r.customer?.full_name || '').toLowerCase().includes(q)
        || (r.location || '').toLowerCase().includes(q)
      )
    })
  }, [rows, chip, search])

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Violation[] }>('/violations')
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function onSave() {
    setFieldErrors({})
    try {
      await api('/violations', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          occurred_at: form.occurred_at,
          amount: Number(form.amount),
          location: form.location || undefined,
          photo_path: form.photo_path || undefined,
        },
      })
      toast.success('تم التسجيل للمتابعة بلا ترحيل محاسبي.')
      setOpen(false)
      await load()
    } catch (e) {
      const apiErr = e as ApiError
      const next: Record<string, string> = {}
      if (apiErr.errors) {
        for (const [k, v] of Object.entries(apiErr.errors)) next[k] = v[0]
      }
      setFieldErrors(next)
      toast.error(apiErr.message)
    }
  }

  async function confirm(id: number) {
    try {
      await api(`/violations/${id}/confirm`, { method: 'POST', json: { collection_note: 'تم التأكيد' } })
      toast.success('تم تأكيد السداد دون قيد.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخالفات"
        subtitle="متابعة تشغيلية فقط — لا تدخل الحسابات أو إجمالي العقد"
        action={<Button onClick={async () => { setOpen(true); const v = await api<{ data: Vehicle[] }>('/vehicles'); setVehicles(v.data) }}>تسجيل مخالفة</Button>}
        mobileFab
      />
      <Fab label="تسجيل مخالفة" onClick={async () => { setOpen(true); const v = await api<{ data: Vehicle[] }>('/vehicles'); setVehicles(v.data) }} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث باللوحة أو العميل…"
      />
      <DataTable
        label="المخالفات"
        rows={visible}
        columns={columns}
        rowKey={(r) => r.id}
        rowLabel={(r) => `مخالفة · ${r.vehicle?.plate ?? ''}`}
        loading={loading}
        defaultSort={{ id: 'date', dir: 'desc' }}
        rowActions={(r) =>
          r.status !== 'confirmed'
            ? [{ id: 'confirm', label: 'تأكيد السداد', icon: <IconCheck size={16} />, onSelect: () => void confirm(r.id) }]
            : []
        }
        emptyTitle="لا توجد مخالفات"
        emptyDescription="المخالفات المسجّلة للمتابعة التشغيلية تظهر هنا."
      />
      <Sheet open={open} title="مخالفة جديدة" onClose={() => setOpen(false)} footer={<><Button onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="السيارة" required error={fieldErrors.vehicle_id} options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))} value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })} />
          <TextField label="وقت المخالفة" required error={fieldErrors.occurred_at} type="datetime-local" value={form.occurred_at} onChange={(e) => setForm({ ...form, occurred_at: e.target.value })} />
          <MoneyField label="المبلغ (للمتابعة)" required error={fieldErrors.amount} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <TextField label="المكان" error={fieldErrors.location} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          <MediaUpload
            label="صورة المخالفة"
            accept="both"
            collection="violations"
            purpose="ticket"
            error={fieldErrors.photo_path}
            value={form.photo_path || null}
            onChange={(path) => setForm({ ...form, photo_path: path || '' })}
          />
        </div>
      </Sheet>
    </div>
  )
}
