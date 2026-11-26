import { useEffect, useMemo, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatNumber } from '@/shared/lib/format'
import { useAuth } from '@/shared/auth/AuthContext'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, BackNavButton, Button, Fab, DataTable, StatusPill, FilterBar,
  MetricCardGrid, useToast, ErrorState, SkeletonBlock,
  IconCheck, IconWrench, IconAlert, IconCar, type DataColumn,
} from '@/shared/ui'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
import './pages.css'
import './MaintenanceVehiclePage.css'

type VehicleInfo = {
  id: number
  brand?: string
  model?: string
  plate?: string
  code?: string
  odometer?: number
  last_oil_change_on?: string | null
  next_oil_on?: string | null
  next_oil_odometer?: number | null
  oil_due_soon?: boolean
}

type WorkOrder = {
  id: number
  status: string
  workshop?: string | null
  notes?: string | null
  opened_on?: string | null
  opened_at?: string | null
  closed_on?: string | null
  odometer?: number | null
  next_service_on?: string | null
  next_service_odometer?: number | null
  items?: string[]
  has_oil_change?: boolean
  vehicle?: VehicleInfo | null
}

type PageMeta = { current_page: number; last_page: number; total: number; per_page?: number }

const statusLabel: Record<string, string> = {
  open: 'مفتوح', in_progress: 'قيد التنفيذ', closed: 'مغلق', cancelled: 'ملغى',
}

const DetailTabs = ['upcoming', 'all', 'oil', 'open', 'closed'] as const
const LIST_PER_PAGE = 20
const HISTORY_PER_PAGE = 50

type LocationState = {
  from?: string
  vehicle?: VehicleInfo
} | null

function vehicleTitle(v?: VehicleInfo | null) {
  if (!v) return 'صيانات العربية'
  const name = `${v.brand || ''} ${v.model || ''}`.trim()
  return name ? `${name} · ${v.plate || ''}`.trim() : (v.plate || 'صيانات العربية')
}

