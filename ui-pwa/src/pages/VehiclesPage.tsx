import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { addCivilDays, todayCivilIso } from '@/shared/lib/dates'
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

const VEHICLE_TABS = [
  'all', 'available', 'rented', 'reserved', 'maintenance',
  'expiring', 'license_expiring', 'sim_recharge', 'owner_agreement_expiring',
] as const
const EXPIRING_PRESETS = [1, 2, 3, 7] as const
const LICENSE_EXPIRING_PRESETS = [7, 14, 30] as const
const LICENSE_EXPIRING_DEFAULT_DAYS = 30
const SIM_RECHARGE_PRESETS = [3, 7, 14, 30] as const
const SIM_RECHARGE_DEFAULT_DAYS = 14
const OWNER_AGREEMENT_PRESETS = [7, 14, 30] as const
const OWNER_AGREEMENT_DEFAULT_DAYS = 30

function addDaysIso(base: string, days: number): string {
  return addCivilDays(base, days)
}

function todayIso(): string {
  return todayCivilIso()
}

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
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  tracker_sim_last_recharged_on?: string | null
  tracker_sim_next_recharge_on?: string | null
  tracker_sim_recharge_cycle_days?: number | null
  notes?: string | null
  is_active?: boolean
  owner_id?: number | null
  owner_monthly_amount?: number | null
  owner_since?: string | null
  owner_agreement_starts_on?: string | null
  owner_agreement_ends_on?: string | null
  owner?: OwnerOption | null
  active_contract_ends_at?: string | null
  license_expires_on?: string | null
  accessories?: Array<{ id?: number; name?: string | null }> | null
  purchase_value_estimate?: number | null
  color?: string | null
  transmission?: string | null
  chassis_number?: string | null
  engine_number?: string | null
  engine_capacity?: string | null
  seats?: number | null
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
  const [summary, setSummary] = useState({
    total: 0, available: 0, rented: 0, reserved: 0, maintenance: 0,
    license_expiring: 0, sim_recharge: 0, owner_agreement_expiring: 0,
  })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: VEHICLE_TABS, defaultValue: 'all' })
  const [withinDays, setWithinDays] = useState(2)
  const [licenseWithinDays, setLicenseWithinDays] = useState(LICENSE_EXPIRING_DEFAULT_DAYS)
  const [simWithinDays, setSimWithinDays] = useState(SIM_RECHARGE_DEFAULT_DAYS)
  const [agreementWithinDays, setAgreementWithinDays] = useState(OWNER_AGREEMENT_DEFAULT_DAYS)
  const [customDays, setCustomDays] = useState('')
  const [licenseCustomDays, setLicenseCustomDays] = useState('')
  const [simCustomDays, setSimCustomDays] = useState('')
  const [agreementCustomDays, setAgreementCustomDays] = useState('')
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
        label: 'تأجير قارب الانتهاء',
        attention: 'warning' as const,
        attentionLabel: `خلال ${withinDays} يوم`,
      },
      {
        id: 'license_expiring',
        label: 'رخص قاربت الانتهاء',
        attention: summary.license_expiring > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.license_expiring} رخصة خلال ${LICENSE_EXPIRING_DEFAULT_DAYS} يوماً أو أقل`,
      },
      {
        id: 'sim_recharge',
        label: 'شحن شرائح التتبع',
        attention: summary.sim_recharge > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.sim_recharge} شريحة خلال ${SIM_RECHARGE_DEFAULT_DAYS} يوماً أو أقل`,
      },
      {
        id: 'owner_agreement_expiring',
        label: 'عقود ملاك قاربت الانتهاء',
        attention: summary.owner_agreement_expiring > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.owner_agreement_expiring} عقد خلال ${OWNER_AGREEMENT_DEFAULT_DAYS} يوماً أو أقل`,
      },
    ],
    [
      summary.rented, summary.reserved, summary.maintenance,
      summary.license_expiring, summary.sim_recharge, summary.owner_agreement_expiring, withinDays,
    ],
  )

  async function load(
    nextSearch = search,
    nextChip = chip,
    days = withinDays,
    licenseDays = licenseWithinDays,
    simDays = simWithinDays,
    agreementDays = agreementWithinDays,
  ) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (nextSearch.trim()) qs.set('search', nextSearch.trim())
      if (nextChip !== 'all') qs.set('status', nextChip)
      if (nextChip === 'expiring') qs.set('within_days', String(days))
      if (nextChip === 'license_expiring') qs.set('within_days', String(licenseDays))
      if (nextChip === 'sim_recharge') qs.set('within_days', String(simDays))
      if (nextChip === 'owner_agreement_expiring') qs.set('within_days', String(agreementDays))
      const res = await api<{ data: Vehicle[]; summary: typeof summary }>(`/vehicles?${qs}`)
      setRows(res.data)
      const s = res.summary
      setSummary({
        total: s.total ?? 0,
        available: s.available ?? 0,
        rented: s.rented ?? 0,
        reserved: s.reserved ?? 0,
        maintenance: s.maintenance ?? 0,
        license_expiring: s.license_expiring ?? 0,
        sim_recharge: s.sim_recharge ?? 0,
        owner_agreement_expiring: s.owner_agreement_expiring ?? 0,
      })
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => load(), 250)
    return () => window.clearTimeout(t)
  }, [search, chip, withinDays, licenseWithinDays, simWithinDays, agreementWithinDays])

  const columns: DataColumn<Vehicle>[] = useMemo(() => {
    if (chip === 'owner_agreement_expiring') {
      return [
        {
          id: 'plate',
          header: 'رقم العربية',
          cell: (v) => (
            <>
              <strong className="rpm-table__primary tabular-nums">{v.plate}</strong>
              <span className="rpm-table__sub">{v.brand} {v.model}</span>
            </>
          ),
          sortValue: (v) => v.plate,
          mobile: 'title',
        },
        {
          id: 'owner',
          header: 'المالك',
          cell: (v) => v.owner?.full_name || '—',
          sortValue: (v) => v.owner?.full_name || '',
        },
        {
          id: 'agreement_start',
          header: 'البداية',
          cell: (v) => (v.owner_agreement_starts_on ? formatDate(v.owner_agreement_starts_on) : '—'),
          sortValue: (v) => v.owner_agreement_starts_on || '',
        },
        {
          id: 'agreement_end',
          header: 'النهاية',
          cell: (v) => (v.owner_agreement_ends_on ? formatDate(v.owner_agreement_ends_on) : '—'),
          sortValue: (v) => v.owner_agreement_ends_on || '',
          mobile: 'badge',
        },
        {
          id: 'owner_amount',
          header: 'الشهري',
          cell: (v) => (v.owner_monthly_amount != null ? formatMoney(v.owner_monthly_amount) : '—'),
          sortValue: (v) => v.owner_monthly_amount ?? 0,
          mobile: 'hidden',
        },
      ]
    }

    if (chip === 'sim_recharge') {
      return [
        {
          id: 'plate',
          header: 'رقم العربية',
          cell: (v) => (
            <>
              <strong className="rpm-table__primary tabular-nums">{v.plate}</strong>
              <span className="rpm-table__sub">{v.brand} {v.model}</span>
            </>
          ),
          sortValue: (v) => v.plate,
          mobile: 'title',
        },
        {
          id: 'sim',
          header: 'رقم الشريحة',
          cell: (v) => (
            <strong className="tabular-nums">{v.tracker_sim_number || '—'}</strong>
          ),
          sortValue: (v) => v.tracker_sim_number || '',
        },
        {
          id: 'last_recharge',
          header: 'آخر شحن',
          cell: (v) => (v.tracker_sim_last_recharged_on ? formatDate(v.tracker_sim_last_recharged_on) : '—'),
          sortValue: (v) => v.tracker_sim_last_recharged_on || '',
        },
        {
          id: 'next_recharge',
          header: 'الشحن القادم',
          cell: (v) => (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', alignItems: 'flex-start' }}>
              <strong>{v.tracker_sim_next_recharge_on ? formatDate(v.tracker_sim_next_recharge_on) : '—'}</strong>
              <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
            </div>
          ),
          sortValue: (v) => v.tracker_sim_next_recharge_on || '',
          mobile: 'badge',
        },
        {
          id: 'device',
          header: 'جهاز التتبع',
          cell: (v) => v.tracker_device_number || '—',
          sortValue: (v) => v.tracker_device_number || '',
          mobile: 'hidden',
        },
      ]
    }

    return [
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
            {chip === 'license_expiring' && v.license_expires_on ? (
              <span className="rpm-table__sub">الرخصة {formatDate(v.license_expires_on)}</span>
            ) : null}
          </div>
        ),
        sortValue: (v) => v.license_expires_on || v.active_contract_ends_at || v.status,
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
  }, [chip])

  async function openEdit(v: Vehicle) {
    try {
      const res = await api<{ data: Vehicle }>(`/vehicles/${v.id}`)
      setEditVehicle(res.data)
    } catch {
      setEditVehicle(v)
    }
  }

  async function markSimRecharged(v: Vehicle) {
    const cycle = v.tracker_sim_recharge_cycle_days && v.tracker_sim_recharge_cycle_days > 0
      ? v.tracker_sim_recharge_cycle_days
      : 30
    const last = todayIso()
    const next = addDaysIso(last, cycle)
    try {
      await api(`/vehicles/${v.id}`, {
        method: 'PUT',
        json: {
          tracker_sim_last_recharged_on: last,
          tracker_sim_next_recharge_on: next,
          tracker_sim_recharge_cycle_days: cycle,
        },
      })
      toast.success(`تم تسجيل الشحن · القادم ${formatDate(next)}`)
      void load()
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّر تسجيل الشحن.')
    }
  }

  function vehicleMenu(v: Vehicle): MenuItem[] {
    const items: MenuItem[] = [
      { id: 'open', label: 'فتح الملف الكامل', icon: <IconEye size={16} />, onSelect: () => navigate(`/vehicles/${v.id}`) },
      { id: 'edit', label: 'تعديل بيانات العربية', icon: <IconEdit size={16} />, onSelect: () => void openEdit(v) },
    ]
    if (chip === 'sim_recharge' || v.tracker_sim_number) {
      items.push({
        id: 'copy-sim',
        label: 'نسخ رقم الشريحة',
        icon: <IconClipboard size={16} />,
        onSelect: () => {
          if (!v.tracker_sim_number) {
            toast.error('لا يوجد رقم شريحة.')
            return
          }
          void navigator.clipboard?.writeText(v.tracker_sim_number).then(
            () => toast.success('تم نسخ رقم الشريحة.'),
            () => toast.error('تعذّر النسخ.'),
          )
        },
      })
      items.push({
        id: 'mark-sim',
        label: 'سجّل شحن اليوم',
        icon: <IconCheck size={16} />,
        onSelect: () => void markSimRecharged(v),
      })
    }
    items.push(
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
    )
    return items
  }

  function applyCustomDays() {
    const n = Number(customDays)
    if (!Number.isFinite(n) || n < 0 || n > 60) {
      toast.error('أدخل عدد أيام بين 0 و 60.')
      return
    }
    setWithinDays(Math.round(n))
  }

  function applyLicenseCustomDays() {
    const n = Number(licenseCustomDays)
    if (!Number.isFinite(n) || n < 0 || n > 90) {
      toast.error('أدخل عدد أيام بين 0 و 90.')
      return
    }
    setLicenseWithinDays(Math.round(n))
  }

  function applySimCustomDays() {
    const n = Number(simCustomDays)
    if (!Number.isFinite(n) || n < 0 || n > 90) {
      toast.error('أدخل عدد أيام بين 0 و 90.')
      return
    }
    setSimWithinDays(Math.round(n))
  }

  function applyAgreementCustomDays() {
    const n = Number(agreementCustomDays)
    if (!Number.isFinite(n) || n < 0 || n > 120) {
      toast.error('أدخل عدد أيام بين 0 و 120.')
      return
    }
    setAgreementWithinDays(Math.round(n))
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

      {chip === 'license_expiring' ? (
        <div className="vh-expiring-bar" role="group" aria-label="نافذة قرب انتهاء رخصة التسيير">
          <span className="vh-expiring-bar__label">خلال:</span>
          <div className="vh-expiring-bar__pills">
            {LICENSE_EXPIRING_PRESETS.map((d) => (
              <Button
                key={d}
                type="button"
                size="sm"
                variant={licenseWithinDays === d && licenseCustomDays === '' ? 'accent' : 'secondary'}
                onClick={() => { setLicenseWithinDays(d); setLicenseCustomDays('') }}
              >
                {d} يوماً
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
              max={90}
              value={licenseCustomDays}
              placeholder={String(licenseWithinDays)}
              onChange={(e) => setLicenseCustomDays(e.target.value)}
              onBlur={() => { if (licenseCustomDays !== '') applyLicenseCustomDays() }}
              onKeyDown={(e) => { if (e.key === 'Enter') applyLicenseCustomDays() }}
              aria-label="عدد أيام قرب انتهاء الرخصة"
            />
            <span className="vh-expiring-bar__unit">يوم</span>
          </label>
        </div>
      ) : null}

      {chip === 'sim_recharge' ? (
        <div className="vh-expiring-bar" role="group" aria-label="نافذة قرب شحن شريحة التتبع">
          <span className="vh-expiring-bar__label">خلال:</span>
          <div className="vh-expiring-bar__pills">
            {SIM_RECHARGE_PRESETS.map((d) => (
              <Button
                key={d}
                type="button"
                size="sm"
                variant={simWithinDays === d && simCustomDays === '' ? 'accent' : 'secondary'}
                onClick={() => { setSimWithinDays(d); setSimCustomDays('') }}
              >
                {d} يوماً
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
              max={90}
              value={simCustomDays}
              placeholder={String(simWithinDays)}
              onChange={(e) => setSimCustomDays(e.target.value)}
              onBlur={() => { if (simCustomDays !== '') applySimCustomDays() }}
              onKeyDown={(e) => { if (e.key === 'Enter') applySimCustomDays() }}
              aria-label="عدد أيام قرب شحن الشريحة"
            />
            <span className="vh-expiring-bar__unit">يوم</span>
          </label>
        </div>
      ) : null}

      {chip === 'owner_agreement_expiring' ? (
        <div className="vh-expiring-bar" role="group" aria-label="نافذة قرب انتهاء عقد المالك">
          <span className="vh-expiring-bar__label">خلال:</span>
          <div className="vh-expiring-bar__pills">
            {OWNER_AGREEMENT_PRESETS.map((d) => (
              <Button
                key={d}
                type="button"
                size="sm"
                variant={agreementWithinDays === d && agreementCustomDays === '' ? 'accent' : 'secondary'}
                onClick={() => { setAgreementWithinDays(d); setAgreementCustomDays('') }}
              >
                {d} يوماً
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
              max={120}
              value={agreementCustomDays}
              placeholder={String(agreementWithinDays)}
              onChange={(e) => setAgreementCustomDays(e.target.value)}
              onBlur={() => { if (agreementCustomDays !== '') applyAgreementCustomDays() }}
              onKeyDown={(e) => { if (e.key === 'Enter') applyAgreementCustomDays() }}
              aria-label="عدد أيام قرب انتهاء عقد المالك"
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
