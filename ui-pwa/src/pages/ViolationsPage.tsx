import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney, toApiDateTime } from '@/shared/lib/format'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, SelectField, TextField, MoneyField, DateField, StatusPill, useToast, DataTable, IconCheck,
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
  contract?: { id: number; code?: string; number?: string } | null
}

type Vehicle = { id: number; brand: string; model: string; plate: string }
type ContractOption = {
  id: number
  number?: string
  code?: string
  status: string
  starts_at: string
  planned_ends_at: string
  customer?: { id: number; full_name: string }
  vehicle?: { id: number; brand: string; model: string; plate: string }
  vehicle_id?: number
}

function contractLabel(c: ContractOption): string {
  const raw = (c.number || c.code || '').trim()
  const no = raw || `CNT-${String(c.id).padStart(5, '0')}`
  const customer = c.customer?.full_name || 'عميل'
  const day = (c.starts_at || '').slice(0, 10) || '—'
  const car = c.vehicle
    ? `${c.vehicle.brand} ${c.vehicle.model}`.trim()
    : ''
  return car ? `${no} · ${customer} · ${day} (${car})` : `${no} · ${customer} · ${day}`
}

const statusLabel: Record<string, string> = {
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

export function ViolationsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Violation[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [contracts, setContracts] = useState<ContractOption[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('open')
  const [form, setForm] = useState({ vehicle_id: '', contract_id: '', occurred_at: '', amount: '', location: '', photo_path: '' })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const openCount = useMemo(
    () => rows.filter((r) => !['paid', 'confirmed'].includes(r.status)).length,
    [rows],
  )
  const chips = useMemo(
    () => [
      {
        id: 'open',
        label: 'مفتوحة',
        attention: openCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${openCount} مخالفة مفتوحة`,
      },
      { id: 'done', label: 'مغلقة' },
      { id: 'all', label: 'الكل' },
    ],
    [openCount],
  )
  const filtered = useMemo(() => {
    const q = search.trim()
    return rows.filter((r) => {
      if (chip === 'open' && ['paid', 'confirmed'].includes(r.status)) return false
      if (chip === 'done' && !['paid', 'confirmed'].includes(r.status)) return false
      if (!q) return true
      const hay = `${r.vehicle?.plate ?? ''} ${r.vehicle?.brand ?? ''} ${r.customer?.full_name ?? ''} ${r.contract?.code ?? ''} ${r.location ?? ''}`
      return hay.includes(q)
    })
  }, [rows, chip, search])

  const matchingContracts = useMemo(() => {
    const vehicleId = Number(form.vehicle_id || 0)
    const day = form.occurred_at
    return contracts.filter((c) => {
      const cVehicleId = c.vehicle?.id ?? Number((c as { vehicle_id?: number }).vehicle_id || 0)
      if (vehicleId && cVehicleId !== vehicleId) return false
      if (!day) return true
      const start = (c.starts_at || '').slice(0, 10)
      const end = (c.planned_ends_at || '').slice(0, 10)
      return start <= day && (!end || end >= day)
    })
  }, [contracts, form.vehicle_id, form.occurred_at])

  const columns: DataColumn<Violation>[] = [
    {
      id: 'vehicle',
      header: 'العربية',
      cell: (r) => (
        <>
          <strong className="rpm-table__primary">{r.vehicle?.brand} {r.vehicle?.model}</strong>
          <span className="rpm-table__sub">{r.vehicle?.plate}</span>
        </>
      ),
      sortValue: (r) => `${r.vehicle?.brand ?? ''} ${r.vehicle?.model ?? ''}`,
      mobile: 'title',
    },
    {
      id: 'contract',
      header: 'العقد',
      cell: (r) => r.contract?.number || r.contract?.code || '—',
      sortValue: (r) => r.contract?.number || r.contract?.code,
    },
    { id: 'customer', header: 'العميل', cell: (r) => r.customer?.full_name || 'بلا ربط بعد', sortValue: (r) => r.customer?.full_name, mobile: 'subtitle' },
    { id: 'date', header: 'تاريخ المخالفة', cell: (r) => formatDate(r.occurred_at), sortValue: (r) => r.occurred_at },
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

  async function openForm() {
    setOpen(true)
    setForm({ vehicle_id: '', contract_id: '', occurred_at: '', amount: '', location: '', photo_path: '' })
    setFieldErrors({})
    const [v, c] = await Promise.all([
      api<{ data: Vehicle[] }>('/vehicles?per_page=100'),
      api<{ data: ContractOption[] }>('/contracts?per_page=100').catch(() => api<{ data: ContractOption[] }>('/bookings?per_page=100')),
    ])
    setVehicles(v.data)
    setContracts(c.data)
  }

  async function onSave() {
    setFieldErrors({})
    try {
      await api('/violations', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          contract_id: form.contract_id ? Number(form.contract_id) : undefined,
          occurred_at: toApiDateTime(form.occurred_at) || form.occurred_at,
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

  const selectedContract = matchingContracts.find((c) => String(c.id) === form.contract_id)
    || contracts.find((c) => String(c.id) === form.contract_id)

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخالفات"
        subtitle="متابعة تشغيلية مربوطة بالعقد والعميل — بلا ترحيل محاسبي"
        action={<Button onClick={() => void openForm()}>تسجيل مخالفة</Button>}
        mobileFab
      />
      <Fab label="تسجيل مخالفة" onClick={() => void openForm()} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث باللوحة أو العميل أو رقم العقد…"
      />
      <DataTable
        label="المخالفات"
        rows={filtered}
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
      <Sheet open={open} title="مخالفة جديدة" onClose={() => setOpen(false)} footer={<><Button onClick={() => void onSave()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField
            label="العربية"
            required
            error={fieldErrors.vehicle_id}
            options={[
              { value: '', label: 'اختر عربية…' },
              ...vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` })),
            ]}
            value={form.vehicle_id}
            onChange={(e) => setForm({ ...form, vehicle_id: e.target.value, contract_id: '' })}
          />
          <DateField
            label="تاريخ المخالفة"
            required
            error={fieldErrors.occurred_at}
            value={form.occurred_at}
            onChange={(e) => setForm({ ...form, occurred_at: e.target.value })}
          />
          <SelectField
            label="العقد المرتبط"
            error={fieldErrors.contract_id}
            hint={form.vehicle_id && form.occurred_at ? 'تُعرض العقود التي تغطي تاريخ المخالفة لهذه العربية' : 'اختر العربية والتاريخ لتصفية العقود'}
            options={[
              { value: '', label: matchingContracts.length ? '— اختيار يدوي / تلقائي —' : 'لا عقود مطابقة — سيُحاول الربط تلقائياً' },
              ...matchingContracts.map((c) => ({
                value: String(c.id),
                label: contractLabel(c),
              })),
            ]}
            value={form.contract_id}
            onChange={(e) => setForm({ ...form, contract_id: e.target.value })}
          />
          {selectedContract?.customer ? (
            <p className="muted" style={{ margin: 0 }}>العميل المسؤول: {selectedContract.customer.full_name}</p>
          ) : null}
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