export function MaintenanceVehiclePage() {
  const { id } = useParams()
  const vehicleId = Number(id)
  const location = useLocation()
  const toast = useToast()
  const { can } = useAuth()
  const canCreate = can('maintenance.create')
  const canClose = can('maintenance.close')
  const canVehicles = can('vehicles.view')

  const seeded = (location.state as LocationState)?.vehicle
  const [vehicle, setVehicle] = useState<VehicleInfo | null>(
    seeded && seeded.id === vehicleId ? seeded : null,
  )
  const [rows, setRows] = useState<WorkOrder[]>([])
  const [meta, setMeta] = useState<PageMeta>({ current_page: 1, last_page: 1, total: 0 })
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: DetailTabs, defaultValue: 'upcoming' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [sheetOpen, setSheetOpen] = useState(false)
  const [upcomingCount, setUpcomingCount] = useState(0)
  const [historyTotal, setHistoryTotal] = useState(0)

  const oilSummary = useMemo(() => {
    const oilOrders = rows.filter((r) => r.has_oil_change)
    const latest = oilOrders[0]
    return {
      last: vehicle?.last_oil_change_on
        ?? latest?.opened_on
        ?? (latest?.opened_at ? String(latest.opened_at).slice(0, 10) : null),
      nextOn: vehicle?.next_oil_on ?? latest?.next_service_on ?? null,
      nextOdo: vehicle?.next_oil_odometer ?? latest?.next_service_odometer ?? null,
      due: Boolean(vehicle?.oil_due_soon),
      oilCount: oilOrders.length,
      openCount: rows.filter((r) => r.status === 'open' || r.status === 'in_progress').length,
    }
  }, [rows, vehicle])

  async function loadVehicleBasics() {
    if (!Number.isFinite(vehicleId) || vehicleId < 1) return
    // جدول الزيت بصلاحية الصيانة — بلا حاجة لصلاحية العربيات.
    try {
      const oil = await api<{ data: Array<VehicleInfo & { vehicle_id: number }> }>(
        `/work-orders?view=oil&tab=oil&vehicle_id=${vehicleId}&per_page=1`,
      )
      const hit = oil.data?.[0]
      if (hit && Number(hit.vehicle_id) === vehicleId) {
        setVehicle((prev) => ({
          id: vehicleId,
          brand: hit.brand || prev?.brand,
          model: hit.model || prev?.model,
          plate: hit.plate || prev?.plate,
          code: hit.code || prev?.code,
          odometer: hit.odometer ?? prev?.odometer,
          last_oil_change_on: hit.last_oil_change_on,
          next_oil_on: hit.next_oil_on,
          next_oil_odometer: hit.next_oil_odometer,
          oil_due_soon: hit.oil_due_soon,
        }))
        return
      }
    } catch {
      /* ignore — fallback below */
    }
    if (canVehicles) {
      try {
        const res = await api<{ data: VehicleInfo }>(`/vehicles/${vehicleId}`)
        setVehicle((prev) => ({ ...prev, ...res.data, id: vehicleId }))
      } catch {
        /* keep seeded / WO-derived */
      }
    }
  }

  async function loadOrders(nextPage = page) {
    if (!Number.isFinite(vehicleId) || vehicleId < 1) {
      setError('عربيّة غير صالحة.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const perPage = chip === 'all' || chip === 'closed' ? HISTORY_PER_PAGE : LIST_PER_PAGE
      const qs = new URLSearchParams()
      qs.set('vehicle_id', String(vehicleId))
      qs.set('page', String(nextPage))
      qs.set('per_page', String(perPage))
      if (chip === 'open' || chip === 'closed') qs.set('status', chip)
      if (chip === 'upcoming') qs.set('due_soon', '1')
      if (chip === 'oil') qs.set('has_oil', '1')
      if (search.trim()) qs.set('search', search.trim())

      const res = await api<{ data: WorkOrder[]; meta?: PageMeta }>(`/work-orders?${qs}`)
      const data = res.data || []
      setRows(data)
      setMeta({
        current_page: res.meta?.current_page ?? nextPage,
        last_page: res.meta?.last_page ?? 1,
        total: res.meta?.total ?? data.length,
        per_page: res.meta?.per_page ?? perPage,
      })
      setPage(res.meta?.current_page ?? nextPage)
      if (chip === 'upcoming') setUpcomingCount(res.meta?.total ?? data.length)
      if (chip === 'all') setHistoryTotal(res.meta?.total ?? data.length)

      const fromWo = data[0]?.vehicle
      if (fromWo) {
        setVehicle((prev) => ({
          id: vehicleId,
          brand: fromWo.brand || prev?.brand,
          model: fromWo.model || prev?.model,
          plate: fromWo.plate || prev?.plate,
          code: fromWo.code || prev?.code,
          odometer: fromWo.odometer ?? prev?.odometer,
          last_oil_change_on: prev?.last_oil_change_on,
          next_oil_on: prev?.next_oil_on,
          next_oil_odometer: prev?.next_oil_odometer,
          oil_due_soon: prev?.oil_due_soon,
        }))
      }
    } catch (e) {
      setError((e as ApiError).message || 'تعذّر تحميل صيانات العربية.')
      setRows([])
    } finally {
      setLoading(false)
    }
  }

  /** عدّادات التبويبات — صيانة قادمة + إجمالي السجل بدون تغيير التبويب الحالي */
  async function loadTabCounts() {
    if (!Number.isFinite(vehicleId) || vehicleId < 1) return
    try {
      const [due, all] = await Promise.all([
        api<{ meta?: PageMeta }>(`/work-orders?vehicle_id=${vehicleId}&due_soon=1&per_page=1`),
        api<{ meta?: PageMeta }>(`/work-orders?vehicle_id=${vehicleId}&per_page=1`),
      ])
      setUpcomingCount(due.meta?.total ?? 0)
      setHistoryTotal(all.meta?.total ?? 0)
    } catch {
      /* غير حرج للعرض */
    }
  }

  useEffect(() => {
    void loadVehicleBasics()
    void loadTabCounts()
  }, [vehicleId])

  useEffect(() => {
    const t = window.setTimeout(() => void loadOrders(page), 180)
    return () => window.clearTimeout(t)
  }, [vehicleId, search, chip, page])

  async function closeOrder(orderId: number) {
    try {
      await api(`/work-orders/${orderId}/close`, { method: 'POST' })
      toast.success('تم إغلاق أمر الصيانة.')
      await loadOrders(page)
      await loadVehicleBasics()
      await loadTabCounts()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  const chips = useMemo(
    () => [
      {
        id: 'upcoming',
        label: 'صيانة قادمة',
        count: upcomingCount > 0 ? upcomingCount : null,
        attention: upcomingCount > 0 || oilSummary.due ? ('critical' as const) : null,
        attentionLabel: upcomingCount > 0
          ? `${upcomingCount} صيانة مستحقة قريباً`
          : (oilSummary.due ? 'زيت مستحق قريباً' : null),
      },
      {
        id: 'all',
        label: 'السجل الكامل',
        count: historyTotal > 0 ? historyTotal : null,
      },
      { id: 'oil', label: 'تغيير الزيت' },
      {
        id: 'open',
        label: 'مفتوح',
        attention: oilSummary.openCount > 0 ? ('critical' as const) : null,
        attentionLabel: `${oilSummary.openCount} أمر مفتوح`,
      },
      { id: 'closed', label: 'السجل القديم' },
    ],
    [oilSummary.openCount, oilSummary.due, upcomingCount, historyTotal],
  )

  const columns: DataColumn<WorkOrder>[] = [
    {
      id: 'opened',
      header: 'التاريخ',
      cell: (r) => (r.opened_on ? formatDate(r.opened_on) : (r.opened_at ? formatDate(r.opened_at) : '—')),
      sortValue: (r) => r.opened_on || r.opened_at || '',
      mobile: 'subtitle',
    },
    {
      id: 'items',
      header: 'البنود',
      cell: (r) => {
        const items = r.items || []
        if (items.length === 0) return r.workshop || 'أمر صيانة'
        const text = items.join(' · ')
        return (
          <>
            <strong className="rpm-table__primary">{text}</strong>
            {r.has_oil_change && items.length > 1
              ? <span className="rpm-maint-vehicle__oil-tag">يشمل زيت</span>
              : null}
          </>
        )
      },
      sortValue: (r) => (r.items || []).join(' '),
      mobile: 'title',
    },
    {
      id: 'odometer',
      header: 'العداد',
      cell: (r) => (r.odometer != null ? <span dir="ltr">{formatNumber(r.odometer)} كم</span> : '—'),
      sortValue: (r) => r.odometer ?? 0,
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

  if (!Number.isFinite(vehicleId) || vehicleId < 1) {
    return <ErrorState title="عربيّة غير صالحة" description="ارجع لقائمة الصيانة واختر عربية." />
  }

  return (
    <div className="rpm-stack rpm-maint-vehicle">
      <BackNavButton to="/maintenance" label="رجوع للصيانة" />
      <PageHeader
        title={vehicleTitle(vehicle)}
        subtitle="الصيانات الجاية · السجل الكامل والقديم · زيت وبنود كبيرة وصغيرة"
        action={canCreate ? <Button onClick={() => setSheetOpen(true)}>صيانة جديدة</Button> : undefined}
        mobileFab={canCreate}
      />
      {canCreate ? <Fab label="صيانة جديدة" onClick={() => setSheetOpen(true)} /> : null}

      <MetricCardGrid
        label="ملخص صيانة العربية"
        mobile="wrap"
        items={[
          {
            id: 'last-oil',
            label: 'آخر تغيير زيت',
            value: oilSummary.last ? formatDate(oilSummary.last) : 'لا يوجد',
            tone: oilSummary.last ? 'info' : 'warning',
            icon: <IconCar size={16} />,
          },
          {
            id: 'next-oil',
            label: 'الزيت القادم',
            value: oilSummary.nextOn
              ? formatDate(oilSummary.nextOn)
              : oilSummary.nextOdo != null
                ? `${formatNumber(oilSummary.nextOdo)} كم`
                : '—',
            tone: oilSummary.due ? 'danger' : 'success',
            icon: <IconAlert size={16} />,
          },
          {
            id: 'upcoming',
            label: 'صيانات قادمة',
            value: upcomingCount,
            tone: upcomingCount > 0 ? 'danger' : 'success',
            icon: <IconAlert size={16} />,
          },
          {
            id: 'orders',
            label: 'السجل الكامل',
            value: historyTotal || meta.total,
            tone: 'info',
            icon: <IconWrench size={16} />,
          },
          {
            id: 'odo',
            label: 'العداد الحالي',
            value: vehicle?.odometer != null ? `${formatNumber(vehicle.odometer)} كم` : '—',
            tone: 'info',
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
        placeholder="بحث في البنود أو الورشة…"
      />

      {error ? <p className="rpm-maint-vehicle__error" role="alert">{error}</p> : null}
      {loading && rows.length === 0 ? <SkeletonBlock rows={5} /> : null}

      <DataTable
        label={
          chip === 'upcoming' ? 'الصيانات الجاية لهذه العربية'
            : chip === 'closed' ? 'السجل القديم (مغلق)'
              : chip === 'oil' ? 'سجل تغيير الزيت'
                : chip === 'open' ? 'أوامر مفتوحة'
                  : 'السجل الكامل — كل الصيانات'
        }
        rows={rows}
        columns={columns}
        rowKey={(r) => r.id}
        rowLabel={(r) => `أمر #${r.id}`}
        loading={loading}
        rowActions={(r) =>
          canClose && r.status !== 'closed'
            ? [{ id: 'close', label: 'إغلاق أمر الشغل', icon: <IconCheck size={16} />, onSelect: () => void closeOrder(r.id) }]
            : []
        }
        emptyTitle={
          chip === 'upcoming' ? 'ما فيش صيانة قادمة مستحقة'
            : chip === 'closed' ? 'ما فيش سجل قديم مغلق'
              : 'لا صيانات على هذه العربية'
        }
        emptyDescription={
          chip === 'upcoming'
            ? 'لما يقرب موعد أو عداد الصيانة التالية (خلال ٧ أيام أو ٢٬٠٠٠ كم) هتظهر هنا.'
            : 'سجّل أمر شغل لأي بند — زيت أو غيره — من زر صيانة جديدة.'
        }
        serverPagination={{
          page: meta.current_page,
          lastPage: meta.last_page,
          total: meta.total,
          perPage: chip === 'all' || chip === 'closed' ? HISTORY_PER_PAGE : LIST_PER_PAGE,
          onPageChange: setPage,
        }}
      />

      <WorkOrderSheet
        open={sheetOpen}
        onClose={() => setSheetOpen(false)}
        onSaved={() => {
          setSheetOpen(false)
          void loadOrders(1)
          void loadVehicleBasics()
          void loadTabCounts()
          setPage(1)
        }}
        vehicle={vehicle ? {
          id: vehicle.id,
          label: vehicleTitle(vehicle),
          odometer: vehicle.odometer,
        } : { id: vehicleId, label: `عربية #${vehicleId}` }}
      />
    </div>
  )
}
