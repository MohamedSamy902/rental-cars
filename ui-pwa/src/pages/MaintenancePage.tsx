import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatNumber } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import { useAuth } from '@/shared/auth/AuthContext'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, Fab, DataTable, StatusPill, useToast,
  IconCheck, IconWrench, IconAlert, IconCar, type DataColumn,
} from '@/shared/ui'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
import './pages.css'
import './MaintenancePage.css'

type OilRow = {
  vehicle_id: number
  code?: string
  plate: string
  brand: string
  model: string
  odometer: number
  last_oil_change_on?: string | null
  next_oil_on?: string | null
  next_oil_odometer?: number | null
  last_oil_work_order_id?: number | null
  oil_due_soon?: boolean
}

type WorkOrder = {
  id: number
  status: string
  workshop?: string | null
  opened_on?: string | null
  opened_at?: string | null
  odometer?: number | null
  next_service_on?: string | null
  next_service_odometer?: number | null
  items?: string[]
  has_oil_change?: boolean
  vehicle?: { id: number; brand: string; model: string; plate: string; odometer?: number }
}

type Summary = {
  open: number
  closed: number
  vehicles?: number
  with_oil_history?: number
  oil_due_soon?: number
}

type PageMeta = { current_page: number; last_page: number; total: number; per_page?: number }

const statusLabel: Record<string, string> = {
  open: 'مفتوح', in_progress: 'قيد التنفيذ', closed: 'مغلق', cancelled: 'ملغى',
}

const MaintenancePageTabs = ['oil', 'upcoming', 'orders', 'open', 'closed'] as const
const LIST_PER_PAGE = 20
const isOilTab = (tab: string) => tab === 'oil' || tab === 'upcoming'

