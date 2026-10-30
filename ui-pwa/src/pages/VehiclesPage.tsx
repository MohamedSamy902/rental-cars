import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, StatusPill, Fab, DataTable,
  useToast, ErrorState, IconEye, IconCalendar, IconClipboard, IconCar, IconCheck, IconKey, IconWrench,
  type DataColumn, type MenuItem,
} from '@/shared/ui'
import { VehicleCreateSheet } from '@/shared/forms/VehicleCreateSheet'
import './pages.css'

type OwnerOption = { id: number; full_name: string; phone?: string | null }

type Vehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  odometer: number
  daily_rate: number
  default_deposit: number
  status: string
  rental_priority: number
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  notes?: string | null
  is_active?: boolean
  owner_id?: number | null
  owner_monthly_amount?: number | null
  owner_since?: string | null
  owner?: OwnerOption | null
}

const statusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
}

export function VehiclesPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Vehicle[]>([])
  const [summary, setSummary] = useState({ total: 0, available: 0, rented: 0, reserved: 0, maintenance: 0 })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      { id: 'available', label: 'متاحة' },
      {
        id: 'rented',
        label: 'مؤجرة',
        attention: summary.rented > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.rented} مركبة مؤجرة`,
      },
      {
        id: 'reserved',
        label: 'محجوزة',
        attention: summary.reserved > 0 ? ('warning' as const) : null,
        attentionLabel: 'حجوزات معتمدة',
      },
      {
        id: 'maintenance',
        label: 'صيانة',
        attention: summary.maintenance > 0 ? ('critical' as const) : null,
        attentionLabel: 'مركبات في الصيانة',
      },
    ],
    [summary.rented, summary.reserved, summary.maintenance],
  )

  async function load(nextSearch = search, nextChip = chip) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (nextSearch.trim()) qs.set('search', nextSearch.trim())
      if (nextChip !== 'all') qs.set('status', nextChip)
      const res = await api<{ data: Vehicle[]; summary: typeof summary }>(`/vehicles?${qs}`)
      setRows(res.data)
      setSummary(res.summary)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => load(), 250)
    return () => window.clearTimeout(t)
  }, [search, chip])

  const columns: DataColumn<Vehicle>[] = [
    {
      id: 'vehicle',
      header: 'المركبة',
      cell: (v) => (
        <>
          <strong className="rpm-table__primary">{v.brand} {v.model}</strong>
          <span className="rpm-table__sub">{v.year} · {v.fuel_type}</span>
        </>
      ),
      sortValue: (v) => `${v.brand} ${v.model}`,
      mobile: 'title',
    },
    {
      id: 'code',
      header: 'الكود',
      cell: (v) => <span className="rpm-table__code">{v.code}</span>,
      sortValue: (v) => v.code,
    },
    { id: 'plate', header: 'اللوحة', cell: (v) => v.plate, sortValue: (v) => v.plate },
    { id: 'category', header: 'الفئة', cell: (v) => v.category, sortValue: (v) => v.category, mobile: 'hidden' },
    {
      id: 'year',
      header: 'السنة',
      cell: (v) => <span className="tabular-nums">{v.year}</span>,
      sortValue: (v) => v.year,
      mobile: 'hidden',
    },
    {
      id: 'status',
      header: 'الحالة',
      cell: (v) => <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />,
      sortValue: (v) => v.status,
      mobile: 'badge',
    },
    {
      id: 'odometer',
      header: 'العداد',
      cell: (v) => `${formatNumber(v.odometer)} كم`,
      sortValue: (v) => Number(v.odometer),
    },
    {
      id: 'deposit',
      header: 'التأمين',
      cell: (v) => formatMoney(v.default_deposit),
      sortValue: (v) => Number(v.default_deposit),
      mobile: 'hidden',
    },
    {
      id: 'rate',
      header: 'اليومي',
      cell: (v) => formatMoney(v.daily_rate),
      sortValue: (v) => Number(v.daily_rate),
    },
    {
      id: 'owner',
      header: 'المالك',
      cell: (v) => v.owner?.full_name || 'المركز',
      sortValue: (v) => v.owner?.full_name || 'المركز',
      mobile: 'hidden',
    },
  ]

  function vehicleMenu(v: Vehicle): MenuItem[] {
    return [
      { id: 'open', label: 'فتح الملف الكامل', icon: <IconEye size={16} />, onSelect: () => navigate(`/vehicles/${v.id}`) },
      { id: 'calendar', label: 'تقويم الأسطول', icon: <IconCalendar size={16} />, onSelect: () => navigate('/calendar') },
      {
        id: 'copy',
        label: 'نسخ رقم اللوحة',
        icon: <IconClipboard size={16} />,
        onSelect: () => {
          void navigator.clipboard?.writeText(v.plate).then(
            () => toast.success('تم نسخ رقم اللوحة.'),
            () => toast.error('تعذّر النسخ.'),
          )
        },
      },
    ]
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المركبات"
        subtitle="أسطول التشغيل — اضغط عرض لفتح الملف الكامل"
        action={<Button onClick={() => setOpen(true)}>إضافة مركبة</Button>}
        mobileFab
      />
      <Fab label="إضافة مركبة" onClick={() => setOpen(true)} />

      <MetricCardGrid
        label="ملخص الأسطول"
        items={[
          { id: 'total', label: 'إجمالي الأسطول', value: summary.total, icon: <IconCar size={16} /> },
          {
            id: 'available', label: 'متاحة', value: summary.available, tone: 'success', icon: <IconCheck size={16} />,
            hint: summary.total ? `${Math.round((summary.available / summary.total) * 100)}% من الأسطول` : undefined,
          },
          {
            id: 'rented', label: 'مؤجرة', value: summary.rented, tone: 'info', icon: <IconKey size={16} />,
            hint: summary.total ? `إشغال ${Math.round((summary.rented / summary.total) * 100)}%` : undefined,
          },
          { id: 'reserved', label: 'محجوزة', value: summary.reserved, tone: 'warning', icon: <IconCalendar size={16} /> },
          { id: 'maintenance', label: 'في الصيانة', value: summary.maintenance, tone: 'danger', icon: <IconWrench size={16} /> },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالكود أو اللوحة أو الموديل…"
      />

      {error ? (
        <ErrorState title="تعذّر تحميل المركبات" description={error} action={<Button onClick={() => load()}>إعادة المحاولة</Button>} />
      ) : (
        <DataTable
          label="المركبات"
          rows={rows}
          columns={columns}
          rowKey={(v) => v.id}
          rowLabel={(v) => `${v.brand} ${v.model} · ${v.plate}`}
          loading={loading}
          onRowClick={(v) => navigate(`/vehicles/${v.id}`)}
          rowActions={vehicleMenu}
          emptyTitle="لا توجد مركبات مطابقة"
          emptyDescription="جرّب كلمة بحث أخرى أو غيّر فلتر الحالة."
        />
      )}

      <VehicleCreateSheet
        open={open}
        onClose={() => setOpen(false)}
        onSaved={(id) => {
          void load()
          if (id) navigate(`/vehicles/${id}`)
        }}
      />
    </div>
  )
}
