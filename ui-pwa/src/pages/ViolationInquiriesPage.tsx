import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  PageHeader, FilterBar, Button, DataTable, StatusPill, useToast, PhoneActions, IconCheck, type DataColumn,
} from '@/shared/ui'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import './pages.css'

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
}

const Tabs = ['pending', 'done_today', 'rented_today', 'all'] as const

export function ViolationInquiriesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<InquiryRow[]>([])
  const [meta, setMeta] = useState<Meta | null>(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [markingId, setMarkingId] = useState<number | null>(null)
  const [chip, setChip] = useQueryTab({ allowed: Tabs, defaultValue: 'pending' })

  const chips = useMemo(
    () => [
      {
        id: 'pending',
        label: 'لم يُستعلم',
        attention: (meta?.pending ?? 0) > 0 ? ('warning' as const) : null,
        attentionLabel: `${meta?.pending ?? 0} بانتظار الاستعلام اليوم`,
      },
      {
        id: 'done_today',
        label: 'تم الاستعلام اليوم',
        attention: null,
      },
      {
        id: 'rented_today',
        label: 'مؤجرة اليوم',
        attention: (meta?.rented_today ?? 0) > 0 ? ('warning' as const) : null,
        attentionLabel: `${meta?.rented_today ?? 0} مؤجرة اليوم`,
      },
      { id: 'all', label: 'الكل' },
    ],
    [meta],
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
      const hay = `${r.plate} ${r.brand ?? ''} ${r.model ?? ''} ${r.category ?? ''} ${r.owner_name ?? ''} ${r.owner_phone ?? ''} ${r.owner_national_id ?? ''}`
      return hay.includes(q)
    })
  }, [rows, search])

  async function markDone(row: InquiryRow) {
    if (row.inquired_today) return
    setMarkingId(row.vehicle_id)
    try {
      const res = await api<{ data: InquiryRow; message?: string }>(
        `/violation-inquiries/${row.vehicle_id}/mark`,
        { method: 'POST', json: {} },
      )
      toast.success(res.message || 'تم تسجيل الاستعلام لليوم.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setMarkingId(null)
    }
  }

  const columns: DataColumn<InquiryRow>[] = [
    {
      id: 'category',
      header: 'نوع العربية',
      cell: (r) => r.category || '—',
      sortValue: (r) => r.category,
      mobile: 'badge',
    },
    {
      id: 'plate',
      header: 'رقم العربية',
      cell: (r) => (
        <>
          <strong className="rpm-table__primary">{r.plate}</strong>
          <span className="rpm-table__sub">{[r.brand, r.model].filter(Boolean).join(' ')}</span>
        </>
      ),
      sortValue: (r) => r.plate,
      mobile: 'title',
    },
    {
      id: 'national_id',
      header: 'الرقم القومي',
      cell: (r) => r.owner_national_id || '—',
      sortValue: (r) => r.owner_national_id,
    },
    {
      id: 'owner',
      header: 'اسم المالك',
      cell: (r) => r.owner_name || '—',
      sortValue: (r) => r.owner_name,
      mobile: 'subtitle',
    },
    {
      id: 'phone',
      header: 'موبايل المالك',
      cell: (r) => (
        r.owner_phone
          ? (
            <span className="rpm-inline-stack">
              <span dir="ltr">{r.owner_phone}</span>
              <PhoneActions phone={r.owner_phone} name={r.owner_name || undefined} />
            </span>
          )
          : '—'
      ),
      sortValue: (r) => r.owner_phone,
    },
    {
      id: 'flags',
      header: 'الحالة',
      cell: (r) => (
        <span className="rpm-inline-stack">
          {r.inquired_today
            ? <StatusPill status="completed" label={r.inquired_by_name ? `تم · ${r.inquired_by_name}` : 'تم اليوم'} />
            : <StatusPill status="pending" label="بانتظار الاستعلام" />}
          {r.rented_today ? <StatusPill status="active" label="مؤجرة اليوم" /> : null}
        </span>
      ),
      sortValue: (r) => (r.inquired_today ? 1 : 0),
      mobile: 'field',
    },
    {
      id: 'mark',
      header: 'الاستعلام',
      cell: (r) => (
        <Button
          size="sm"
          variant={r.inquired_today ? 'secondary' : undefined}
          disabled={r.inquired_today || markingId === r.vehicle_id}
          loading={markingId === r.vehicle_id}
          onClick={() => void markDone(r)}
        >
          تم الاستعلام
        </Button>
      ),
      mobile: 'field',
      wide: true,
    },
  ]

  return (
    <div className="rpm-stack">
      <PageHeader
        title="استعلامات عن المخالفات"
        subtitle={`متابعة يومية للوحات — اليوم ${meta?.date || '…'} · يُعاد الاستعلام كل يوم`}
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
        rowActions={(r) => r.inquired_today
          ? []
          : [{
              id: 'mark',
              label: 'تم الاستعلام',
              icon: <IconCheck size={16} />,
              onSelect: () => void markDone(r),
            }]}
        emptyTitle="لا عربيات في هذا الفلتر"
        emptyDescription="غيّر الفلتر أو أضف عربيات نشطة في الأسطول."
      />
    </div>
  )
}
