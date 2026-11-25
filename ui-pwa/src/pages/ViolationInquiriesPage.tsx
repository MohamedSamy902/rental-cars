import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { todayCivilIso } from '@/shared/lib/dates'
import { formatDate, toApiDateTime } from '@/shared/lib/format'
import {
  PageHeader, FilterBar, Button, DataTable, useToast, IconCheck, IconClipboard, Sheet,
  SelectField, MoneyField, DateField, MediaUpload, type DataColumn,
} from '@/shared/ui'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import './pages.css'
import './ViolationInquiriesPage.css'

type InquiryRow = {
  vehicle_id: number
  category?: string | null
  plate: string
  brand?: string | null
  model?: string | null
  code?: string | null
  owner_name?: string | null
  owner_phone?: string | null
  owner_national_id?: string | null
  rented_today: boolean
  inquired_today: boolean
  inquired_on?: string
  inquired_by_name?: string | null
}

type Meta = {
  date: string
  total: number
  done_today: number
  pending: number
  rented_today: number
  auto_daily_reset?: boolean
}

type ContractOption = {
  id: number
  number?: string
  code?: string
  status: string
  starts_at: string
  vehicle_id?: number
  customer?: { id: number; full_name: string }
  vehicle?: { id: number; brand: string; model: string; plate: string }
}

const Tabs = ['pending', 'done_today', 'rented_today', 'all'] as const

function CopyableValue({
  value,
  label,
  dir,
  onCopy,
}: {
  value?: string | null
  label: string
  dir?: 'ltr' | 'rtl'
  onCopy: (text: string, label: string) => void
}) {
  const text = (value || '').trim()
  const empty = text === ''
  return (
    <span className="viq-copy">
      <span className="viq-copy__text" dir={dir}>
        {empty ? '—' : text}
      </span>
      {!empty ? (
        <button
          type="button"
          className="rpm-icon-btn viq-copy__btn"
          aria-label={`نسخ ${label}`}
          title={`نسخ ${label}`}
          onClick={(e) => {
            e.stopPropagation()
            onCopy(text, label)
          }}
        >
          <IconClipboard size={14} />
        </button>
      ) : null}
    </span>
  )
}

function contractOption(c: ContractOption): { value: string; label: string; description?: string } {
  const raw = (c.number || c.code || '').trim()
  const no = raw || `CNT-${String(c.id).padStart(5, '0')}`
  const customer = c.customer?.full_name || 'عميل'
  const day = formatDate(c.starts_at)
  const car = c.vehicle
    ? `${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`.trim()
    : ''
  return {
    value: String(c.id),
    label: `${no} · ${customer}`,
    description: car ? `${day} · ${car}` : day,
  }
}

