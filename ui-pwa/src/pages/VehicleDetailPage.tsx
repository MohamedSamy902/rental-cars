import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  Button, StatusPill, Sheet, AttentionDot,
  TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField, MediaUpload,
  useToast, SkeletonBlock, ErrorState, QrCode,
  type AttentionTone,
} from '@/shared/ui'
import { useNewContract } from '@/shared/contracts/newContractContext'
import { ExpenseSheet } from '@/shared/forms/ExpenseSheet'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
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
  file_path?: string | null
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
  target_monthly_income?: number | null
  purchase_value_estimate?: number | null
  status: string
  manual_status?: string | null
  rental_priority: number
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
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

type TabId = 'overview' | 'charts' | 'accessories' | 'assets' | 'bookings' | 'maintenance' | 'expenses' | 'docs' | 'owner'

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
}

const chartColors = ['#dc2626', '#64748b', '#f59e0b', '#059669', '#a1a1aa', '#b91c1c', '#475569']

const emptyForm = {
  code: '', plate: '', brand: '', model: '', year: '', category: 'اقتصادي', fuel_type: 'بنزين',
  color: '', transmission: 'أوتوماتيك', chassis_number: '', engine_number: '', engine_capacity: '',
  seats: '5', odometer: '', daily_rate: '', default_deposit: '', rental_priority: '50',
  target_monthly_income: '', purchase_value_estimate: '',
  tracker_device_number: '', tracker_sim_number: '', notes: '', is_active: true,
  owner_id: '', owner_monthly_amount: '',
}

const emptyAcc = {
  name: '', default_qty: '1', required_each_rental: true, is_active: true, notes: '',
}

