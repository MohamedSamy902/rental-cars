import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { todayCivilIso } from '@/shared/lib/dates'
import { formatDate, formatMoney, toApiDateTime } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, FilterBar, Button, Sheet, Fab, SelectField, MoneyField, DateField, StatusPill, useToast, DataTable, IconCheck, IconEdit,
  MediaUpload, type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Violation = {
  id: number
  amount: number
  occurred_at: string
  status: string
  photo_path?: string | null
  photo_paths?: string[] | null
  vehicle_id?: number
  contract_id?: number | null
  created_by?: number | null
  created_by_name?: string | null
  creator?: { id: number; name: string } | null
  vehicle?: { id?: number; brand: string; model: string; plate: string }
  customer?: { full_name: string }
  contract?: { id: number; code?: string; number?: string } | null
}

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

function todayDateOnly(): string {
  return todayCivilIso()
}

function contractLabel(c: ContractOption): string {
  const raw = (c.number || c.code || '').trim()
  const no = raw || `CNT-${String(c.id).padStart(5, '0')}`
  const customer = c.customer?.full_name || 'عميل'
  const day = (c.starts_at || '').slice(0, 10) || '—'
  const car = c.vehicle
    ? `${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`.trim()
    : ''
  return car ? `${no} · ${customer} · ${day} (${car})` : `${no} · ${customer} · ${day}`
}