export function MaintenancePage() {
  const toast = useToast()
  const navigate = useNavigate()
  const location = useLocation()
  const { can } = useAuth()
  const canCreate = can('maintenance.create')
  const canClose = can('maintenance.close')

  const [oilRows, setOilRows] = useState<OilRow[]>([])
  const [orderRows, setOrderRows] = useState<WorkOrder[]>([])
  const [summary, setSummary] = useState<Summary>({ open: 0, closed: 0 })
  const [meta, setMeta] = useState<PageMeta>({ current_page: 1, last_page: 1, total: 0 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [sheetOpen, setSheetOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: MaintenancePageTabs, defaultValue: 'oil' })
  const [lockedVehicle, setLockedVehicle] = useState<{ id: number; label: string; odometer?: number } | undefined>()

  async function load(nextPage = page) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      qs.set('page', String(nextPage))
      qs.set('per_page', String(LIST_PER_PAGE))
      if (search.trim()) qs.set('search', search.trim())

      if (isOilTab(chip)) {
        qs.set('view', 'oil')
        qs.set('tab', chip)
        const res = await api<{ data: OilRow[]; summary: Summary; meta?: PageMeta }>(`/work-orders?${qs}`)
        setOilRows(res.data)
        setOrderRows([])
        setSummary(res.summary)
        setMeta({
          current_page: res.meta?.current_page ?? nextPage,
          last_page: res.meta?.last_page ?? 1,
          total: res.meta?.total ?? res.data.length,
          per_page: res.meta?.per_page ?? LIST_PER_PAGE,
        })
        setPage(res.meta?.current_page ?? nextPage)
      } else {
        if (chip === 'open' || chip === 'closed') qs.set('status', chip)
        const res = await api<{ data: WorkOrder[]; summary: Summary; meta?: PageMeta }>(`/work-orders?${qs}`)
        setOrderRows(res.data)
        setOilRows([])
        setSummary(res.summary)
        setMeta({
          current_page: res.meta?.current_page ?? nextPage,
          last_page: res.meta?.last_page ?? 1,
          total: res.meta?.total ?? res.data.length,
          per_page: res.meta?.per_page ?? LIST_PER_PAGE,
        })
        setPage(res.meta?.current_page ?? nextPage)
      }
    } catch (e) {
      setError((e as ApiError).message || 'تعذّر تحميل الصيانة.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => void load(page), 220)
    return () => window.clearTimeout(t)
  }, [search, chip, page])

  const chips = useMemo(
    () => [
      { id: 'oil', label: 'تغيير الزيت' },
      {
        id: 'upcoming',
        label: 'صيانة قادمة',
        count: summary.oil_due_soon && summary.oil_due_soon > 0 ? summary.oil_due_soon : null,
        attention: (summary.oil_due_soon ?? 0) > 0 ? ('critical' as const) : null,
        attentionLabel: 'زيت أو صيانة مستحقة قريباً',
      },
      { id: 'orders', label: 'كل الصيانات' },
      {
        id: 'open',
        label: 'مفتوح',
        attention: summary.open > 0 ? ('critical' as const) : null,
        attentionLabel: `${summary.open} أمر شغل مفتوح`,
      },
      { id: 'closed', label: 'مغلق' },
    ],
    [summary.open, summary.oil_due_soon],
  )

  function openVehicleMaintenance(row: OilRow) {
    navigate(`/maintenance/vehicles/${row.vehicle_id}`, {
      state: {
        from: `${location.pathname}${location.search}`,
        vehicle: {
          id: row.vehicle_id,
          brand: row.brand,
          model: row.model,
          plate: row.plate,
          code: row.code,
          odometer: row.odometer,
          last_oil_change_on: row.last_oil_change_on,
          next_oil_on: row.next_oil_on,
          next_oil_odometer: row.next_oil_odometer,
          oil_due_soon: row.oil_due_soon,
        },
      },
    })
  }

  function openVehicleFromOrder(order: WorkOrder) {
    const vid = order.vehicle?.id
    if (!vid) return
    navigate(`/maintenance/vehicles/${vid}`, {
      state: {
        from: `${location.pathname}${location.search}`,
        vehicle: order.vehicle
          ? {
              id: order.vehicle.id,
              brand: order.vehicle.brand,
              model: order.vehicle.model,
              plate: order.vehicle.plate,
              odometer: order.vehicle.odometer,
            }
          : undefined,
      },
    })
  }

  function openNewForVehicle(row?: OilRow) {
    if (row) {
      setLockedVehicle({
        id: row.vehicle_id,
        label: `${row.brand} ${row.model} · ${row.plate}`,
        odometer: row.odometer,
      })
    } else {
      setLockedVehicle(undefined)
    }
    setSheetOpen(true)
  }

  async function closeOrder(id: number) {
    try {
      await api(`/work-orders/${id}/close`, { method: 'POST' })
      toast.success('تم إغلاق أمر الصيانة.')
      await load(page)
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  const oilColumns: DataColumn<OilRow>[] = [
    {
      id: 'vehicle',
      header: 'العربية',
      cell: (r) => (
        <>
          <strong className="rpm-table__primary">{r.brand} {r.model}</strong>
          <span className="rpm-table__sub">{r.plate}</span>
        </>
      ),
      sortValue: (r) => `${r.brand} ${r.model}`,
      mobile: 'title',
    },
    {
      id: 'last_oil',
      header: 'آخر تغيير زيت',
      cell: (r) => (
        <span className={`rpm-maint-oil__last ${r.last_oil_change_on ? 'has-value' : ''}`}>
          {r.last_oil_change_on ? formatDate(r.last_oil_change_on) : 'لا يوجد بعد'}
        </span>
      ),
      sortValue: (r) => r.last_oil_change_on || '',
      mobile: 'subtitle',
    },
    {
      id: 'next_oil',
      header: 'القادم',
      cell: (r) => {
        if (r.next_oil_on) return <span dir="ltr">{formatDate(r.next_oil_on)}</span>
        if (r.next_oil_odometer != null) return <span dir="ltr">{formatNumber(r.next_oil_odometer)} كم</span>
        return '—'
      },
      sortValue: (r) => r.next_oil_on || String(r.next_oil_odometer ?? ''),
    },
    {
      id: 'due',
      header: 'الاستحقاق',
      cell: (r) => (
        r.oil_due_soon
          ? <StatusPill status="maintenance" label="مستحق قريباً" />
          : r.last_oil_change_on
            ? <StatusPill status="completed" label="منتظم" />
            : <StatusPill status="pending" label="بلا سجل" />
      ),
      sortValue: (r) => (r.oil_due_soon ? 0 : 1),
      mobile: 'badge',
    },
  ]

  const orderColumns: DataColumn<WorkOrder>[] = [
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
      id: 'opened',
      header: 'تاريخ الصيانة',
      cell: (r) => (r.opened_on ? formatDate(r.opened_on) : (r.opened_at ? formatDate(r.opened_at) : '—')),
      sortValue: (r) => r.opened_on || r.opened_at || '',
      mobile: 'subtitle',
    },
    {
      id: 'items',
      header: 'البنود',
      cell: (r) => {
        const items = r.items || []
        if (items.length === 0) return '—'
        const text = items.slice(0, 3).join(' · ')
        return items.length > 3 ? `${text} +${items.length - 3}` : text
      },
      sortValue: (r) => (r.items || []).join(' '),
    },
    {
      id: 'next',
      header: 'الصيانة التالية',
      cell: (r) => {
        if (r.next_service_on) return <span dir="ltr">{formatDate(r.next_service_on)}</span>
        if (r.next_service_odometer != null) return <span dir="ltr">{formatNumber(r.next_service_odometer)} كم</span>
        return '—'
      },
      sortValue: (r) => r.next_service_on || String(r.next_service_odometer ?? ''),
    },
    {
      id: 'status',
      header: 'الحالة',
      cell: (r) => (
        <StatusPill
          status={r.status === 'closed' ? 'completed' : 'maintenance'}
          label={statusLabel[r.status] || r.status}
        />
      ),
      sortValue: (r) => r.status,
      mobile: 'badge',
    },
  ]

  return (
    <div className="rpm-stack rpm-maint-page">
      <PageHeader
        title="الصيانة"
        subtitle="آخر تغيير زيت · وافتح صفحة العربية لكل الصيانات"
        action={canCreate ? <Button onClick={() => openNewForVehicle()}>صيانة جديدة</Button> : undefined}
        mobileFab={canCreate}
      />
      {canCreate ? <Fab label="صيانة جديدة" onClick={() => openNewForVehicle()} /> : null}

      <MetricCardGrid
        label="ملخص الصيانة"
        mobile="wrap"
        items={[
          {
            id: 'last',
            label: 'عربيات لها سجل زيت',
            value: summary.with_oil_history ?? 0,
            tone: 'info',
            icon: <IconCar size={16} />,
          },
          {
            id: 'due',
            label: 'صيانة قادمة',
            value: summary.oil_due_soon ?? 0,
            tone: (summary.oil_due_soon ?? 0) > 0 ? 'danger' : 'success',
            icon: <IconAlert size={16} />,
          },
          {
            id: 'open',
            label: 'أوامر مفتوحة',
            value: summary.open,
            tone: 'warning',
            icon: <IconWrench size={16} />,
          },
          {
            id: 'closed',
            label: 'أوامر مغلقة',
            value: summary.closed,
            tone: 'success',
            icon: <IconCheck size={16} />,
          },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={(v) => { setSearch(v); setPage(1) }}
        chips={chips}
        activeChip={chip}
        onChipChange={(id) => { setChip(id); setPage(1) }}
        placeholder="بحث باللوحة أو الموديل…"
      />

      {error ? (
        <p className="rpm-maint-page__error" role="alert">{error}</p>
      ) : null}

      {isOilTab(chip) ? (
        <DataTable
          label="جدول تغيير الزيت"
          rows={oilRows}
          columns={oilColumns}
          rowKey={(r) => r.vehicle_id}
          rowLabel={(r) => `${r.brand} ${r.model} · ${r.plate}`}
          loading={loading}
          onRowClick={(r) => openVehicleMaintenance(r)}
          rowActions={(r) => [
            { id: 'history', label: 'كل صيانات العربية', icon: <IconWrench size={16} />, onSelect: () => openVehicleMaintenance(r) },
            ...(canCreate
              ? [{ id: 'new', label: 'صيانة جديدة', icon: <IconCheck size={16} />, onSelect: () => openNewForVehicle(r) }]
              : []),
          ]}
          emptyTitle="لا عربيات مطابقة"
          emptyDescription="غيّر البحث أو سجّل أول تغيير زيت على العربية."
          serverPagination={{
            page: meta.current_page,
            lastPage: meta.last_page,
            total: meta.total,
            perPage: LIST_PER_PAGE,
            onPageChange: setPage,
          }}
        />
      ) : (
        <DataTable
          label="أوامر الصيانة"
          rows={orderRows}
          columns={orderColumns}
          rowKey={(r) => r.id}
          rowLabel={(r) => `أمر شغل · ${r.vehicle?.plate ?? ''}`}
          loading={loading}
          onRowClick={(r) => openVehicleFromOrder(r)}
          rowActions={(r) => [
            ...(r.vehicle?.id
              ? [{ id: 'vehicle', label: 'كل صيانات العربية', icon: <IconWrench size={16} />, onSelect: () => openVehicleFromOrder(r) }]
              : []),
            ...(canClose && r.status !== 'closed'
              ? [{ id: 'close', label: 'إغلاق أمر الشغل', icon: <IconCheck size={16} />, onSelect: () => void closeOrder(r.id) }]
              : []),
          ]}
          emptyTitle="لا توجد أوامر شغل"
          emptyDescription="افتح أمر شغل لتسجيل بنود الصيانة وموعد الزيت القادم."
          serverPagination={{
            page: meta.current_page,
            lastPage: meta.last_page,
            total: meta.total,
            perPage: LIST_PER_PAGE,
            onPageChange: setPage,
          }}
        />
      )}

      <WorkOrderSheet
        open={sheetOpen}
        onClose={() => { setSheetOpen(false); setLockedVehicle(undefined) }}
        onSaved={() => {
          setSheetOpen(false)
          setLockedVehicle(undefined)
          void load(page)
        }}
        vehicle={lockedVehicle}
      />
    </div>
  )
}