export function ViolationInquiriesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<InquiryRow[]>([])
  const [meta, setMeta] = useState<Meta | null>(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [markingId, setMarkingId] = useState<number | null>(null)
  const [resetting, setResetting] = useState(false)
  const [chip, setChip] = useQueryTab({ allowed: Tabs, defaultValue: 'pending' })
  const autoDailyReset = meta?.auto_daily_reset ?? false

  const [createFor, setCreateFor] = useState<InquiryRow | null>(null)
  const [contracts, setContracts] = useState<ContractOption[]>([])
  const [saving, setSaving] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [form, setForm] = useState({
    contract_id: '',
    occurred_at: todayCivilIso(),
    amount: '',
    photo_paths: ['', ''] as string[],
  })

  const chips = useMemo(
    () => [
      {
        id: 'pending',
        label: 'لم يُستعلم',
        attention: (meta?.pending ?? 0) > 0 ? ('warning' as const) : null,
        attentionLabel: `${meta?.pending ?? 0} بانتظار الاستعلام`,
      },
      { id: 'done_today', label: autoDailyReset ? 'تم الاستعلام اليوم' : 'تم الاستعلام', attention: null },
      {
        id: 'rented_today',
        label: 'مؤجرة اليوم',
        attention: (meta?.rented_today ?? 0) > 0 ? ('warning' as const) : null,
        attentionLabel: `${meta?.rented_today ?? 0} مؤجرة اليوم`,
      },
      { id: 'all', label: 'الكل' },
    ],
    [meta, autoDailyReset],
  )

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const qs = new URLSearchParams({ filter: chip })
      const res = await api<{ data: InquiryRow[]; meta: Meta }>(`/violation-inquiries?${qs}`)
      setRows(res.data)
      setMeta(res.meta)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }, [chip, toast])

  useEffect(() => {
    void load()
  }, [load])

  const visible = useMemo(() => {
    const q = search.trim()
    if (!q) return rows
    return rows.filter((r) => {
      const hay = `${r.plate} ${r.category ?? ''} ${r.owner_name ?? ''} ${r.owner_phone ?? ''} ${r.owner_national_id ?? ''}`
      return hay.includes(q)
    })
  }, [rows, search])

  function copyValue(text: string, label: string) {
    void navigator.clipboard?.writeText(text).then(
      () => toast.success(`تم نسخ ${label}.`),
      () => toast.error('تعذّر النسخ.'),
    )
  }

  async function markDone(row: InquiryRow) {
    if (row.inquired_today) return
    setMarkingId(row.vehicle_id)
    try {
      const res = await api<{ data: InquiryRow; message?: string }>(
        `/violation-inquiries/${row.vehicle_id}/mark`,
        { method: 'POST', json: {} },
      )
      toast.success(res.message || 'تم تسجيل الاستعلام.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setMarkingId(null)
    }
  }

  async function resetAll() {
    setResetting(true)
    try {
      const res = await api<{ data: { cleared: number }; message?: string }>(
        '/violation-inquiries/reset-all',
        { method: 'POST', json: {} },
      )
      toast.success(res.message || 'أُعيدت اللوحات لبانتظار الاستعلام.')
      setChip('pending')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setResetting(false)
    }
  }

  async function openCreate(row: InquiryRow) {
    setCreateFor(row)
    setFieldErrors({})
    setForm({
      contract_id: '',
      occurred_at: todayCivilIso(),
      amount: '',
      photo_paths: ['', ''],
    })
    try {
      const statuses = ['active', 'approved', 'completed']
      const batches = await Promise.all(
        statuses.map((status) =>
          api<{ data: ContractOption[] }>(`/contracts?view=options&per_page=100&status=${status}&vehicle_id=${row.vehicle_id}`)
            .catch(() => ({ data: [] as ContractOption[] })),
        ),
      )
      const byId = new Map<number, ContractOption>()
      for (const batch of batches) {
        for (const c of batch.data) {
          const vid = c.vehicle_id ?? c.vehicle?.id
          if (vid && vid !== row.vehicle_id) continue
          const raw = (c.number || c.code || '').trim().toUpperCase()
          if (raw.startsWith('DEMO-') || c.status === 'cancelled') continue
          byId.set(c.id, c)
        }
      }
      // Fallback: all operational contracts if vehicle filter unsupported
      let list = [...byId.values()]
      if (list.length === 0) {
        const all = await api<{ data: ContractOption[] }>('/contracts?view=options&per_page=100&status=active')
          .catch(() => ({ data: [] as ContractOption[] }))
        list = all.data.filter((c) => {
          const vid = c.vehicle_id ?? c.vehicle?.id
          const raw = (c.number || c.code || '').trim().toUpperCase()
          return vid === row.vehicle_id && !raw.startsWith('DEMO-')
        })
      }
      list.sort((a, b) => b.id - a.id)
      setContracts(list)
      if (list[0]) {
        setForm((f) => ({ ...f, contract_id: String(list[0].id) }))
      }
    } catch (e) {
      toast.error((e as ApiError).message)
      setContracts([])
    }
  }

  async function saveViolation() {
    if (!createFor) return
    setSaving(true)
    setFieldErrors({})
    try {
      const photos = form.photo_paths.map((p) => p.trim()).filter(Boolean)
      await api('/violations', {
        method: 'POST',
        json: {
          contract_id: Number(form.contract_id),
          vehicle_id: createFor.vehicle_id,
          occurred_at: toApiDateTime(form.occurred_at) || form.occurred_at,
          amount: Number(form.amount || 0),
          photo_paths: photos.length ? photos : undefined,
        },
      })
      if (!createFor.inquired_today) {
        await api(`/violation-inquiries/${createFor.vehicle_id}/mark`, { method: 'POST', json: {} })
      }
      toast.success('تم تسجيل المخالفة واعتبار الاستعلام منجزاً.')
      setCreateFor(null)
      await load()
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) {
        for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      }
      setFieldErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  // الترتيب الملزم: نوع · رقم · قومي · اسم المالك · موبايل · تم الاستعلام
  const columns: DataColumn<InquiryRow>[] = [
    {
      id: 'category',
      header: 'نوع العربية',
      cell: (r): ReactNode => (
        <CopyableValue value={r.category} label="نوع العربية" onCopy={copyValue} />
      ),
      sortValue: (r) => r.category,
      mobile: 'badge',
    },
    {
      id: 'plate',
      header: 'رقم العربية',
      cell: (r): ReactNode => (
        <CopyableValue value={r.plate} label="رقم العربية" onCopy={copyValue} />
      ),
      sortValue: (r) => r.plate,
      mobile: 'title',
    },
    {
      id: 'national_id',
      header: 'الرقم القومي',
      cell: (r): ReactNode => (
        <CopyableValue value={r.owner_national_id} label="الرقم القومي" dir="ltr" onCopy={copyValue} />
      ),
      sortValue: (r) => r.owner_national_id,
      mobile: 'field',
    },
    {
      id: 'owner',
      header: 'اسم المالك',
      cell: (r): ReactNode => (
        <CopyableValue value={r.owner_name} label="اسم المالك" onCopy={copyValue} />
      ),
      sortValue: (r) => r.owner_name,
      mobile: 'subtitle',
    },
    {
      id: 'phone',
      header: 'رقم موبايل المالك',
      cell: (r): ReactNode => (
        <CopyableValue value={r.owner_phone} label="موبايل المالك" dir="ltr" onCopy={copyValue} />
      ),
      sortValue: (r) => r.owner_phone,
      mobile: 'field',
    },
    {
      id: 'mark',
      header: 'الاستعلام',
      cell: (r): ReactNode => (
        <div className="viq-actions">
          <Button
            size="sm"
            variant={r.inquired_today ? 'secondary' : undefined}
            disabled={r.inquired_today || markingId === r.vehicle_id}
            loading={markingId === r.vehicle_id}
            onClick={() => void markDone(r)}
          >
            {r.inquired_today ? (r.inquired_by_name ? `تم · ${r.inquired_by_name}` : 'تم الاستعلام') : 'تم الاستعلام'}
          </Button>
          <Button size="sm" variant="secondary" onClick={() => void openCreate(r)}>
            تسجيل مخالفة
          </Button>
        </div>
      ),
      mobile: 'field',
      wide: true,
    },
  ]

  return (
    <div className="rpm-stack viq-page">
      <PageHeader
        title="استعلامات عن المخالفات"
        subtitle={
          autoDailyReset
            ? `متابعة يومية للوحات — اليوم ${meta?.date || '…'} · يُعاد الاستعلام كل يوم`
            : `متابعة اللوحات — اليوم ${meta?.date || '…'} · الإرجاع اليومي التلقائي موقّف · استخدم «إعادة الكل للاستعلام»`
        }
        action={
          <Button
            variant="secondary"
            loading={resetting}
            disabled={resetting || (meta?.done_today ?? 0) === 0}
            onClick={() => void resetAll()}
          >
            إعادة الكل للاستعلام
          </Button>
        }
      />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث باللوحة أو المالك أو الرقم القومي…"
      />
      <DataTable
        label="استعلامات المخالفات"
        rows={visible}
        columns={columns}
        rowKey={(r) => r.vehicle_id}
        rowLabel={(r) => `استعلام · ${r.plate}`}
        loading={loading}
        defaultSort={{ id: 'plate', dir: 'asc' }}
        rowActions={(r) => [
          ...(!r.inquired_today
            ? [{
                id: 'mark',
                label: 'تم الاستعلام',
                icon: <IconCheck size={16} />,
                onSelect: () => void markDone(r),
              }]
            : []),
          {
            id: 'add-violation',
            label: 'تسجيل مخالفة',
            onSelect: () => void openCreate(r),
          },
        ]}
        emptyTitle="لا عربيات في هذا الفلتر"
        emptyDescription="غيّر الفلتر أو اربط العربيات بمالك لإظهار القومي والموبايل."
      />

      <Sheet
        open={!!createFor}
        title={createFor ? `مخالفة · ${createFor.plate}` : 'مخالفة جديدة'}
        onClose={() => setCreateFor(null)}
        footer={
          <>
            <Button loading={saving} onClick={() => void saveViolation()}>حفظ</Button>
            <Button variant="secondary" onClick={() => setCreateFor(null)}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <p className="muted" style={{ margin: 0 }}>
            {createFor
              ? `${createFor.brand || ''} ${createFor.model || ''} · ${createFor.plate}`.trim()
              : ''}
            {' · بعد الحفظ يُسجَّل الاستعلام تلقائياً'}
          </p>
          <SelectField
            label="الحجز"
            required
            error={fieldErrors.contract_id}
            options={[
              { value: '', label: 'اختر الحجز…' },
              ...contracts.map((c) => contractOption(c)),
            ]}
            value={form.contract_id}
            onChange={(e) => setForm({ ...form, contract_id: e.target.value })}
          />
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
          />
          {form.photo_paths.map((path, index) => (
            <MediaUpload
              key={`photo-${index}`}
              label={index === 0 ? 'صور المخالفة' : `صورة إضافية ${index + 1}`}
              accept="both"
              collection="violations"
              purpose="ticket"
              value={path || null}
              onChange={(p) => {
                const next = [...form.photo_paths]
                next[index] = p || ''
                if (index === next.length - 1 && p && next.length < 5) next.push('')
                setForm({ ...form, photo_paths: next })
              }}
            />
          ))}
        </div>
      </Sheet>
    </div>
  )
}