const statusLabel: Record<string, string> = {
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

const emptyForm = () => ({
  contract_id: '',
  occurred_at: todayDateOnly(),
  amount: '',
  photo_paths: ['', ''] as string[],
  status: 'new',
})

const ViolationsPageTabs = ['all', 'open', 'done'] as const

export function ViolationsPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Violation[]>([])
  const [contracts, setContracts] = useState<ContractOption[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<Violation | null>(null)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: ViolationsPageTabs, defaultValue: 'all' })
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const openCount = useMemo(
    () => rows.filter((r) => !['paid', 'confirmed'].includes(r.status)).length,
    [rows],
  )
  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'open',
        label: 'مفتوحة',
        attention: openCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${openCount} مخالفة مفتوحة`,
      },
      { id: 'done', label: 'مغلقة' },
    ],
    [openCount],
  )
  const filtered = useMemo(() => {
    const q = search.trim()
    return rows.filter((r) => {
      if (chip === 'open' && ['paid', 'confirmed'].includes(r.status)) return false
      if (chip === 'done' && !['paid', 'confirmed'].includes(r.status)) return false
      if (!q) return true
      const hay = `${r.vehicle?.plate ?? ''} ${r.vehicle?.brand ?? ''} ${r.customer?.full_name ?? ''} ${r.contract?.number ?? ''} ${r.contract?.code ?? ''} ${r.created_by_name ?? ''} ${r.creator?.name ?? ''}`
      return hay.includes(q)
    })
  }, [rows, chip, search])

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
      header: 'الحجز',
      cell: (r) => r.contract?.number || r.contract?.code || '—',
      sortValue: (r) => r.contract?.number || r.contract?.code,
    },
    { id: 'customer', header: 'العميل', cell: (r) => r.customer?.full_name || 'بلا ربط بعد', sortValue: (r) => r.customer?.full_name, mobile: 'subtitle' },
    { id: 'date', header: 'تاريخ المخالفة', cell: (r) => formatDate(r.occurred_at), sortValue: (r) => r.occurred_at },
    { id: 'amount', header: 'المبلغ', cell: (r) => formatMoney(r.amount), sortValue: (r) => Number(r.amount) },
    {
      id: 'uploader',
      header: 'رفعها',
      cell: (r) => r.created_by_name || r.creator?.name || '—',
      sortValue: (r) => r.created_by_name || r.creator?.name,
    },
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

  async function ensureLookups() {
    const c = await api<{ data: ContractOption[] }>('/contracts?per_page=100').catch(() => api<{ data: ContractOption[] }>('/bookings?per_page=100'))
    setContracts(c.data)
  }

  function photosFromRow(row: Violation): string[] {
    const list = (row.photo_paths && row.photo_paths.length > 0)
      ? row.photo_paths
      : (row.photo_path ? [row.photo_path] : [])
    const next = [...list]
    if (next.length < 5 && (next.length === 0 || next.every(Boolean))) next.push('')
    while (next.length < 2) next.push('')
    return next.slice(0, 5)
  }

  async function openCreate() {
    setEditing(null)
    setForm(emptyForm())
    setFieldErrors({})
    setOpen(true)
    await ensureLookups()
  }

  async function openEdit(row: Violation) {
    setEditing(row)
    setForm({
      contract_id: row.contract_id || row.contract?.id ? String(row.contract_id || row.contract?.id) : '',
      occurred_at: (row.occurred_at || '').slice(0, 10) || todayDateOnly(),
      amount: String(row.amount ?? ''),
      photo_paths: photosFromRow(row),
      status: row.status || 'new',
    })
    setFieldErrors({})
    setOpen(true)
    await ensureLookups()
  }

  function setPhotoAt(index: number, path: string | null) {
    setForm((prev) => {
      const next = [...prev.photo_paths]
      next[index] = path || ''
      const cleaned = next.filter((p, i) => p || i < 2)
      if (cleaned.filter(Boolean).length < 5 && cleaned.every(Boolean)) cleaned.push('')
      while (cleaned.length < 2) cleaned.push('')
      return { ...prev, photo_paths: cleaned.slice(0, 5) }
    })
  }

  async function onSave() {
    setFieldErrors({})
    setSaving(true)
    try {
      const selected = contracts.find((c) => String(c.id) === form.contract_id)
      const vehicleId = selected?.vehicle?.id ?? selected?.vehicle_id
      const photo_paths = form.photo_paths.map((p) => p.trim()).filter(Boolean)
      const payload = {
        contract_id: form.contract_id ? Number(form.contract_id) : null,
        vehicle_id: vehicleId ? Number(vehicleId) : undefined,
        occurred_at: toApiDateTime(form.occurred_at) || form.occurred_at,
        amount: Number(form.amount),
        photo_path: photo_paths[0] || undefined,
        photo_paths,
        ...(editing ? { status: form.status } : {}),
      }
      if (editing) {
        await api(`/violations/${editing.id}`, { method: 'PUT', json: payload })
        toast.success('تم تحديث المخالفة.')
      } else {
        await api('/violations', { method: 'POST', json: payload })
        toast.success('تم التسجيل للمتابعة بلا ترحيل محاسبي.')
      }
      setOpen(false)
      setEditing(null)
      await load()
    } catch (e) {
      const apiErr = e as ApiError
      const next: Record<string, string> = {}
      if (apiErr.errors) {
        for (const [k, v] of Object.entries(apiErr.errors)) next[k] = v[0]
      }
      setFieldErrors(next)
      toast.error(apiErr.message)
    } finally {
      setSaving(false)
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

  const selectedContract = contracts.find((c) => String(c.id) === form.contract_id)

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخالفات"
        subtitle="متابعة تشغيلية على الحجز — بلا ترحيل محاسبي"
        action={<Button onClick={() => void openCreate()}>تسجيل مخالفة</Button>}
        mobileFab
      />
      <Fab label="تسجيل مخالفة" onClick={() => void openCreate()} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث باللوحة أو العميل أو رقم الحجز أو من رفعها…"
      />
      <DataTable
        label="المخالفات"
        rows={filtered}
        columns={columns}
        rowKey={(r) => r.id}
        rowLabel={(r) => `مخالفة · ${r.vehicle?.plate ?? ''}`}
        loading={loading}
        defaultSort={{ id: 'date', dir: 'desc' }}
        rowActions={(r) => [
          { id: 'edit', label: 'تعديل', icon: <IconEdit size={16} />, onSelect: () => void openEdit(r) },
          ...(r.status !== 'confirmed'
            ? [{ id: 'confirm', label: 'تأكيد السداد', icon: <IconCheck size={16} />, onSelect: () => void confirm(r.id) }]
            : []),
        ]}
        emptyTitle="لا توجد مخالفات"
        emptyDescription="المخالفات المسجّلة على الحجوزات تظهر هنا."
      />
      <Sheet
        open={open}
        title={editing ? 'تعديل المخالفة' : 'مخالفة جديدة'}
        onClose={() => { setOpen(false); setEditing(null) }}
        footer={
          <>
            <Button loading={saving} onClick={() => void onSave()}>{editing ? 'حفظ التعديلات' : 'حفظ'}</Button>
            <Button variant="secondary" onClick={() => { setOpen(false); setEditing(null) }}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <SelectField
            label="الحجز"
            required
            error={fieldErrors.contract_id}
            hint="تُربط المخالفة بالحجز والعميل والعربية تلقائياً"
            options={[
              { value: '', label: 'اختر الحجز…' },
              ...contracts.map((c) => ({
                value: String(c.id),
                label: contractLabel(c),
              })),
            ]}
            value={form.contract_id}
            onChange={(e) => setForm({ ...form, contract_id: e.target.value })}
          />
          {selectedContract ? (
            <p className="muted" style={{ margin: 0 }}>
              {selectedContract.customer?.full_name || 'عميل'}
              {selectedContract.vehicle
                ? ` · ${selectedContract.vehicle.brand} ${selectedContract.vehicle.model} · ${selectedContract.vehicle.plate}`
                : ''}
            </p>
          ) : null}
          <DateField
            label="تاريخ المخالفة"
            required
            error={fieldErrors.occurred_at}
            value={form.occurred_at}
            onChange={(e) => setForm({ ...form, occurred_at: e.target.value })}
          />
          <MoneyField
            label="المبلغ"
            required
            error={fieldErrors.amount}
            value={form.amount}
            onChange={(e) => setForm({ ...form, amount: e.target.value })}
            hint="للمتابعة فقط — يظهر كمبلغ مطلوب عند استلام العربية"
          />
          {form.photo_paths.map((path, index) => (
            <MediaUpload
              key={`photo-${index}`}
              label={index === 0 ? 'صور المخالفة' : `صورة إضافية ${index + 1}`}
              accept="both"
              collection="violations"
              purpose="ticket"
              error={index === 0 ? (fieldErrors.photo_path || fieldErrors.photo_paths || fieldErrors['photo_paths.0']) : undefined}
              value={path || null}
              onChange={(p) => setPhotoAt(index, p)}
              hint={index === 0 ? 'يمكن رفع أكثر من صورة (حتى 5)' : undefined}
            />
          ))}
          {editing ? (
            <SelectField
              label="الحالة"
              value={form.status}
              error={fieldErrors.status}
              options={[
                { value: 'new', label: statusLabel.new },
                { value: 'assigned', label: statusLabel.assigned },
                { value: 'confirmed', label: statusLabel.confirmed },
                { value: 'paid', label: statusLabel.paid },
              ]}
              onChange={(e) => setForm({ ...form, status: e.target.value })}
            />
          ) : null}
          {editing?.created_by_name || editing?.creator?.name ? (
            <p className="muted" style={{ margin: 0 }}>رفعها: {editing.created_by_name || editing.creator?.name}</p>
          ) : null}
        </div>
      </Sheet>
    </div>
  )
}