function shortDate(value?: string | null) {
  if (!value) return '—'
  return value.slice(0, 10)
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
  const [tab, setTab] = useState<TabId>('overview')
  const [editing, setEditing] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [owners, setOwners] = useState<OwnerOption[]>([])
  const [docForm, setDocForm] = useState({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '', file_path: '' })
  const [docEdit, setDocEdit] = useState<Document | null>(null)
  const [accOpen, setAccOpen] = useState(false)
  const [accEdit, setAccEdit] = useState<Accessory | null>(null)
  const [accForm, setAccForm] = useState(emptyAcc)
  const [estimatedKm, setEstimatedKm] = useState<number | null>(null)
  const [woOpen, setWoOpen] = useState(false)
  const [expOpen, setExpOpen] = useState(false)
  const [assetSheet, setAssetSheet] = useState<'tire' | 'battery' | 'downtime' | null>(null)
  const [assetSaving, setAssetSaving] = useState(false)
  const [tireForm, setTireForm] = useState({
    position: 'front_right', brand: '', installed_on: new Date().toISOString().slice(0, 10), odometer_at_install: '', expected_life_km: '',
  })
  const [batteryForm, setBatteryForm] = useState({
    brand: '', installed_on: new Date().toISOString().slice(0, 10), odometer_at_install: '', expected_life_km: '',
  })
  const [downtimeForm, setDowntimeForm] = useState({
    reason: 'maintenance', starts_at: new Date().toISOString().slice(0, 16), ends_at: '',
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
    () => (dossier?.charts.revenue_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const expenseSeries = useMemo(
    () => (dossier?.charts.expenses_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const maintenanceSeries = useMemo(
    () => (dossier?.charts.maintenance_by_month || []).map((p) => ({ ...p, label: monthLabel(p.month) })),
    [dossier],
  )
  const statusDonut = useMemo(
    () => (dossier?.charts.contracts_by_status || [])
      .filter((s) => s.count > 0)
      .map((s) => ({
        key: s.status,
        name: statusLabel[s.status] || s.label || s.status,
        value: s.count,
      })),
    [dossier],
  )

  function fillForm(v: Vehicle) {
    setForm({
      code: v.code,
      plate: v.plate,
      brand: v.brand,
      model: v.model,
      year: String(v.year),
      category: v.category,
      fuel_type: v.fuel_type,
      color: v.color || '',
      transmission: v.transmission || 'أوتوماتيك',
      chassis_number: v.chassis_number || '',
      engine_number: v.engine_number || '',
      engine_capacity: v.engine_capacity || '',
      seats: v.seats != null ? String(v.seats) : '5',
      odometer: String(v.odometer),
      daily_rate: String(v.daily_rate),
      default_deposit: String(v.default_deposit),
      rental_priority: String(v.rental_priority ?? 50),
      target_monthly_income: v.target_monthly_income != null ? String(v.target_monthly_income) : '',
      purchase_value_estimate: v.purchase_value_estimate != null ? String(v.purchase_value_estimate) : '',
      tracker_device_number: v.tracker_device_number || '',
      tracker_sim_number: v.tracker_sim_number || '',
      notes: v.notes || '',
      is_active: v.is_active !== false,
      owner_id: v.owner_id ? String(v.owner_id) : '',
      owner_monthly_amount: v.owner_monthly_amount != null ? String(v.owner_monthly_amount) : '',
    })
  }

  async function load() {
    if (!Number.isFinite(vehicleId) || vehicleId <= 0) {
      setError('معرّف المركبة غير صالح.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Dossier }>(`/vehicles/${vehicleId}/dossier`)
      setDossier(res.data)
      fillForm(res.data.vehicle)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [vehicleId])
  useEffect(() => {
    void api<{ data: OwnerOption[] }>('/owners?active_only=1&per_page=100')
      .then((res) => setOwners(res.data))
      .catch(() => setOwners([]))
  }, [])

  function vehiclePayload() {
    return {
      ...form,
      year: Number(form.year),
      odometer: Number(form.odometer),
      daily_rate: Number(form.daily_rate),
      default_deposit: Number(form.default_deposit),
      rental_priority: Number(form.rental_priority || 50),
      seats: form.seats ? Number(form.seats) : null,
      target_monthly_income: form.target_monthly_income !== '' ? Number(form.target_monthly_income) : null,
      purchase_value_estimate: form.purchase_value_estimate !== '' ? Number(form.purchase_value_estimate) : null,
      color: form.color || null,
      transmission: form.transmission || null,
      chassis_number: form.chassis_number || null,
      engine_number: form.engine_number || null,
      engine_capacity: form.engine_capacity || null,
      owner_id: form.owner_id ? Number(form.owner_id) : null,
      owner_monthly_amount: form.owner_id && form.owner_monthly_amount !== ''
        ? Number(form.owner_monthly_amount)
        : null,
    }
  }

  async function onUpdate() {
    if (!dossier) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}`, { method: 'PUT', json: vehiclePayload() })
      toast.success('تم تحديث المركبة.')
      setEditOpen(false)
      setEditing(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

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
          file_path: docForm.file_path || undefined,
          expires_on: docForm.expires_on || undefined,
          issued_on: docForm.issued_on || undefined,
          notes: docForm.notes || undefined,
        },
      })
      toast.success('تم تسجيل المستند.')
      setDocForm({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '', file_path: '' })
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
      await api(`/vehicles/${dossier.vehicle.id}/documents/${docEdit.id}`, {
        method: 'PUT',
        json: {
          type: docEdit.type,
          file_path: docEdit.file_path || undefined,
          expires_on: docEdit.expires_on || undefined,
          issued_on: docEdit.issued_on || undefined,
          notes: docEdit.notes || undefined,
        },
      })
      toast.success('تم تحديث المستند.')
      setDocEdit(null)
      await load()
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

  const formFields = (
    <div className="rpm-form-grid">
      <TextField label="الكود الداخلي" required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
      <TextField label="رقم اللوحة" required value={form.plate} onChange={(e) => setForm({ ...form, plate: e.target.value })} />
      <TextField label="الماركة" required value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} />
      <TextField label="الموديل" required value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
      <NumberField label="سنة الصنع" required value={form.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
      <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'دفع رباعي', label: 'دفع رباعي' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
      <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
      <TextField label="اللون" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
      <SelectField label="ناقل الحركة" options={[{ value: 'أوتوماتيك', label: 'أوتوماتيك' }, { value: 'مانيوال', label: 'مانيوال' }]} value={form.transmission} onChange={(e) => setForm({ ...form, transmission: e.target.value })} />
      <NumberField label="عدد المقاعد" value={form.seats} onChange={(e) => setForm({ ...form, seats: e.target.value })} />
      <TextField label="رقم الشاسيه" value={form.chassis_number} onChange={(e) => setForm({ ...form, chassis_number: e.target.value })} />
      <TextField label="رقم المحرك" value={form.engine_number} onChange={(e) => setForm({ ...form, engine_number: e.target.value })} />
      <TextField label="سعة المحرك" value={form.engine_capacity} onChange={(e) => setForm({ ...form, engine_capacity: e.target.value })} />
      <NumberField label="العداد الفعلي" required value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
      <MoneyField label="الإيجار اليومي" required value={form.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
      <MoneyField label="التأمين النقدي" required value={form.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
      <MoneyField label="المستهدف الشهري" value={form.target_monthly_income} onChange={(e) => setForm({ ...form, target_monthly_income: e.target.value })} />
      <MoneyField label="تقدير قيمة الشراء" value={form.purchase_value_estimate} onChange={(e) => setForm({ ...form, purchase_value_estimate: e.target.value })} />
      <NumberField label="أولوية التأجير" value={form.rental_priority} onChange={(e) => setForm({ ...form, rental_priority: e.target.value })} />
      <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
      <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />
      <SelectField
        label="المالك"
        value={form.owner_id}
        onChange={(e) => setForm({ ...form, owner_id: e.target.value })}
        options={[
          { value: '', label: '— ملك المركز —' },
          ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
        ]}
      />
      {form.owner_id ? (
        <MoneyField label="المبلغ الشهري للمالك" required value={form.owner_monthly_amount} onChange={(e) => setForm({ ...form, owner_monthly_amount: e.target.value })} />
      ) : null}
      <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
    </div>
  )

  if (loading) {
    return <div className="vd"><SkeletonBlock rows={10} /></div>
  }

  if (error || !dossier) {
    return (
      <div className="vd">
        <ErrorState
          title="تعذّر تحميل ملف المركبة"
          description={error || 'لا توجد بيانات'}
          action={
            <div className="rpm-actions-row">
              <Button variant="secondary" onClick={() => navigate('/vehicles')}>رجوع</Button>
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

  const assetCount = (dossier.tires?.length || 0) + (dossier.batteries?.length || 0) + (dossier.downtime?.length || 0) + (dossier.consumables?.length || 0)
  const tabs: Array<{ id: TabId; label: string; count?: number; attention?: AttentionTone | null; attentionLabel?: string }> = [
    { id: 'overview', label: 'التفاصيل' },
    { id: 'charts', label: 'الشارتات' },
    { id: 'accessories', label: 'المستلزمات', count: accessories.length },
    {
      id: 'assets',
      label: 'إطارات وبطارية',
      count: assetCount,
      attention: (dossier.totals.lost_revenue || 0) > 0 ? 'warning' : null,
      attentionLabel: 'دخل ضائع من التوقف',
    },
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
      attention: openMaintenance > 0 ? (v.manual_status === 'maintenance' ? 'critical' : 'warning') : null,
      attentionLabel: 'أوامر شغل مفتوحة',
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
        <Fact label="المستهدف الشهري" value={v.target_monthly_income != null ? formatMoney(v.target_monthly_income) : '—'} />
        <Fact label="تقدير قيمة الشراء" value={v.purchase_value_estimate != null ? formatMoney(v.purchase_value_estimate) : '—'} />
        <Fact label="أولوية التأجير" value={v.rental_priority} />
        <Fact label="الحالة التشغيلية" value={statusLabel[v.status] || v.status} />
        <Fact label="حالة يدوية" value={v.manual_status || '—'} />
        <Fact label="نشطة" value={v.is_active === false ? 'لا' : 'نعم'} />
        <Fact label="جهاز التتبع" value={v.tracker_device_number || '—'} />
        <Fact label="شريحة التتبع" value={v.tracker_sim_number || '—'} />
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
        <h2>تحليل المركبة</h2>
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
            <p>مرتبطة بالمركبة</p>
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
        <h2>سجل الحجز والعقود</h2>
        <p>{dossier.totals.contracts} عقد</p>
      </div>
      {dossier.contracts.length === 0 ? <Empty>لا حجوزات لهذه المركبة بعد</Empty> : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--stack">
            <thead>
              <tr><th>العقد</th><th>العميل</th><th>الحالة</th><th>من</th><th>إلى</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
              {dossier.contracts.map((c) => (
                <tr key={c.id}>
                  <td data-label="العقد"><span className="rpm-table__code">{c.number}</span></td>
                  <td data-label="العميل">{c.customer?.full_name || '—'}</td>
                  <td data-label="الحالة"><StatusPill status={c.status} label={statusLabel[c.status] || c.status} /></td>
                  <td data-label="من">{shortDate(c.starts_at)}</td>
                  <td data-label="إلى">{shortDate(c.actual_ends_at || c.planned_ends_at)}</td>
                  <td data-label="الإجمالي">{c.final_total != null ? formatMoney(c.final_total) : (c.period_rent != null ? formatMoney(c.period_rent) : '—')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const maintenancePanel = (
    <section className="vd-panel" key="maintenance">
      <div className="vd-panel__head">
        <h2>الصيانات</h2>
        <p>{dossier.totals.work_orders} أمر · تكلفة {formatMoney(dossier.totals.maintenance_cost || 0)}</p>
      </div>
      {dossier.work_orders.length === 0 ? <Empty>لا صيانات مسجّلة</Empty> : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--stack">
            <thead>
              <tr><th>#</th><th>الحالة</th><th>الورشة</th><th>الفتح</th><th>الإغلاق</th><th>العداد</th><th>قطع / عمالة</th></tr>
            </thead>
            <tbody>
              {dossier.work_orders.map((w) => (
                <tr key={w.id}>
                  <td data-label="#">{w.id}</td>
                  <td data-label="الحالة"><StatusPill status={w.status} label={statusLabel[w.status] || w.status} /></td>
                  <td data-label="الورشة">{w.workshop || '—'}</td>
                  <td data-label="الفتح">{w.opened_on || '—'}</td>
                  <td data-label="الإغلاق">{w.closed_on || '—'}</td>
                  <td data-label="العداد">{w.odometer != null ? formatNumber(w.odometer) : '—'}</td>
                  <td data-label="قطع / عمالة">
                    {formatMoney(w.parts_cost || 0)} / {formatMoney(w.labor_cost || 0)}
                    {w.lines.length > 0 ? <p className="muted">{w.lines.map((l) => l.description).join(' · ')}</p> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div className="vd-panel__head" style={{ marginTop: '0.5rem' }}>
        <h2>المخالفات</h2>
        <p>{dossier.totals.violations}</p>
      </div>
      {dossier.violations.length === 0 ? <Empty>لا مخالفات</Empty> : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--compact rpm-table--stack">
            <thead><tr><th>التاريخ</th><th>الموقع</th><th>المبلغ</th><th>العميل</th><th>الحالة</th></tr></thead>
            <tbody>
              {dossier.violations.map((x) => (
                <tr key={x.id}>
                  <td data-label="التاريخ">{shortDate(x.occurred_at)}</td>
                  <td data-label="الموقع">{x.location || '—'}</td>
                  <td data-label="المبلغ">{x.amount != null ? formatMoney(x.amount) : '—'}</td>
                  <td data-label="العميل">{x.customer?.full_name || '—'}</td>
                  <td data-label="الحالة">{x.status || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const expensesPanel = (
    <section className="vd-panel" key="expenses">
      <div className="vd-panel__head">
        <h2>المصروفات</h2>
        <p>الإجمالي {formatMoney(dossier.totals.expenses_amount)}</p>
      </div>
      {dossier.expenses.length === 0 ? <Empty>لا مصروفات مرتبطة</Empty> : (
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
                <div><span>صدر {d.issued_on || '—'} · ينتهي {d.expires_on || '—'}{d.file_path ? ' · مرفق' : ''}</span></div>
              </div>
              <div className="rpm-actions-row vd-no-print">
                <Button size="sm" variant="ghost" onClick={() => setDocEdit({ ...d })}>تعديل</Button>
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
          <TextField label="تاريخ الإصدار" type="date" value={docForm.issued_on} onChange={(e) => setDocForm({ ...docForm, issued_on: e.target.value })} />
          <TextField label="تاريخ الانتهاء" type="date" value={docForm.expires_on} onChange={(e) => setDocForm({ ...docForm, expires_on: e.target.value })} />
          <MediaUpload
            label="ملف المستند"
            value={docForm.file_path || null}
            collection="vehicle_docs"
            purpose="document"
            accept="both"
            attachableType="vehicle"
            attachableId={v.id}
            onChange={(path) => setDocForm({ ...docForm, file_path: path || '' })}
          />
          <Button size="sm" loading={saving} onClick={() => addDocument()}>إضافة مستند</Button>
        </div>
      </div>

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
          label="رفع مرفق للمركبة"
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
              <span>{v.owner.phone || 'بدون هاتف'} · شهري {formatMoney(v.owner_monthly_amount || 0)}</span>
              <span>ربط منذ {v.owner_since || '—'}</span>
            </div>
          </div>
          <Link className="vd-no-print rpm-link-btn" to="/owners">صفحة الملاك</Link>
        </div>
      ) : (
        <Empty>هذه المركبة ملك المركز — لا يوجد مالك خارجي مرتبط.</Empty>
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
    <section className="vd-panel" key="assets">
      <div className="vd-panel__head">
        <h2>الإطارات · البطارية · التوقف · القطع</h2>
        <p>دخل ضائع تقديري {formatMoney(dossier.totals.lost_revenue || 0)}</p>
      </div>

      <div className="vd-panel__head"><h3>الإطارات</h3></div>
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

      <div className="vd-panel__head" style={{ marginBlockStart: '1rem' }}><h3>البطارية</h3></div>
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

      <div className="vd-panel__head" style={{ marginBlockStart: '1rem' }}><h3>أيام التوقف</h3></div>
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

      <div className="vd-panel__head" style={{ marginBlockStart: '1rem' }}><h3>قطع استهلاكية</h3></div>
      {(dossier.consumables || []).length === 0 ? <Empty>لا قوالب مربوطة بهذه المركبة</Empty> : (
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

      <div className="rpm-card__actions" style={{ marginBlockStart: '1rem' }}>
        <Button size="sm" variant="secondary" onClick={() => setAssetSheet('tire')}>تسجيل إطار</Button>
        <Button size="sm" variant="secondary" onClick={() => setAssetSheet('battery')}>تسجيل بطارية</Button>
        <Button size="sm" variant="secondary" onClick={() => setAssetSheet('downtime')}>تسجيل توقف</Button>
      </div>
    </section>
  )

  const panelByTab: Record<TabId, ReactNode> = {
    overview: overviewPanel,
    charts: chartsPanel,
    accessories: accessoriesPanel,
    assets: assetsPanel,
    bookings: bookingsPanel,
    maintenance: maintenancePanel,
    expenses: expensesPanel,
    docs: docsPanel,
    owner: ownerPanel,
  }

  return (
    <div className="vd">
      <div className="vd-print-head">
        <strong>ملف مركبة — RPM</strong>
        <span>{v.brand} {v.model} · {v.plate}</span>
      </div>

      <header className="vd-hero">
        <div className="vd-hero__top">
          <button type="button" className="vd-back vd-no-print" onClick={() => navigate('/vehicles')}>رجوع للأسطول</button>
          <div className="vd-hero__actions vd-no-print">
            <Button size="sm" onClick={() => openNewContract({ vehicleId: v.id })}>إنشاء حجز</Button>
            <Button size="sm" variant="secondary" onClick={() => setWoOpen(true)}>إضافة صيانة</Button>
            <Button size="sm" variant="secondary" onClick={() => setExpOpen(true)}>تسجيل مصروف</Button>
            <Button size="sm" variant="secondary" onClick={() => loadEstimated()}>عداد تقديري</Button>
            <Button size="sm" variant="secondary" onClick={() => { setEditing(true); setEditOpen(true) }}>تعديل</Button>
            <Button size="sm" onClick={() => window.print()}>طباعة</Button>
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

      <nav className="vd-tabs vd-no-print" aria-label="أقسام ملف المركبة">
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
        {overviewPanel}{chartsPanel}{accessoriesPanel}{assetsPanel}{bookingsPanel}{maintenancePanel}{expensesPanel}{docsPanel}{ownerPanel}
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
            <TextField label="بداية التوقف" type="datetime-local" value={downtimeForm.starts_at} onChange={(e) => setDowntimeForm({ ...downtimeForm, starts_at: e.target.value })} />
            <TextField label="نهاية التوقف" type="datetime-local" value={downtimeForm.ends_at} onChange={(e) => setDowntimeForm({ ...downtimeForm, ends_at: e.target.value })} />
          </div>
        ) : null}
      </Sheet>

      <Sheet
        open={editOpen}
        title="تعديل المركبة"
        onClose={() => { setEditOpen(false); setEditing(false) }}
        footer={
          <>
            <Button loading={saving} onClick={() => onUpdate()}>حفظ التعديل</Button>
            <Button variant="secondary" onClick={() => { setEditOpen(false); setEditing(false) }}>إلغاء</Button>
          </>
        }
      >
        {editing ? formFields : null}
      </Sheet>

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
            <TextField label="تاريخ الإصدار" type="date" value={docEdit.issued_on || ''} onChange={(e) => setDocEdit({ ...docEdit, issued_on: e.target.value })} />
            <TextField label="تاريخ الانتهاء" type="date" value={docEdit.expires_on || ''} onChange={(e) => setDocEdit({ ...docEdit, expires_on: e.target.value })} />
            <TextAreaField label="ملاحظات" value={docEdit.notes || ''} onChange={(e) => setDocEdit({ ...docEdit, notes: e.target.value })} />
          </div>
        ) : null}
      </Sheet>
    </div>
  )
}
