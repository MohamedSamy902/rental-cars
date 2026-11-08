import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { api, type ApiError } from '@/shared/api/client'
import { civilDateOnly, nowLocalDateTimeValue, todayCivilIso } from '@/shared/lib/dates'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  Button, BackNavButton, StatusPill, Sheet, AttentionDot,
  TextField, NumberField, SelectField, TextAreaField, SwitchField, MediaUpload,
  DateField,
  useToast, SkeletonBlock, ErrorState, QrCode, PhoneActions,
  type AttentionTone,
} from '@/shared/ui'
import { useNewContract } from '@/shared/contracts/newContractContext'
import { ContractActionsBar } from '@/shared/contracts/ContractActionsBar'
import { ContractActionSheet } from '@/shared/contracts/ContractActionSheet'
import { useContractLifecycle } from '@/shared/contracts/useContractLifecycle'
import { printContractDocument } from '@/shared/contracts/printContract'
import type { Contract } from '@/shared/contracts/contractModel'
import { printDomSubtree, printHtmlDocument } from '@/utils/printHtml'
import { shellOwnerAgreementHtml } from '@/shared/contracts/ownerAgreementPrint'
import { ExpenseSheet } from '@/shared/forms/ExpenseSheet'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
import { VehicleFormSheet } from '@/shared/forms/VehicleFormSheet'
import './VehicleDetailPage.css'
import './pages.css'
import '@/shared/ui/AttentionDot.css'

type OwnerOption = { id: number; full_name: string; phone?: string | null }
type Accessory = {
  id: number
  name: string
  default_qty?: number
  required_each_rental?: boolean
  is_active?: boolean
  notes?: string | null
  sort_order?: number
}
type Document = {
  id: number
  type: string
  document_number?: string | null
  file_path?: string | null
  file_path_back?: string | null
  expires_on?: string | null
  issued_on?: string | null
  notes?: string | null
}

type Vehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  color?: string | null
  transmission?: string | null
  chassis_number?: string | null
  engine_number?: string | null
  engine_capacity?: string | null
  seats?: number | null
  odometer: number
  odometer_source?: string | null
  daily_rate: number
  default_deposit: number
  purchase_value_estimate?: number | null
  status: string
  manual_status?: string | null
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  tracker_sim_last_recharged_on?: string | null
  tracker_sim_next_recharge_on?: string | null
  tracker_sim_recharge_cycle_days?: number | null
  owner_agreement_starts_on?: string | null
  owner_agreement_ends_on?: string | null
  notes?: string | null
  is_active?: boolean
  owner_id?: number | null
  owner_monthly_amount?: number | null
  owner_since?: string | null
  owner?: OwnerOption | null
  accessories?: Accessory[] | null
  documents?: Document[] | null
  created_at?: string | null
  updated_at?: string | null
}

type ChartPoint = { month: string; amount: number; count?: number }
type StatusSlice = { status: string; label: string; count: number }

type Dossier = {
  vehicle: Vehicle
  contracts: Array<{
    id: number
    number: string
    status: string
    starts_at?: string | null
    planned_ends_at?: string | null
    actual_ends_at?: string | null
    final_total?: number | null
    period_rent?: number | null
    customer?: { id: number; full_name: string; phone?: string | null } | null
  }>
  work_orders: Array<{
    id: number
    status: string
    workshop?: string | null
    opened_on?: string | null
    closed_on?: string | null
    odometer?: number | null
    parts_cost?: number | null
    labor_cost?: number | null
    notes?: string | null
    lines: Array<{ id: number; description: string; quantity?: number; unit_price?: number }>
  }>
  expenses: Array<{
    id: number
    title: string
    category?: string | null
    amount: number
    spent_on?: string | null
    payment_method?: string | null
  }>
  violations: Array<{
    id: number
    status?: string | null
    amount?: number | null
    location?: string | null
    occurred_at?: string | null
    customer?: { id: number; full_name: string } | null
  }>
  media: Array<{
    id: number
    kind: string
    original_name: string
    collection?: string | null
    purpose?: string | null
    url: string
  }>
  owner_dues: Array<{ id: number; year_month: string; amount: number }>
  owner_payouts: Array<{
    id: number
    amount: number
    paid_on?: string | null
    payment_method?: string | null
  }>
  qr: { payload: string; label: string }
  charts: {
    contracts_by_status: StatusSlice[]
    expenses_by_month: ChartPoint[]
    maintenance_by_month: ChartPoint[]
    revenue_by_month: ChartPoint[]
  }
  tires?: Array<{
    id: number
    position: string
    brand?: string | null
    installed_on?: string | null
    odometer_at_install: number
    expected_life_km: number
    cost?: number | null
  }>
  batteries?: Array<{
    id: number
    brand?: string | null
    installed_on?: string | null
    odometer_at_install: number
    expected_life_km: number
    cost?: number | null
  }>
  downtime?: Array<{
    id: number
    reason: string
    starts_at?: string | null
    ends_at?: string | null
    days_count?: number | null
    lost_revenue_estimate?: number | null
  }>
  consumables?: Array<{
    id: number
    status: string
    due_on?: string | null
    due_odometer?: number | null
    template?: { id: number; name: string } | null
  }>
  asset_defaults?: { tire_life_km: number; battery_life_km: number; lost_income_daily_basis: number }
  totals: {
    contracts: number
    work_orders: number
    expenses_amount: number
    violations: number
    media: number
    maintenance_cost?: number
    revenue_amount?: number
    accessories?: number
    documents?: number
    lost_revenue?: number
  }
}

type TabId = 'overview' | 'accessories' | 'bookings' | 'maintenance' | 'expenses' | 'docs' | 'owner'

const VEHICLE_DETAIL_TABS = ['overview', 'accessories', 'bookings', 'maintenance', 'expenses', 'docs', 'owner'] as const satisfies readonly TabId[]

const tirePosLabel: Record<string, string> = {
  front_right: 'أمامي يمين',
  front_left: 'أمامي يسار',
  rear_right: 'خلفي يمين',
  rear_left: 'خلفي يسار',
  spare: 'احتياطي',
}

