import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, StatusPill, Fab, DataTable,
  useToast, ErrorState, IconEye, IconCalendar, IconClipboard, IconCar, IconCheck, IconKey, IconWrench, IconEdit,
  type DataColumn, type MenuItem,
} from '@/shared/ui'
import { VehicleFormSheet } from '@/shared/forms/VehicleFormSheet'
import './pages.css'
import './VehiclesPage.css'

const VEHICLE_TABS = ['all', 'available', 'rented', 'reserved', 'maintenance', 'expiring'] as const
const EXPIRING_PRESETS = [1, 2, 3, 7] as const

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
  active_contract_ends_at?: string | null
  accessories?: Array<{ id?: number; name?: string | null }> | null
  purchase_value_estimate?: number | null
  color?: string | null
  transmission?: string | null
  chassis_number?: string | null
  engine_number?: string | null
  engine_capacity?: string | null
  seats?: number | null
  target_monthly_income?: number | null
}

const statusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
  expiring: 'قاربت على الانتهاء',
}

export function VehiclesPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Vehicle[]>([])
  const [summary, setSummary] = useState({ total: 0, available: 0, rented: 0, reserved: 0, maintenance: 0 })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: VEHICLE_TABS, defaultValue: 'all' })
  const [withinDays, setWithinDays] = useState(2)
  const [customDays, setCustomDays] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [editVehicle, setEditVehicle] = useState<Vehicle | null>(null)

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      { id: 'available', label: 'متاحة' },
      {
        id: 'rented',
        label: 'مؤجرة',
        attention: summary.rented > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.rented} عربية مؤجرة`,
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
        attentionLabel: 'عربيات في الصيانة',
      },
      {
        id: 'expiring',
        label: 'قاربت على الانتهاء',
        attention: 'warning' as const,
        attentionLabel: `خلال ${withinDays} يوم`,
      },
    ],
    [summary.rented, summary.reserved, summary.maintenance, withinDays],
  )

  async function load(nextSearch = search, nextChip = chip, days = withinDays) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (nextSearch.trim()) qs.set('search', nextSearch.trim())
      if (nextChip !== 'all') qs.set('status', nextChip)
      if (nextChip === 'expiring') qs.set('within_days', String(days))
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
  }, [search, chip, withinDays])

  const columns: DataColumn<Vehicle>[] = [
    {
      id: 'vehicle',
      header: 'العربية',
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
      cell: (v) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', alignItems: 'flex-start' }}>
          <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
          {chip === 'expiring' && v.active_contract_ends_at ? (
            <span className="rpm-table__sub">ينتهي {formatDate(v.active_contract_ends_at)}</span>
          ) : null}
        </div>
      ),
      sortValue: (v) => v.active_contract_ends_at || v.status,
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

  async function openEdit(v: Vehicle) {
    try {
      const res = await api<{ data: Vehicle }>(`/vehicles/${v.id}`)
      setEditVehicle(res.data)
    } catch {
      setEditVehicle(v)
    }
  }

  function vehicleMenu(v: Vehicle): MenuItem[] {
    return [
      { id: 'open', label: 'فتح الملف الكامل', icon: <IconEye size={16} />, onSelect: () => navigate(`/vehicles/${v.id}`) },
      { id: 'edit', label: 'تعديل بيانات العربية', icon: <IconEdit size={16} />, onSelect: () => void openEdit(v) },
      { id: 'calendar', label: 'تقويم العربيات', icon: <IconCalendar size={16} />, onSelect: () => navigate('/calendar') },
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

  function applyCustomDays() {
    const n = Number(customDays)
    if (!Number.isFinite(n) || n < 0 || n > 60) {
      toast.error('أدخل عدد أيام بين 0 و 60.')
      return
    }
    setWithinDays(Math.round(n))
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العربيات"
        subtitle="أسطول التشغيل — اضغط عرض لفتح الملف الكامل"
        action={<Button onClick={() => setOpen(true)}>إضافة عربية</Button>}
        mobileFab
      />
      <Fab label="إضافة عربية" onClick={() => setOpen(true)} />

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

      {chip === 'expiring' ? (
        <div className="vh-expiring-bar" role="group" aria-label="نافذة قرب انتهاء التأجير">
          <span className="vh-expiring-bar__label">خلال:</span>
          <div className="vh-expiring-bar__pills">
            {EXPIRING_PRESETS.map((d) => (
              <Button
                key={d}
                type="button"
                size="sm"
                variant={withinDays === d && customDays === '' ? 'accent' : 'secondary'}
                onClick={() => { setWithinDays(d); setCustomDays('') }}
              >
                {d === 1 ? '1 يوم' : d === 2 ? '2 يوم' : `${d} أيام`}
              </Button>
            ))}
          </div>
          <span className="vh-expiring-bar__sep" aria-hidden />
          <label className="vh-expiring-bar__custom">
            <span className="vh-expiring-bar__custom-label">مخصص:</span>
            <input
              className="vh-expiring-bar__input"
              type="number"
              min={0}
              max={60}
              value={customDays}
              placeholder={String(withinDays)}
              onChange={(e) => setCustomDays(e.target.value)}
              onBlur={() => { if (customDays !== '') applyCustomDays() }}
              onKeyDown={(e) => { if (e.key === 'Enter') applyCustomDays() }}
              aria-label="عدد الأيام المخصص"
            />
            <span className="vh-expiring-bar__unit">يوم</span>
          </label>
        </div>
      ) : null}

      {error ? (
        <ErrorState title="تعذّر تحميل العربيات" description={error} action={<Button onClick={() => load()}>إعادة المحاولة</Button>} />
      ) : (
        <DataTable
          label="العربيات"
          rows={rows}
          columns={columns}
          rowKey={(v) => v.id}
          rowLabel={(v) => `${v.brand} ${v.model} · ${v.plate}`}
          loading={loading}
          onRowClick={(v) => navigate(`/vehicles/${v.id}`)}
          rowActions={vehicleMenu}
          emptyTitle="لا توجد عربيات مطابقة"
          emptyDescription="جرّب كلمة بحث أخرى أو غيّر فلتر الحالة."
        />
      )}

      <VehicleFormSheet
        open={open}
        mode="create"
        onClose={() => setOpen(false)}
        onSaved={(id) => {
          void load()
          if (id) navigate(`/vehicles/${id}`)
        }}
      />

      <VehicleFormSheet
        open={!!editVehicle}
        mode="edit"
        vehicleId={editVehicle?.id}
        initial={editVehicle}
        onClose={() => setEditVehicle(null)}
        onSaved={() => {
          setEditVehicle(null)
          void load()
        }}
      />
    </div>
  )
}