const statusLabel: Record<string, string> = {
  available: 'متاحة', reserved: 'محجوزة', rented: 'مؤجرة', maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة', pending: 'قيد الانتظار', approved: 'معتمدة',
  active: 'نشطة', completed: 'مكتملة', cancelled: 'ملغاة', open: 'مفتوحة',
  in_progress: 'قيد التنفيذ', closed: 'مغلقة',
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

const chartColors = ['#dc2626', '#64748b', '#f59e0b', '#059669', '#a1a1aa', '#b91c1c', '#475569']

const emptyAcc = {
  name: '', default_qty: '1', required_each_rental: true, is_active: true, notes: '',
}

function shortDate(value?: string | null) {
  if (!value) return '—'
  return civilDateOnly(value) || '—'
}

function monthLabel(ym: string) {
  const [, m] = ym.split('-')
  const names = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
  const idx = Number(m) - 1
  return names[idx] || ym
}

function Fact({ label, value, wide }: { label: string; value: ReactNode; wide?: boolean }) {
  return (
    <div className={`vd-fact${wide ? ' vd-fact--wide' : ''}`}>
      <span>{label}</span>
      <strong>{value ?? '—'}</strong>
    </div>
  )
}

function Empty({ children }: { children: ReactNode }) {
  return <div className="vd-empty">{children}</div>
}

export function VehicleDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { openNewContract } = useNewContract()
  const vehicleId = Number(id)

  const [dossier, setDossier] = useState<Dossier | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useQueryTab<TabId>({ allowed: VEHICLE_DETAIL_TABS, defaultValue: 'overview' })
  const [editOpen, setEditOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [docForm, setDocForm] = useState({ type: 'رخصة تسيير', document_number: '', expires_on: '', issued_on: '', notes: '', file_path: '', file_path_back: '' })
  const [docEdit, setDocEdit] = useState<Document | null>(null)
  const [docHistory, setDocHistory] = useState<Array<{ id: number; type: string; document_number?: string | null; issued_on?: string | null; expires_on?: string | null; archived_at?: string }>>([])
  const [accOpen, setAccOpen] = useState(false)
  const [accEdit, setAccEdit] = useState<Accessory | null>(null)
  const [accForm, setAccForm] = useState(emptyAcc)
  const [estimatedKm, setEstimatedKm] = useState<number | null>(null)
  const [woOpen, setWoOpen] = useState(false)
  const [expOpen, setExpOpen] = useState(false)
  const [assetSheet, setAssetSheet] = useState<'tire' | 'battery' | 'downtime' | null>(null)
  const [assetSaving, setAssetSaving] = useState(false)
  const [tireForm, setTireForm] = useState({
    position: 'front_right', brand: '', installed_on: todayCivilIso(), odometer_at_install: '', expected_life_km: '',
  })
  const [batteryForm, setBatteryForm] = useState({
    brand: '', installed_on: todayCivilIso(), odometer_at_install: '', expected_life_km: '',
  })
  const [downtimeForm, setDowntimeForm] = useState({
    reason: 'maintenance', starts_at: nowLocalDateTimeValue(), ends_at: '',
  })

  async function saveAsset() {
    if (!dossier || !assetSheet) return
    setAssetSaving(true)
    try {
      if (assetSheet === 'tire') {
        await api(`/vehicles/${dossier.vehicle.id}/tires`, {
          method: 'POST',
          json: {
            position: tireForm.position,
            brand: tireForm.brand || undefined,
            installed_on: tireForm.installed_on,
            odometer_at_install: Number(tireForm.odometer_at_install || dossier.vehicle.odometer),
            expected_life_km: tireForm.expected_life_km ? Number(tireForm.expected_life_km) : undefined,
          },
        })
        toast.success('تم تسجيل الإطار.')
      } else if (assetSheet === 'battery') {
        await api(`/vehicles/${dossier.vehicle.id}/batteries`, {
          method: 'POST',
          json: {
            brand: batteryForm.brand || undefined,
            installed_on: batteryForm.installed_on,
            odometer_at_install: Number(batteryForm.odometer_at_install || dossier.vehicle.odometer),
            expected_life_km: batteryForm.expected_life_km ? Number(batteryForm.expected_life_km) : undefined,
          },
        })
        toast.success('تم تسجيل البطارية.')
      } else {
        await api(`/vehicles/${dossier.vehicle.id}/downtime`, {
          method: 'POST',
          json: {
            reason: downtimeForm.reason,
            starts_at: downtimeForm.starts_at,
            ends_at: downtimeForm.ends_at || undefined,
          },
        })
        toast.success('تم تسجيل أيام التوقف.')
      }
      setAssetSheet(null)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setAssetSaving(false)
    }
  }

  const revenueSeries = useMemo(
    () => (dossier?.charts?.revenue_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const expenseSeries = useMemo(
    () => (dossier?.charts?.expenses_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const maintenanceSeries = useMemo(
    () => (dossier?.charts?.maintenance_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const statusDonut = useMemo(
    () => (dossier?.charts?.contracts_by_status || [])
      .filter((s) => s.count > 0)
      .map((s) => ({
        key: s.status,
        name: statusLabel[s.status] || s.label || s.status,
        value: s.count,
      })),
    [dossier],
  )


  async function load() {
    if (!Number.isFinite(vehicleId) || vehicleId <= 0) {
      setError('معرّف العربية غير صالح.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Dossier }>(`/vehicles/${vehicleId}/dossier`)
      setDossier(res.data)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [vehicleId])
  const life = useContractLifecycle(() => { void load() })

  async function closeWorkOrder(id: number) {
    try {
      await api(`/work-orders/${id}/close`, { method: 'POST' })
      toast.success('تم إغلاق أمر الشغل.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function confirmViolation(id: number) {
    try {
      await api(`/violations/${id}/confirm`, { method: 'POST' })
      toast.success('تم تأكيد سداد المخالفة.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  function toContract(c: Dossier['contracts'][number]): Contract {
    return {
      id: c.id,
      number: c.number,
      status: c.status,
      starts_at: c.starts_at || '',
      planned_ends_at: c.planned_ends_at || '',
      actual_ends_at: c.actual_ends_at,
      frozen_daily_rate: dossier?.vehicle.daily_rate || 0,
      final_total: c.final_total ?? undefined,
      period_rent: c.period_rent ?? undefined,
      customer: c.customer
        ? { id: c.customer.id, full_name: c.customer.full_name, phone: c.customer.phone || undefined }
        : undefined,
      vehicle: dossier
        ? {
            id: dossier.vehicle.id,
            brand: dossier.vehicle.brand,
            model: dossier.vehicle.model,
            plate: dossier.vehicle.plate,
            odometer: dossier.vehicle.odometer,
          }
        : undefined,
    }
  }
  useEffect(() => {
    if (!Number.isFinite(vehicleId) || vehicleId <= 0) return
    void api<{ data: typeof docHistory }>(`/vehicles/${vehicleId}/document-history`)
      .then((res) => setDocHistory(res.data || []))
      .catch(() => setDocHistory([]))
  }, [vehicleId, dossier?.vehicle.documents?.length])


  function openAccCreate() {
    setAccEdit(null)
    setAccForm(emptyAcc)
    setAccOpen(true)
  }

  function openAccEdit(a: Accessory) {
    setAccEdit(a)
    setAccForm({
      name: a.name,
      default_qty: String(a.default_qty ?? 1),
      required_each_rental: a.required_each_rental !== false,
      is_active: a.is_active !== false,
      notes: a.notes || '',
    })
    setAccOpen(true)
  }

  async function saveAccessory() {
    if (!dossier || !accForm.name.trim()) {
      toast.error('اسم المستلزم مطلوب.')
      return
    }
    setSaving(true)
    try {
      const body = {
        name: accForm.name.trim(),
        default_qty: Number(accForm.default_qty || 1),
        required_each_rental: accForm.required_each_rental,
        is_active: accForm.is_active,
        notes: accForm.notes || null,
      }
      if (accEdit) {
        await api(`/vehicles/${dossier.vehicle.id}/accessories/${accEdit.id}`, { method: 'PUT', json: body })
        toast.success('تم تحديث المستلزم.')
      } else {
        await api(`/vehicles/${dossier.vehicle.id}/accessories`, { method: 'POST', json: body })
        toast.success('تم إضافة المستلزم.')
      }
      setAccOpen(false)
      setAccEdit(null)
      setAccForm(emptyAcc)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function deleteAccessory(a: Accessory) {
    if (!dossier) return
    if (!window.confirm(`حذف المستلزم «${a.name}»؟`)) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}/accessories/${a.id}`, { method: 'DELETE' })
      toast.success('تم حذف المستلزم.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function addDocument() {
    if (!dossier) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}/documents`, {
        method: 'POST',
        json: {
          type: docForm.type,
          document_number: docForm.document_number || undefined,
          file_path: docForm.file_path || undefined,
          file_path_back: docForm.file_path_back || undefined,
          expires_on: docForm.expires_on || undefined,
          issued_on: docForm.issued_on || undefined,
          notes: docForm.notes || undefined,
        },
      })
      toast.success('تم تسجيل المستند.')
      setDocForm({ type: 'رخصة تسيير', document_number: '', expires_on: '', issued_on: '', notes: '', file_path: '', file_path_back: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function saveDocumentEdit() {
    if (!dossier || !docEdit) return
    setSaving(true)
    try {
      const isLicense = /رخص|license/i.test(docEdit.type)
      const res = await api<{ message?: string }>(`/vehicles/${dossier.vehicle.id}/documents/${docEdit.id}`, {
        method: 'PUT',
        json: {
          type: docEdit.type,
          document_number: docEdit.document_number || undefined,
          file_path: docEdit.file_path || undefined,
          file_path_back: docEdit.file_path_back || undefined,
          expires_on: docEdit.expires_on || undefined,
          issued_on: docEdit.issued_on || undefined,
          notes: docEdit.notes || undefined,
          archive_previous: isLicense,
        },
      })
      toast.success(res.message || (isLicense ? 'تم تحديث الرخصة مع حفظ السجل السابق.' : 'تم تحديث المستند.'))
      setDocEdit(null)
      await load()
      const hist = await api<{ data: typeof docHistory }>(`/vehicles/${dossier.vehicle.id}/document-history`)
      setDocHistory(hist.data || [])
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function deleteDocument(d: Document) {
    if (!dossier) return
    if (!window.confirm(`حذف مستند «${d.type}»؟`)) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}/documents/${d.id}`, { method: 'DELETE' })
      toast.success('تم حذف المستند.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function loadEstimated() {
    if (!dossier) return
    try {
      const res = await api<{ data: { estimated: number } }>(`/vehicles/${dossier.vehicle.id}/estimated-odometer`)
      setEstimatedKm(res.data.estimated)
      toast.success('تم حساب العداد التقديري.')
    } catch {
      setEstimatedKm(null)
      toast.error('تعذّر حساب العداد التقديري.')
    }
  }

  if (loading) {
    return <div className="vd"><SkeletonBlock rows={10} /></div>
  }

  if (error || !dossier) {
    return (
      <div className="vd">
        <ErrorState
          title="تعذّر تحميل ملف العربية"
          description={error || 'لا توجد بيانات'}
          action={
            <div className="rpm-actions-row">
              <BackNavButton to="/vehicles" label="رجوع للعربيات" />
              <Button onClick={() => load()}>إعادة المحاولة</Button>
            </div>
          }
        />
      </div>
    )
  }

  const v = dossier.vehicle
  const accessories = v.accessories || []
  const ownerInitial = (v.owner?.full_name || 'م').trim().charAt(0)

  const openMaintenance = dossier.work_orders.filter((w) => ['open', 'in_progress'].includes(String(w.status))).length
  const activeBookings = dossier.contracts.filter((c) => ['pending', 'approved', 'active'].includes(String(c.status))).length
  const overdueBookings = dossier.contracts.filter(
    (c) => c.status === 'active' && c.planned_ends_at && new Date(c.planned_ends_at).getTime() < Date.now(),
  ).length

  const tabs: Array<{ id: TabId; label: string; count?: number; attention?: AttentionTone | null; attentionLabel?: string }> = [
    { id: 'overview', label: 'التفاصيل' },
    { id: 'accessories', label: 'المستلزمات', count: accessories.length },
    {
      id: 'bookings',
      label: 'الحجوزات',
      count: dossier.totals.contracts,
      attention: overdueBookings > 0 ? 'critical' : activeBookings > 0 ? 'warning' : null,
      attentionLabel: overdueBookings > 0 ? 'عقد متأخر' : 'عقود جارية',
    },
    {
      id: 'maintenance',
      label: 'الصيانة',
      count: dossier.totals.work_orders,
      attention: openMaintenance > 0 || (dossier.totals.lost_revenue || 0) > 0
        ? (v.manual_status === 'maintenance' ? 'critical' : 'warning')
        : null,
      attentionLabel: openMaintenance > 0 ? 'أوامر شغل مفتوحة' : 'دخل ضائع من التوقف',
    },
    { id: 'expenses', label: 'المصروفات', count: dossier.expenses.length },
    { id: 'docs', label: 'المستندات', count: (v.documents?.length || 0) + dossier.totals.media },
    { id: 'owner', label: 'المالك' },
  ]

  const overviewPanel = (
    <section className="vd-panel" key="overview">
      <div className="vd-panel__head">
        <h2>البيانات الأساسية</h2>
        <p>المواصفات · التعريف · التشغيل</p>
      </div>
      <div className="vd-facts">
        <Fact label="الكود الداخلي" value={v.code} />
        <Fact label="اللوحة" value={v.plate} />
        <Fact label="الماركة / الموديل" value={`${v.brand} ${v.model}`} />
        <Fact label="سنة الصنع" value={v.year} />
        <Fact label="الفئة" value={v.category} />
        <Fact label="الوقود" value={v.fuel_type} />
        <Fact label="اللون" value={v.color || '—'} />
        <Fact label="ناقل الحركة" value={v.transmission || '—'} />
        <Fact label="المقاعد" value={v.seats ?? '—'} />
        <Fact label="سعة المحرك" value={v.engine_capacity || '—'} />
        <Fact label="رقم الشاسيه" value={v.chassis_number || '—'} />
        <Fact label="رقم المحرك" value={v.engine_number || '—'} />
      </div>

      <div className="vd-panel__head" style={{ marginTop: '0.35rem' }}>
        <h2>التشغيل والأسعار</h2>
      </div>
      <div className="vd-facts">
        <Fact label="العداد الفعلي" value={`${formatNumber(v.odometer)} كم`} />
        <Fact label="مصدر العداد" value={v.odometer_source || '—'} />
        <Fact label="الإيجار اليومي" value={formatMoney(v.daily_rate)} />
        <Fact label="التأمين النقدي" value={formatMoney(v.default_deposit)} />
        <Fact label="الحالة التشغيلية" value={statusLabel[v.status] || v.status} />
        <Fact label="حالة يدوية" value={v.manual_status || '—'} />
        <Fact label="نشطة" value={v.is_active === false ? 'لا' : 'نعم'} />
        <Fact label="جهاز التتبع" value={v.tracker_device_number || '—'} />
        <Fact label="شريحة التتبع" value={v.tracker_sim_number || '—'} />
        <Fact label="آخر شحن شريحة" value={shortDate(v.tracker_sim_last_recharged_on)} />
        <Fact label="شحن الشريحة القادم" value={shortDate(v.tracker_sim_next_recharge_on)} />
        <Fact label="دورة شحن الشريحة" value={v.tracker_sim_recharge_cycle_days != null ? `${v.tracker_sim_recharge_cycle_days} يوماً` : '—'} />
        <Fact label="أُنشئت" value={shortDate(v.created_at)} />
        <Fact label="آخر تحديث" value={shortDate(v.updated_at)} />
        <Fact label="ملاحظات" value={v.notes || '—'} wide />
      </div>
      {estimatedKm != null ? (
        <p className="vd-note">العداد التقديري أثناء التأجير: {formatNumber(estimatedKm)} كم</p>
      ) : null}
    </section>
  )

  const chartsPanel = (
    <section className="vd-panel" key="charts">
      <div className="vd-panel__head">
        <h2>تحليل العربية</h2>
        <p>إيراد · مصروف · صيانة · توزيع العقود</p>
      </div>
      <div className="vd-chart-kpis">
        <div className="vd-kpi"><span>إيراد العقود</span><strong>{formatMoney(dossier.totals.revenue_amount || 0)}</strong></div>
        <div className="vd-kpi"><span>المصروفات</span><strong>{formatMoney(dossier.totals.expenses_amount)}</strong></div>
        <div className="vd-kpi"><span>تكلفة الصيانة</span><strong>{formatMoney(dossier.totals.maintenance_cost || 0)}</strong></div>
        <div className="vd-kpi"><span>المخالفات</span><strong>{dossier.totals.violations}</strong></div>
      </div>

      <div className="vd-charts">
        <article className="vd-chart-card">
          <div className="vd-chart-card__head">
            <h3>الإيراد الشهري</h3>
            <p>آخر ١٢ شهراً</p>
          </div>
          <div className="vd-chart-card__body">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={revenueSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <YAxis tickLine={false} axisLine={false} width={48} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <Tooltip formatter={(value) => [formatMoney(Number(value ?? 0)), 'الإيراد']} contentStyle={{ borderRadius: 12, border: '1px solid var(--border)' }} />
                <Area type="monotone" dataKey="amount" stroke="var(--brand)" strokeWidth={2.4} fill="var(--brand)" fillOpacity={0.08} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </article>

        <article className="vd-chart-card">
          <div className="vd-chart-card__head">
            <h3>المصروفات الشهرية</h3>
            <p>مرتبطة بالعربية</p>
          </div>
          <div className="vd-chart-card__body">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={expenseSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <YAxis tickLine={false} axisLine={false} width={48} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <Tooltip formatter={(value) => [formatMoney(Number(value ?? 0)), 'المصروف']} contentStyle={{ borderRadius: 12, border: '1px solid var(--border)' }} />
                <Bar dataKey="amount" fill="var(--steel)" radius={[8, 8, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </article>

        <article className="vd-chart-card">
          <div className="vd-chart-card__head">
            <h3>تكلفة الصيانة</h3>
            <p>قطع + عمالة</p>
          </div>
          <div className="vd-chart-card__body">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={maintenanceSeries} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <YAxis tickLine={false} axisLine={false} width={48} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} />
                <Tooltip formatter={(value) => [formatMoney(Number(value ?? 0)), 'التكلفة']} contentStyle={{ borderRadius: 12, border: '1px solid var(--border)' }} />
                <Bar dataKey="amount" fill="var(--warning)" radius={[8, 8, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </article>

        <article className="vd-chart-card">
          <div className="vd-chart-card__head">
            <h3>توزيع العقود</h3>
            <p>حسب الحالة</p>
          </div>
          <div className="vd-chart-card__body vd-chart-card__body--pie">
            {statusDonut.length === 0 ? <Empty>لا عقود للرسم</Empty> : (
              <>
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={statusDonut} dataKey="value" nameKey="name" innerRadius="62%" outerRadius="88%" paddingAngle={3} strokeWidth={0}>
                      {statusDonut.map((entry, i) => (
                        <Cell key={entry.key} fill={chartColors[i % chartColors.length]} />
                      ))}
                    </Pie>
                    <Tooltip contentStyle={{ borderRadius: 12, border: '1px solid var(--border)' }} />
                  </PieChart>
                </ResponsiveContainer>
                <ul className="vd-chart-legend">
                  {statusDonut.map((item, i) => (
                    <li key={item.key}>
                      <i style={{ background: chartColors[i % chartColors.length] }} />
                      <span>{item.name}</span>
                      <b>{item.value}</b>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </article>
      </div>
    </section>
  )

  const accessoriesPanel = (
    <section className="vd-panel" key="accessories">
      <div className="vd-panel__head">
        <div>
          <h2>المستلزمات</h2>
          <p>تُراجع مع كل تأجير · {accessories.length} عنصر</p>
        </div>
        <Button size="sm" className="vd-no-print" onClick={openAccCreate}>إضافة مستلزم</Button>
      </div>
      {accessories.length === 0 ? (
        <Empty>
          لا مستلزمات بعد.
          <div className="vd-no-print" style={{ marginTop: '0.75rem' }}>
            <Button size="sm" onClick={openAccCreate}>إضافة أول مستلزم</Button>
          </div>
        </Empty>
      ) : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--stack">
            <thead>
              <tr>
                <th>المستلزم</th>
                <th>الكمية</th>
                <th>مطلوب بكل تأجير</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
                <th className="vd-no-print"></th>
              </tr>
            </thead>
            <tbody>
              {accessories.map((a) => (
                <tr key={a.id}>
                  <td data-label="المستلزم"><strong className="rpm-table__primary">{a.name}</strong></td>
                  <td data-label="الكمية">{a.default_qty ?? 1}</td>
                  <td data-label="مطلوب بكل تأجير">{a.required_each_rental === false ? 'لا' : 'نعم'}</td>
                  <td data-label="الحالة">{a.is_active === false ? 'موقوف' : 'نشط'}</td>
                  <td data-label="ملاحظات">{a.notes || '—'}</td>
                  <td data-label="" className="vd-no-print">
                    <div className="rpm-actions-row" style={{ justifyContent: 'flex-end' }}>
                      <Button size="sm" variant="ghost" onClick={() => openAccEdit(a)}>تعديل</Button>
                      <Button size="sm" variant="danger" loading={saving} onClick={() => deleteAccessory(a)}>حذف</Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const bookingsPanel = (
    <section className="vd-panel" key="bookings">
      <div className="vd-panel__head">
        <div>
          <h2>سجل الحجز والعقود</h2>
          <p>{dossier.totals.contracts} عقد</p>
        </div>
        <Button size="sm" className="vd-no-print" variant="accent" onClick={() => openNewContract({ vehicleId: v.id })}>عقد جديد</Button>
      </div>
      {dossier.contracts.length === 0 ? (
        <Empty>
          لا حجوزات لهذه العربية بعد
          <div className="vd-no-print" style={{ marginBlockStart: '0.75rem' }}>
            <Button size="sm" onClick={() => openNewContract({ vehicleId: v.id })}>إنشاء أول عقد</Button>
          </div>
        </Empty>
      ) : (
        <div className="rpm-card-list">
          {dossier.contracts.map((c) => {
            const contract = toContract(c)
            return (
              <article key={c.id} className="rpm-card">
                <div className="rpm-card__row">
                  <div>
                    <Link className="rpm-table__code" to={`/contracts/${c.id}`}>{c.number}</Link>
                    <p className="muted">{c.customer?.full_name || '—'} · {shortDate(c.starts_at)} → {shortDate(c.actual_ends_at || c.planned_ends_at)}</p>
                    {c.customer?.phone ? (
                      <div style={{ marginBlockStart: '0.35rem' }}>
                        <PhoneActions phone={c.customer.phone} name={c.customer.full_name} compact />
                      </div>
                    ) : null}
                  </div>
                  <StatusPill status={c.status} label={statusLabel[c.status] || c.status} />
                </div>
                <p className="tabular-nums">{c.final_total != null ? formatMoney(c.final_total) : (c.period_rent != null ? formatMoney(c.period_rent) : '—')}</p>
                <div className="vd-no-print">
                  <ContractActionsBar
                    contract={contract}
                    onApprove={() => void life.approve(c.id)}
                    onAction={(kind) => void life.openAction(kind, contract)}
                    onPrint={(kind = 'contract') => void printContractDocument(c.id, kind, toast.error)}
                  />
                </div>
              </article>
            )
          })}
        </div>
      )}
    </section>
  )

  const maintenancePanel = (
    <section className="vd-panel" key="maintenance">
      <div className="vd-panel__head">
        <div>
          <h2>الصيانات</h2>
          <p>{dossier.totals.work_orders} أمر · تكلفة {formatMoney(dossier.totals.maintenance_cost || 0)}</p>
        </div>
        <Button size="sm" className="vd-no-print" onClick={() => setWoOpen(true)}>أمر صيانة</Button>
      </div>
      {dossier.work_orders.length === 0 ? (
        <Empty>
          لا صيانات مسجّلة
          <div className="vd-no-print" style={{ marginBlockStart: '0.75rem' }}>
            <Button size="sm" onClick={() => setWoOpen(true)}>فتح أول أمر صيانة</Button>
          </div>
        </Empty>
      ) : (
        <div className="rpm-card-list">
          {dossier.work_orders.map((w) => (
            <article key={w.id} className="rpm-card">
              <div className="rpm-card__row">
                <div>
                  <strong>أمر #{w.id}</strong>
                  <p className="muted">{w.workshop || '—'} · فتح {w.opened_on || '—'} · عداد {w.odometer != null ? formatNumber(w.odometer) : '—'}</p>
                </div>
                <StatusPill status={w.status} label={statusLabel[w.status] || w.status} />
              </div>
              <p className="tabular-nums">{formatMoney(w.parts_cost || 0)} قطع · {formatMoney(w.labor_cost || 0)} عمالة</p>
              {w.lines.length > 0 ? <p className="muted">{w.lines.map((l) => l.description).join(' · ')}</p> : null}
              {['open', 'in_progress'].includes(String(w.status)) ? (
                <div className="rpm-card__actions vd-no-print">
                  <Button size="sm" onClick={() => void closeWorkOrder(w.id)}>إغلاق أمر الشغل</Button>
                </div>
              ) : null}
            </article>
          ))}
        </div>
      )}
      <div className="vd-panel__head">
        <div>
          <h2>المخالفات</h2>
          <p>{dossier.totals.violations}</p>
        </div>
        <Button size="sm" className="vd-no-print" variant="secondary" onClick={() => navigate('/violations')}>تسجيل مخالفة</Button>
      </div>
      {dossier.violations.length === 0 ? <Empty>لا مخالفات</Empty> : (
        <div className="rpm-card-list">
          {dossier.violations.map((x) => (
            <article key={x.id} className="rpm-card">
              <div className="rpm-card__row">
                <div>
                  <strong>{x.location || 'مخالفة'}</strong>
                  <p className="muted">{shortDate(x.occurred_at)} · {x.customer?.full_name || '—'}</p>
                </div>
                <strong className="tabular-nums">{x.amount != null ? formatMoney(x.amount) : '—'}</strong>
              </div>
              <div className="rpm-card__actions">
                <StatusPill status={x.status || 'open'} label={statusLabel[x.status || ''] || x.status || '—'} />
                {x.status && !['paid', 'confirmed'].includes(x.status) ? (
                  <Button size="sm" className="vd-no-print" onClick={() => void confirmViolation(x.id)}>تأكيد السداد</Button>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  )

  const expensesPanel = (
    <section className="vd-panel" key="expenses">
      <div className="vd-panel__head">
        <div>
          <h2>المصروفات</h2>
          <p>الإجمالي {formatMoney(dossier.totals.expenses_amount)}</p>
        </div>
        <Button size="sm" className="vd-no-print" onClick={() => setExpOpen(true)}>تسجيل مصروف</Button>
      </div>
      {dossier.expenses.length === 0 ? (
        <Empty>
          لا مصروفات مرتبطة
          <div className="vd-no-print" style={{ marginBlockStart: '0.75rem' }}>
            <Button size="sm" onClick={() => setExpOpen(true)}>تسجيل مصروف</Button>
          </div>
        </Empty>
      ) : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--stack">
            <thead><tr><th>العنوان</th><th>التصنيف</th><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th></tr></thead>
            <tbody>
              {dossier.expenses.map((e) => (
                <tr key={e.id}>
                  <td data-label="العنوان"><strong className="rpm-table__primary">{e.title}</strong></td>
                  <td data-label="التصنيف">{e.category || '—'}</td>
                  <td data-label="التاريخ">{e.spent_on || '—'}</td>
                  <td data-label="المبلغ">{formatMoney(e.amount)}</td>
                  <td data-label="الطريقة">{e.payment_method || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const docsPanel = (
    <section className="vd-panel" key="docs">
      <div className="vd-panel__head">
        <h2>المستندات الرسمية</h2>
        <p>{(v.documents || []).length} مستند</p>
      </div>
      {(v.documents || []).length === 0 ? <Empty>لا مستندات بعد</Empty> : (
        <div className="vd-docs">
          {(v.documents || []).map((d) => (
            <div key={d.id} className="vd-doc">
              <div>
                <strong>{d.type}</strong>
                <div>
                  <span>
                    {d.document_number ? `رقم ${d.document_number} · ` : ''}
                    صدر {d.issued_on ? civilDateOnly(d.issued_on) : '—'} · ينتهي {d.expires_on ? civilDateOnly(d.expires_on) : '—'}
                    {d.file_path ? ' · وجه' : ''}
                    {d.file_path_back ? ' · ظهر' : ''}
                  </span>
                </div>
              </div>
              <div className="rpm-actions-row vd-no-print">
                <Button size="sm" variant="ghost" onClick={() => setDocEdit({
                  ...d,
                  issued_on: d.issued_on ? civilDateOnly(d.issued_on) : '',
                  expires_on: d.expires_on ? civilDateOnly(d.expires_on) : '',
                })}>تعديل</Button>
                <Button size="sm" variant="danger" loading={saving} onClick={() => deleteDocument(d)}>حذف</Button>
              </div>
            </div>
          ))}
        </div>
      )}
      <div className="vd-form-block vd-no-print">
        <div className="rpm-form-grid">
          <SelectField
            label="نوع المستند"
            value={docForm.type}
            onChange={(e) => setDocForm({ ...docForm, type: e.target.value })}
            options={[
              { value: 'رخصة تسيير', label: 'رخصة تسيير' },
              { value: 'تأمين', label: 'تأمين' },
              { value: 'فحص فني', label: 'فحص فني' },
              { value: 'أخرى', label: 'أخرى' },
            ]}
          />
          <TextField
            label={/رخص|license/i.test(docForm.type) ? 'رقم الرخصة' : 'رقم المستند'}
            value={docForm.document_number}
            onChange={(e) => setDocForm({ ...docForm, document_number: e.target.value })}
          />
          <DateField label="تاريخ الإصدار" value={docForm.issued_on} onChange={(e) => setDocForm({ ...docForm, issued_on: e.target.value })} />
          <DateField label="تاريخ الانتهاء" value={docForm.expires_on} onChange={(e) => setDocForm({ ...docForm, expires_on: e.target.value })} />
          <MediaUpload
            label="وجه المستند (وش)"
            value={docForm.file_path || null}
            collection="vehicle_docs"
            purpose="document"
            accept="both"
            attachableType="vehicle"
            attachableId={v.id}
            onChange={(path) => setDocForm({ ...docForm, file_path: path || '' })}
          />
          <MediaUpload
            label="ظهر المستند (ضهر)"
            value={docForm.file_path_back || null}
            collection="vehicle_docs"
            purpose="document"
            accept="both"
            attachableType="vehicle"
            attachableId={v.id}
            onChange={(path) => setDocForm({ ...docForm, file_path_back: path || '' })}
          />
          <Button size="sm" loading={saving} onClick={() => addDocument()}>إضافة مستند</Button>
        </div>
      </div>

      <div className="vd-panel__head" style={{ marginTop: '0.75rem' }}>
        <h2>سجل الرخص والمستندات السابقة</h2>
        <p>{docHistory.length} أرشيف</p>
      </div>
      {docHistory.length === 0 ? (
        <Empty>لا يوجد سجل سابق — عند تحديث رخصة تُحفظ النسخة القديمة هنا تلقائياً</Empty>
      ) : (
        <div className="vd-docs">
          {docHistory.map((h) => (
            <div key={h.id} className="vd-doc">
              <div>
                <strong>{h.type}</strong>
                <div>
                  <span>
                    {h.document_number ? `رقم ${h.document_number} · ` : ''}
                    صدر {h.issued_on || '—'} · ينتهي {h.expires_on || '—'} · أُرشف {h.archived_at ? h.archived_at.slice(0, 16).replace('T', ' ') : '—'}
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="vd-panel__head" style={{ marginTop: '0.5rem' }}>
        <h2>المرفقات والوسائط</h2>
        <p>{dossier.totals.media} ملف</p>
      </div>
      {dossier.media.length === 0 ? <Empty>لا مرفقات مرفوعة</Empty> : (
        <div className="vd-media">
          {dossier.media.map((m) => (
            <a key={m.id} href={m.url} target="_blank" rel="noreferrer">
              <strong>{m.original_name}</strong>
              <span>{m.kind} · {m.purpose || m.collection || '—'}</span>
            </a>
          ))}
        </div>
      )}
      <div className="vd-form-block vd-no-print">
        <MediaUpload
          label="رفع مرفق للعربية"
          value={null}
          collection="vehicle_docs"
          purpose="document"
          accept="both"
          attachableType="vehicle"
          attachableId={v.id}
          onChange={() => { void load() }}
        />
      </div>
    </section>
  )

  const ownerPanel = (
    <section className="vd-panel" key="owner">
      <div className="vd-panel__head">
        <h2>المالك الأساسي</h2>
        <p>{v.owner ? 'عربيات الغير' : 'ملك المركز'}</p>
      </div>
      {v.owner ? (
        <div className="vd-owner">
          <div className="vd-owner__row">
            <div className="vd-owner__avatar" aria-hidden>{ownerInitial}</div>
            <div className="vd-owner__meta">
              <strong>{v.owner.full_name}</strong>
              {v.owner.phone ? (
                <PhoneActions phone={v.owner.phone} name={v.owner.full_name} />
              ) : (
                <span>بدون هاتف</span>
              )}
              <span>شهري {formatMoney(v.owner_monthly_amount || 0)} · ربط منذ {v.owner_since || '—'}</span>
            </div>
          </div>
          <div className="vd-facts" style={{ marginBlockStart: '0.75rem' }}>
            <Fact label="بداية العقد" value={shortDate(v.owner_agreement_starts_on)} />
            <Fact label="نهاية العقد" value={shortDate(v.owner_agreement_ends_on)} />
          </div>
          <div className="vd-no-print" style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginBlockStart: '0.75rem' }}>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => {
                void api<{ data: { html: string } }>(`/vehicles/${v.id}/owner-agreement/print`)
                  .then((res) => {
                    printHtmlDocument(shellOwnerAgreementHtml(res.data.html))
                  })
                  .catch((e) => toast.error((e as ApiError).message || 'تعذّرت طباعة عقد المالك.'))
              }}
            >
              طباعة عقد المالك
            </Button>
            <Link className="rpm-link-btn" to={v.owner ? `/owners/${v.owner.id}` : '/owners'}>{v.owner ? 'ملف المالك' : 'صفحة الملاك'}</Link>
          </div>
        </div>
      ) : (
        <Empty>هذه العربية ملك المركز — لا يوجد مالك خارجي مرتبط.</Empty>
      )}
      {(dossier.owner_dues.length > 0 || dossier.owner_payouts.length > 0) ? (
        <div className="vd-split">
          <div>
            <h3>المستحقات الشهرية</h3>
            {dossier.owner_dues.length === 0 ? <Empty>لا مستحقات</Empty> : (
              <div className="rpm-table-wrap">
                <table className="rpm-table rpm-table--compact rpm-table--stack">
                  <thead><tr><th>الشهر</th><th>المبلغ</th></tr></thead>
                  <tbody>
                    {dossier.owner_dues.map((d) => (
                      <tr key={d.id}><td data-label="الشهر">{d.year_month}</td><td data-label="المبلغ">{formatMoney(d.amount)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
          <div>
            <h3>المدفوعات</h3>
            {dossier.owner_payouts.length === 0 ? <Empty>لا مدفوعات</Empty> : (
              <div className="rpm-table-wrap">
                <table className="rpm-table rpm-table--compact rpm-table--stack">
                  <thead><tr><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th></tr></thead>
                  <tbody>
                    {dossier.owner_payouts.map((p) => (
                      <tr key={p.id}>
                        <td data-label="التاريخ">{p.paid_on || '—'}</td>
                        <td data-label="المبلغ">{formatMoney(p.amount)}</td>
                        <td data-label="الطريقة">{p.payment_method || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      ) : null}
      <div className="vd-panel__head" style={{ marginTop: '0.35rem' }}>
        <h2>رمز QR</h2>
      </div>
      <QrCode
        className="vd-qr-panel"
        value={dossier.qr.payload}
        label={dossier.qr.label}
        fileName={`rpm-${v.code}-qr`}
        size={176}
      />
    </section>
  )

  const assetsPanel = (
    <section className="vd-panel vd-panel--spaced" key="assets">
      <div className="vd-panel__head">
        <div>
          <h2>الإطارات · البطارية · التوقف · القطع</h2>
          <p>دخل ضائع تقديري {formatMoney(dossier.totals.lost_revenue || 0)}</p>
        </div>
      </div>

      <div className="vd-assets-grid">
        <article className="vd-asset-card">
          <div className="vd-asset-card__head">
            <h3>الإطارات</h3>
            <Button size="sm" variant="secondary" className="vd-no-print" onClick={() => setAssetSheet('tire')}>+ تسجيل إطار</Button>
          </div>
          {(dossier.tires || []).length === 0 ? <Empty>لا إطارات مسجّلة</Empty> : (
            <div className="rpm-card-list">
              {(dossier.tires || []).map((t) => (
                <article key={t.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong>{tirePosLabel[t.position] || t.position}</strong>
                    <span className="tabular-nums">{formatNumber(t.expected_life_km)} كم عمر</span>
                  </div>
                  <p className="muted">{t.brand || '—'} · تركيب {shortDate(t.installed_on)} · عداد {formatNumber(t.odometer_at_install)}</p>
                </article>
              ))}
            </div>
          )}
        </article>

        <article className="vd-asset-card">
          <div className="vd-asset-card__head">
            <h3>البطارية</h3>
            <Button size="sm" variant="secondary" className="vd-no-print" onClick={() => setAssetSheet('battery')}>+ تسجيل بطارية</Button>
          </div>
          {(dossier.batteries || []).length === 0 ? <Empty>لا بطارية مسجّلة</Empty> : (
            <div className="rpm-card-list">
              {(dossier.batteries || []).map((b) => (
                <article key={b.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong>{b.brand || 'بطارية'}</strong>
                    <span className="tabular-nums">{formatNumber(b.expected_life_km)} كم</span>
                  </div>
                  <p className="muted">تركيب {shortDate(b.installed_on)} · عداد {formatNumber(b.odometer_at_install)}</p>
                </article>
              ))}
            </div>
          )}
        </article>

        <article className="vd-asset-card">
          <div className="vd-asset-card__head">
            <h3>أيام التوقف</h3>
            <Button size="sm" variant="secondary" className="vd-no-print" onClick={() => setAssetSheet('downtime')}>+ تسجيل توقف</Button>
          </div>
          {(dossier.downtime || []).length === 0 ? <Empty>لا توقف مسجّل</Empty> : (
            <div className="rpm-card-list">
              {(dossier.downtime || []).map((d) => (
                <article key={d.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong>{d.reason === 'maintenance' ? 'صيانة' : d.reason === 'out_of_service' ? 'خارج الخدمة' : 'أخرى'}</strong>
                    <strong className="tabular-nums">{formatMoney(d.lost_revenue_estimate || 0)}</strong>
                  </div>
                  <p className="muted">{shortDate(d.starts_at)} → {shortDate(d.ends_at)} · {formatNumber(d.days_count || 0)} يوم</p>
                </article>
              ))}
            </div>
          )}
        </article>

        <article className="vd-asset-card">
          <div className="vd-asset-card__head">
            <h3>قطع استهلاكية</h3>
          </div>
          {(dossier.consumables || []).length === 0 ? <Empty>لا قوالب مربوطة بهذه العربية</Empty> : (
            <div className="rpm-card-list">
              {(dossier.consumables || []).map((c) => (
                <article key={c.id} className="rpm-card">
                  <div className="rpm-card__row">
                    <strong>{c.template?.name || 'قطعة'}</strong>
                    <span>{c.status}</span>
                  </div>
                  <p className="muted">استحقاق {shortDate(c.due_on)}{c.due_odometer != null ? ` · عداد ${formatNumber(c.due_odometer)}` : ''}</p>
                </article>
              ))}
            </div>
          )}
        </article>
      </div>
    </section>
  )

  const overviewCombined = (
    <>
      {chartsPanel}
      {overviewPanel}
    </>
  )

  const maintenanceCombined = (
    <>
      {maintenancePanel}
      {assetsPanel}
    </>
  )

  const panelByTab: Record<TabId, ReactNode> = {
    overview: overviewCombined,
    accessories: accessoriesPanel,
    bookings: bookingsPanel,
    maintenance: maintenanceCombined,
    expenses: expensesPanel,
    docs: docsPanel,
    owner: ownerPanel,
  }

  return (
    <div className="vd">
      <div className="vd-print-head">
        <strong>ملف عربية — RPM</strong>
        <span>{v.brand} {v.model} · {v.plate}</span>
      </div>

      <header className="vd-hero">
        <div className="vd-hero__top">
          <BackNavButton className="vd-no-print" to="/vehicles" label="رجوع للعربيات" />
          <div className="vd-hero__actions vd-no-print">
            <Button size="sm" variant="accent" onClick={() => openNewContract({ vehicleId: v.id })}>عقد جديد</Button>
            <Button size="sm" variant="secondary" onClick={() => setWoOpen(true)}>إضافة صيانة</Button>
            <Button size="sm" variant="secondary" onClick={() => setExpOpen(true)}>تسجيل مصروف</Button>
            <Button size="sm" variant="secondary" onClick={() => loadEstimated()}>عداد تقديري</Button>
            <Button size="sm" variant="secondary" onClick={() => setEditOpen(true)}>تعديل</Button>
            <Button
              size="sm"
              variant="primary"
              onClick={() => {
                const root = document.querySelector('.vd')
                if (root) printDomSubtree(root, `ملف عربية · ${v.code}`)
                else toast.error('تعذّر تجهيز ملف العربية للطباعة.')
              }}
            >
              طباعة
            </Button>
          </div>
        </div>
        <div className="vd-hero__body">
          <div className="vd-identity">
            <div className="vd-identity__status">
              <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
              <span className="vd-code">{v.code}</span>
            </div>
            <div className="vd-plate" aria-label={`لوحة ${v.plate}`}>
              <div className="vd-plate__band">EGYPT · RPM</div>
              <p className="vd-plate__num">{v.plate}</p>
            </div>
            <h1 className="vd-title">{v.brand} {v.model}</h1>
            <p className="vd-sub">{v.year}{v.color ? ` · ${v.color}` : ''}{v.transmission ? ` · ${v.transmission}` : ''}</p>
            <div className="vd-tags">
              <span className="vd-tag">{v.category}</span>
              <span className="vd-tag">{v.fuel_type}</span>
              <span className="vd-tag">{formatNumber(v.odometer)} كم</span>
              {v.seats ? <span className="vd-tag">{v.seats} مقاعد</span> : null}
              {v.owner ? <span className="vd-tag">مالك: {v.owner.full_name}</span> : <span className="vd-tag">ملك المركز</span>}
            </div>
          </div>
          <aside className="vd-hero__side">
            <div className="vd-rate">
              <span>الإيجار اليومي</span>
              <strong>{formatMoney(v.daily_rate)}</strong>
            </div>
            <QrCode
              className="vd-qr"
              value={dossier.qr.payload}
              label="امسح للوصول السريع"
              fileName={`rpm-${v.code}-qr`}
              size={124}
            />
          </aside>
        </div>
      </header>

      <div className="vd-kpis">
        <div className="vd-kpi"><span>الحجوزات</span><strong>{dossier.totals.contracts}</strong></div>
        <div className="vd-kpi"><span>الصيانة</span><strong>{dossier.totals.work_orders}</strong></div>
        <div className="vd-kpi"><span>المصروفات</span><strong>{formatMoney(dossier.totals.expenses_amount)}</strong></div>
        <div className="vd-kpi"><span>المستلزمات</span><strong>{accessories.length}</strong><em>{dossier.totals.media} مرفق</em></div>
      </div>

      <nav className="vd-tabs vd-no-print" aria-label="أقسام ملف العربية">
        {tabs.map((t) => (
          <button key={t.id} type="button" className={`vd-tab${tab === t.id ? ' is-active' : ''}`} onClick={() => setTab(t.id)}>
            <span className="rpm-attention-inline">
              {t.label}
              {t.attention ? (
                <AttentionDot
                  tone={t.attention}
                  placement="inline"
                  ping={t.attention === 'critical'}
                  label={t.attentionLabel}
                />
              ) : null}
            </span>
            {t.count != null ? <span className="vd-tab__count">{t.count}</span> : null}
          </button>
        ))}
      </nav>

      <div className="vd-no-print">{panelByTab[tab]}</div>
      <div className="vd-print-all">
        {chartsPanel}{overviewPanel}{accessoriesPanel}{assetsPanel}{bookingsPanel}{maintenancePanel}{expensesPanel}{docsPanel}{ownerPanel}
      </div>

      <Sheet
        open={!!assetSheet}
        title={assetSheet === 'tire' ? 'تسجيل إطار' : assetSheet === 'battery' ? 'تسجيل بطارية' : 'تسجيل توقف'}
        onClose={() => setAssetSheet(null)}
        footer={<><Button loading={assetSaving} onClick={() => void saveAsset()}>حفظ</Button><Button variant="secondary" onClick={() => setAssetSheet(null)}>إلغاء</Button></>}
      >
        {assetSheet === 'tire' ? (
          <div className="rpm-form-grid">
            <SelectField
              label="الموضع"
              value={tireForm.position}
              onChange={(e) => setTireForm({ ...tireForm, position: e.target.value })}
              options={Object.entries(tirePosLabel).map(([value, label]) => ({ value, label }))}
            />
            <TextField label="الماركة" value={tireForm.brand} onChange={(e) => setTireForm({ ...tireForm, brand: e.target.value })} />
            <TextField label="تاريخ التركيب" type="date" value={tireForm.installed_on} onChange={(e) => setTireForm({ ...tireForm, installed_on: e.target.value })} />
            <NumberField label="العداد عند التركيب" value={tireForm.odometer_at_install} onChange={(e) => setTireForm({ ...tireForm, odometer_at_install: e.target.value })} />
            <NumberField label="العمر المتوقع (كم)" value={tireForm.expected_life_km} onChange={(e) => setTireForm({ ...tireForm, expected_life_km: e.target.value })} />
          </div>
        ) : null}
        {assetSheet === 'battery' ? (
          <div className="rpm-form-grid">
            <TextField label="الماركة" value={batteryForm.brand} onChange={(e) => setBatteryForm({ ...batteryForm, brand: e.target.value })} />
            <TextField label="تاريخ التركيب" type="date" value={batteryForm.installed_on} onChange={(e) => setBatteryForm({ ...batteryForm, installed_on: e.target.value })} />
            <NumberField label="العداد عند التركيب" value={batteryForm.odometer_at_install} onChange={(e) => setBatteryForm({ ...batteryForm, odometer_at_install: e.target.value })} />
            <NumberField label="العمر المتوقع (كم)" value={batteryForm.expected_life_km} onChange={(e) => setBatteryForm({ ...batteryForm, expected_life_km: e.target.value })} />
          </div>
        ) : null}
        {assetSheet === 'downtime' ? (
          <div className="rpm-form-grid">
            <SelectField
              label="السبب"
              value={downtimeForm.reason}
              onChange={(e) => setDowntimeForm({ ...downtimeForm, reason: e.target.value })}
              options={[
                { value: 'maintenance', label: 'صيانة' },
                { value: 'out_of_service', label: 'خارج الخدمة' },
                { value: 'other', label: 'أخرى' },
              ]}
            />
            <TextField label="بداية التوقف" type="date" value={civilDateOnly(downtimeForm.starts_at)} onChange={(e) => setDowntimeForm({ ...downtimeForm, starts_at: e.target.value })} />
            <TextField label="نهاية التوقف" type="date" value={civilDateOnly(downtimeForm.ends_at)} onChange={(e) => setDowntimeForm({ ...downtimeForm, ends_at: e.target.value })} />
          </div>
        ) : null}
      </Sheet>

      <VehicleFormSheet
        open={editOpen}
        mode="edit"
        vehicleId={v.id}
        initial={v}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          setEditOpen(false)
          void load()
        }}
      />

      <Sheet
        open={accOpen}
        title={accEdit ? 'تعديل مستلزم' : 'إضافة مستلزم'}
        onClose={() => { setAccOpen(false); setAccEdit(null) }}
        footer={
          <>
            <Button loading={saving} onClick={() => saveAccessory()}>{accEdit ? 'حفظ التعديل' : 'إضافة'}</Button>
            <Button variant="secondary" onClick={() => { setAccOpen(false); setAccEdit(null) }}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <TextField label="اسم المستلزم" required value={accForm.name} onChange={(e) => setAccForm({ ...accForm, name: e.target.value })} hint="مثل: مثلث عاكس · طفاية · إطار احتياطي" />
          <NumberField label="الكمية الافتراضية" required value={accForm.default_qty} onChange={(e) => setAccForm({ ...accForm, default_qty: e.target.value })} />
          <SwitchField label="مطلوب في كل تأجير" checked={accForm.required_each_rental} onChange={(val) => setAccForm({ ...accForm, required_each_rental: val })} />
          <SwitchField label="نشط" checked={accForm.is_active} onChange={(val) => setAccForm({ ...accForm, is_active: val })} />
          <TextAreaField label="ملاحظات" value={accForm.notes} onChange={(e) => setAccForm({ ...accForm, notes: e.target.value })} />
        </div>
      </Sheet>

      <WorkOrderSheet
        open={woOpen}
        onClose={() => setWoOpen(false)}
        onSaved={() => void load()}
        vehicle={{ id: v.id, label: `${v.brand} ${v.model} · ${v.plate}`, odometer: v.odometer }}
      />
      <ExpenseSheet
        open={expOpen}
        onClose={() => setExpOpen(false)}
        onSaved={() => void load()}
        vehicle={{ id: v.id, label: `${v.brand} ${v.model} · ${v.plate}` }}
      />
      <ContractActionSheet
        action={life.action}
        selected={life.selected}
        vehicles={life.vehicles}
        saving={life.saving}
        form={life.actionForm}
        onChange={life.setActionForm}
        onConfirm={() => void life.runAction()}
        onClose={life.closeAction}
      />
      <Sheet
        open={!!docEdit}
        title="تعديل مستند"
        onClose={() => setDocEdit(null)}
        footer={
          <>
            <Button loading={saving} onClick={() => saveDocumentEdit()}>حفظ</Button>
            <Button variant="secondary" onClick={() => setDocEdit(null)}>إلغاء</Button>
          </>
        }
      >
        {docEdit ? (
          <div className="rpm-form-grid">
            <SelectField
              label="نوع المستند"
              value={docEdit.type}
              onChange={(e) => setDocEdit({ ...docEdit, type: e.target.value })}
              options={[
                { value: 'رخصة تسيير', label: 'رخصة تسيير' },
                { value: 'تأمين', label: 'تأمين' },
                { value: 'فحص فني', label: 'فحص فني' },
                { value: 'أخرى', label: 'أخرى' },
              ]}
            />
            <TextField
              label={/رخص|license/i.test(docEdit.type) ? 'رقم الرخصة' : 'رقم المستند'}
              value={docEdit.document_number || ''}
              onChange={(e) => setDocEdit({ ...docEdit, document_number: e.target.value })}
            />
            <DateField label="تاريخ الإصدار" value={docEdit.issued_on || ''} onChange={(e) => setDocEdit({ ...docEdit, issued_on: e.target.value })} />
            <DateField label="تاريخ الانتهاء" value={docEdit.expires_on || ''} onChange={(e) => setDocEdit({ ...docEdit, expires_on: e.target.value })} />
            <MediaUpload
              label="وجه المستند (وش)"
              value={docEdit.file_path || null}
              collection="vehicle_docs"
              purpose="document"
              accept="both"
              attachableType="vehicle"
              attachableId={v.id}
              onChange={(path) => setDocEdit({ ...docEdit, file_path: path || '' })}
            />
            <MediaUpload
              label="ظهر المستند (ضهر)"
              value={docEdit.file_path_back || null}
              collection="vehicle_docs"
              purpose="document"
              accept="both"
              attachableType="vehicle"
              attachableId={v.id}
              onChange={(path) => setDocEdit({ ...docEdit, file_path_back: path || '' })}
            />
            <TextAreaField label="ملاحظات" value={docEdit.notes || ''} onChange={(e) => setDocEdit({ ...docEdit, notes: e.target.value })} />
          </div>
        ) : null}
      </Sheet>
    </div>
  )
}
