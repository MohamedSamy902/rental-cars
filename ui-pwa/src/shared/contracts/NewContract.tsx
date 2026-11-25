import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, TextField, SelectField, MoneyField, TextAreaField, DateTimeField, DateField, MediaUpload, NumberField, SwitchField, useToast,
} from '@/shared/ui'
import { toApiDateTime, formatMoney, formatDepositAmount, DEFAULT_DEPOSIT_CURRENCY } from '@/shared/lib/format'
import { DEFAULT_NATIONALITY } from '@/shared/lib/nationalities'
import { NationalityField } from '@/shared/forms/NationalityField'
import { NewContractContext, type NewContractOptions } from './newContractContext'

type Customer = { id: number; full_name: string; phone: string }
type Accessory = { id: number; name: string; qty?: number }
type Vehicle = {
  id: number
  brand: string
  model: string
  plate: string
  daily_rate: number
  half_monthly_rate?: number | null
  monthly_rate?: number | null
  default_deposit: number
  purchase_value_estimate?: number | null
  odometer?: number
  accessories?: Accessory[]
}

const DEFAULT_DELIVERY_FEE = 1000
const DEFAULT_DAILY_KM = 200
const DEFAULT_EXCESS_KM_RATE = 5

const HALF_MONTHLY_FACTOR = 15
const MONTHLY_FACTOR = 22
const HALF_MONTH_UNIT_DAYS = 15
const MONTH_UNIT_DAYS = 30

function packRent(days: number, unitDays: number, unitRate: number): number {
  if (days < 1 || unitDays < 1 || unitRate < 1) return 0
  const full = Math.floor(days / unitDays)
  const extra = days % unitDays
  return full * unitRate + Math.round((extra * unitRate) / unitDays)
}

function resolveRentTier(days: number): 'daily' | 'half_monthly' | 'monthly' {
  if (days <= 10) return 'daily'
  if (days <= 20) return 'half_monthly'
  return 'monthly'
}

function estimatePeriodRent(days: number, daily: number, halfMonthly: number, monthly: number): number {
  if (!days || !daily) return 0
  const tier = resolveRentTier(days)
  if (tier === 'half_monthly') {
    return packRent(days, HALF_MONTH_UNIT_DAYS, halfMonthly || Math.round(daily * HALF_MONTHLY_FACTOR))
  }
  if (tier === 'monthly') {
    return packRent(days, MONTH_UNIT_DAYS, monthly || Math.round(daily * MONTHLY_FACTOR))
  }
  return days * daily
}

/** عكس packRent تقريباً: من إيجار المدة → سعر وحدة الشريحة المجمّد. */
function unitRateFromPeriodRent(days: number, tier: 'daily' | 'half_monthly' | 'monthly', periodRent: number): number {
  if (days < 1 || periodRent < 1) return 0
  if (tier === 'daily') return Math.max(1, Math.round(periodRent / days))
  if (tier === 'half_monthly') {
    return Math.max(1, Math.round((periodRent * HALF_MONTH_UNIT_DAYS) / days))
  }
  return Math.max(1, Math.round((periodRent * MONTH_UNIT_DAYS) / days))
}

function tierBasisHint(tier: 'daily' | 'half_monthly' | 'monthly', unitRate: number, days: number): string {
  if (tier === 'daily') {
    return `لمدة ${days} يوم (شريحة يومي ١–١٠) · محسوب من السعر اليومي ${formatMoney(unitRate)}`
  }
  if (tier === 'half_monthly') {
    return `لمدة ${days} يوم (شريحة نص شهري ١١–٢٠) · محسوب من سعر نص الشهر ${formatMoney(unitRate)} · يوم الزيادة = ÷ ${HALF_MONTH_UNIT_DAYS}`
  }
  return `لمدة ${days} يوم (شريحة شهري ٢١+) · محسوب من السعر الشهري ${formatMoney(unitRate)} · يوم الزيادة = ÷ ${MONTH_UNIT_DAYS}`
}

const emptyForm = {
  customer_id: '', vehicle_id: '', starts_at: '', planned_ends_at: '',
  daily_rate: '', half_monthly_rate: '', monthly_rate: '',
  deposit: '', deposit_currency: DEFAULT_DEPOSIT_CURRENCY, odometer: '', notes: '',
  daily_km_allowance: String(DEFAULT_DAILY_KM),
  excess_km_rate: String(DEFAULT_EXCESS_KM_RATE),
  prepaid_excess_km: '',
  include_vehicle_value_obligation: false,
  vehicle_value_text: '',
  has_delivery_service: false,
  delivery_service_fee: String(DEFAULT_DELIVERY_FEE),
  delivery_service_notes: '',
  payment_amount: '', payment_method: 'cash', payment_notes: '', balance_due_on: '',
}

function suggestVehicleValueText(estimate?: number | null): string {
  if (estimate == null || Number(estimate) <= 0) return ''
  return String(Math.round(Number(estimate)))
}

const emptyCustomer = {
  full_name: '',
  phone: '',
  phone_alt: '',
  nationality: DEFAULT_NATIONALITY,
  national_id: '',
  national_id_issued_from: '',
  national_id_issued_on: '',
  address: '',
  birth_date: '1990-01-01',
  license_number: '',
  license_issued_from: '',
  license_expires_on: '2030-01-01',
  notes: '',
  national_id_image_path: '',
  national_id_back_path: '',
  license_image_path: '',
  license_back_path: '',
}

export function NewContractProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [createdVersion, setCreatedVersion] = useState(0)
  const [customers, setCustomers] = useState<Customer[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [customerSearching, setCustomerSearching] = useState(false)
  const [vehicleSearching, setVehicleSearching] = useState(false)
  const [inlineCustomer, setInlineCustomer] = useState(false)
  const [newCustomer, setNewCustomer] = useState(emptyCustomer)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [accessoryIds, setAccessoryIds] = useState<number[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [lockedVehicleId, setLockedVehicleId] = useState<number | undefined>()
  const [periodRentDraft, setPeriodRentDraft] = useState<string | null>(null)

  const loadVehicles = useCallback(async (starts?: string, ends?: string, preferredId?: number) => {
    const params = new URLSearchParams()
    if (starts) params.set('from', toApiDateTime(starts))
    if (ends) params.set('to', toApiDateTime(ends))
    const qs = params.toString()
    const path = qs ? `/vehicles/recommend?${qs}` : '/vehicles/recommend'
    try {
      const v = await api<{ data: Vehicle[] }>(path)
      setVehicles(v.data)
      if (preferredId && !v.data.some((x) => x.id === preferredId)) {
        const fallback = await api<{ data: Vehicle[] }>(`/vehicles?per_page=30&search=`).catch(() => ({ data: [] as Vehicle[] }))
        const found = fallback.data.find((x) => x.id === preferredId)
        if (found) setVehicles((prev) => [{ ...found }, ...prev.filter((p) => p.id !== found.id)])
      }
      return v.data
    } catch {
      const fallback = await api<{ data: Vehicle[] }>('/vehicles?per_page=30')
      setVehicles(fallback.data)
      return fallback.data
    }
  }, [])

  const searchCustomers = useCallback(async (q: string) => {
    setCustomerSearching(true)
    try {
      const params = new URLSearchParams({ per_page: '30' })
      if (q) params.set('search', q)
      const c = await api<{ data: Customer[] }>(`/customers?${params}`)
      setCustomers(c.data)
    } finally {
      setCustomerSearching(false)
    }
  }, [])

  const searchVehicles = useCallback(async (q: string) => {
    setVehicleSearching(true)
    try {
      const params = new URLSearchParams({ per_page: '30' })
      if (q) params.set('search', q)
      if (form.starts_at) params.set('from', toApiDateTime(form.starts_at))
      if (form.planned_ends_at) params.set('to', toApiDateTime(form.planned_ends_at))
      const v = await api<{ data: Vehicle[] }>(`/vehicles?${params}`)
      setVehicles(v.data)
    } finally {
      setVehicleSearching(false)
    }
  }, [form.starts_at, form.planned_ends_at])

  const applyVehicleDefaults = useCallback((v: Vehicle | undefined, prev: typeof emptyForm) => {
    if (!v) return prev
    const daily = Number(v.daily_rate || 0)
    const half = v.half_monthly_rate != null && Number(v.half_monthly_rate) > 0
      ? Number(v.half_monthly_rate)
      : Math.round(daily * HALF_MONTHLY_FACTOR)
    const monthly = v.monthly_rate != null && Number(v.monthly_rate) > 0
      ? Number(v.monthly_rate)
      : Math.round(daily * MONTHLY_FACTOR)
    const suggested = suggestVehicleValueText(v.purchase_value_estimate)
    return {
      ...prev,
      vehicle_id: String(v.id),
      daily_rate: String(v.daily_rate),
      half_monthly_rate: String(half),
      monthly_rate: String(monthly),
      deposit: String(v.default_deposit),
      odometer: v.odometer != null ? String(v.odometer) : prev.odometer,
      vehicle_value_text: prev.include_vehicle_value_obligation
        ? (prev.vehicle_value_text.trim() || suggested)
        : prev.vehicle_value_text,
    }
  }, [])

  const openNewContract = useCallback((opts?: NewContractOptions) => {
    setOpen(true)
    setInlineCustomer(false)
    setErrors({})
    setNewCustomer(emptyCustomer)
    setAccessoryIds([])
    setPeriodRentDraft(null)
    setLockedVehicleId(opts?.vehicleId)
    setForm({
      ...emptyForm,
      vehicle_id: opts?.vehicleId ? String(opts.vehicleId) : '',
      customer_id: opts?.customerId ? String(opts.customerId) : '',
    })
    void Promise.all([
      api<{ data: Customer[] }>('/customers?per_page=30'),
      loadVehicles(undefined, undefined, opts?.vehicleId),
      api<{ data: { operational?: Record<string, unknown> } }>('/settings/tenant')
        .catch(() => ({ data: { operational: {} as Record<string, unknown> } })),
    ])
      .then(([c, v, tenant]) => {
        setCustomers(c.data)
        const ops = tenant.data?.operational || {}
        const kmDefault = Number(
          ops.default_daily_km_allowance ?? ops.default_daily_km ?? DEFAULT_DAILY_KM,
        )
        const excessDefault = Number(
          ops.default_excess_km_rate ?? ops.excess_km_rate ?? DEFAULT_EXCESS_KM_RATE,
        )
        setForm((f) => {
          const next = {
            ...f,
            customer_id: opts?.customerId ? String(opts.customerId) : f.customer_id,
            daily_km_allowance: String(Number.isFinite(kmDefault) ? kmDefault : DEFAULT_DAILY_KM),
            excess_km_rate: String(Number.isFinite(excessDefault) ? excessDefault : DEFAULT_EXCESS_KM_RATE),
          }
          if (!opts?.vehicleId) return next
          const picked = v.find((x) => x.id === opts.vehicleId)
          if (!picked) return next
          setAccessoryIds((picked.accessories || []).map((a) => a.id))
          return applyVehicleDefaults(picked, next)
        })
      })
      .catch((e) => toast.error((e as ApiError).message))
  }, [applyVehicleDefaults, loadVehicles, toast])

  useEffect(() => {
    if (!open) return
    if (!form.starts_at || !form.planned_ends_at) return
    const handle = window.setTimeout(() => {
      void loadVehicles(form.starts_at, form.planned_ends_at, lockedVehicleId).then((list) => {
        if (form.vehicle_id && !list.some((v) => String(v.id) === form.vehicle_id) && Number(form.vehicle_id) !== lockedVehicleId) {
          setForm((f) => ({ ...f, vehicle_id: '', daily_rate: '', deposit: '', deposit_currency: DEFAULT_DEPOSIT_CURRENCY, odometer: '' }))
          setAccessoryIds([])
          toast.info('العربية المختارة غير متاحة في هذه الفترة — اختر عربية أخرى.')
        }
      })
    }, 350)
    return () => window.clearTimeout(handle)
  }, [open, form.starts_at, form.planned_ends_at, form.vehicle_id, loadVehicles, lockedVehicleId, toast])

  function close() {
    setOpen(false)
  }

  async function addInlineCustomer() {
    try {
      const res = await api<{ data: Customer; message: string }>('/customers', {
        method: 'POST',
        json: {
          full_name: newCustomer.full_name,
          phone: newCustomer.phone,
          phone_alt: newCustomer.phone_alt || null,
          nationality: newCustomer.nationality || DEFAULT_NATIONALITY,
          national_id: newCustomer.national_id || null,
          birth_date: newCustomer.birth_date,
          license_number: newCustomer.license_number,
          license_expires_on: newCustomer.license_expires_on,
          notes: newCustomer.notes || null,
          national_id_image_path: newCustomer.national_id_image_path || null,
          national_id_back_path: newCustomer.national_id_back_path || null,
          license_image_path: newCustomer.license_image_path || null,
          license_back_path: newCustomer.license_back_path || null,
        },
      })
      setCustomers((prev) => [res.data, ...prev])
      setForm((f) => ({ ...f, customer_id: String(res.data.id) }))
      setInlineCustomer(false)
      setNewCustomer(emptyCustomer)
      toast.success('تم تسجيل العميل وربطه بالعقد.')
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function onSave() {
    setErrors({})
    const paidRaw = form.payment_amount.trim()
    const paid = paidRaw !== '' ? Number(paidRaw) : NaN
    const rate = Number(form.daily_rate || 0)
    const halfMonthly = Number(form.half_monthly_rate || 0)
    const monthly = Number(form.monthly_rate || 0)
    let days = 0
    if (form.starts_at && form.planned_ends_at) {
      const a = new Date(form.starts_at)
      const b = new Date(form.planned_ends_at)
      if (!Number.isNaN(a.getTime()) && !Number.isNaN(b.getTime()) && b > a) {
        // مطابقة عدّ الأيام في الباكند: أيام كاملة (floor) بلا ceil
        days = Math.max(1, Math.floor((b.getTime() - a.getTime()) / 86400000))
      }
    }
    const excessRate = Number(form.excess_km_rate || 0)
    const prepaidKm = Math.max(0, Number(form.prepaid_excess_km || 0))
    const prepaidAmount = prepaidKm * (Number.isFinite(excessRate) ? excessRate : 0)
    const deliveryNow = form.has_delivery_service ? Math.max(0, Number(form.delivery_service_fee || 0)) : 0
    const rentNow = days && rate ? estimatePeriodRent(days, rate, halfMonthly, monthly) : 0
    const total = rentNow ? rentNow + deliveryNow + prepaidAmount : 0
    if (!Number.isFinite(paid) || paid < 1) {
      setErrors({ payment_amount: 'دفعة عند الإنشاء مطلوبة وأكبر من صفر' })
      toast.error('لا تأجير بلا دفع — أدخل دفعة عند الإنشاء')
      return
    }
    if (paid < 0) {
      setErrors({ payment_amount: 'لا يجوز مبلغ سالب' })
      toast.error('لا يجوز مبلغ سالب')
      return
    }
    if (total > 0 && paid > total) {
      setErrors({ payment_amount: `لا يجوز أن تتجاوز الدفعة إجمالي العقد التقديري (${total} ج.م)` })
      toast.error('الدفعة أكبر من إجمالي العقد التقديري')
      return
    }
    const left = Math.max(0, total - paid)
    if (left > 0 && !form.balance_due_on) {
      setErrors({ balance_due_on: 'حدد تاريخ تحصيل باقي المبلغ' })
      toast.error('حدد تاريخ تحصيل باقي المبلغ')
      return
    }
    if (form.include_vehicle_value_obligation && !form.vehicle_value_text.trim()) {
      setErrors({ vehicle_value_text: 'اكتب نص ثمن العربية عند تفعيل الالتزام' })
      toast.error('اكتب نص ثمن العربية')
      return
    }

    setSaving(true)
    try {
      await api('/contracts', {
        method: 'POST',
        json: {
          customer_id: Number(form.customer_id),
          vehicle_id: Number(form.vehicle_id),
          starts_at: toApiDateTime(form.starts_at),
          planned_ends_at: toApiDateTime(form.planned_ends_at),
          daily_rate: form.daily_rate ? Number(form.daily_rate) : undefined,
          half_monthly_rate: form.half_monthly_rate ? Number(form.half_monthly_rate) : undefined,
          monthly_rate: form.monthly_rate ? Number(form.monthly_rate) : undefined,
          deposit: form.deposit ? Number(form.deposit) : undefined,
          deposit_currency: form.deposit_currency?.trim() || DEFAULT_DEPOSIT_CURRENCY,
          daily_km_allowance: form.daily_km_allowance !== '' ? Number(form.daily_km_allowance) : undefined,
          excess_km_rate: form.excess_km_rate !== '' ? Number(form.excess_km_rate) : undefined,
          prepaid_excess_km: form.prepaid_excess_km !== '' ? Number(form.prepaid_excess_km) : 0,
          include_vehicle_value_obligation: form.include_vehicle_value_obligation,
          vehicle_value_text: form.include_vehicle_value_obligation
            ? form.vehicle_value_text.trim()
            : undefined,
          odometer: form.odometer !== '' ? Number(form.odometer) : undefined,
          accessory_ids: accessoryIds,
          has_delivery_service: form.has_delivery_service,
          delivery_service_fee: form.has_delivery_service
            ? Number(form.delivery_service_fee || 0)
            : 0,
          delivery_service_notes: form.has_delivery_service
            ? (form.delivery_service_notes || undefined)
            : undefined,
          payment_amount: paid,
          payment_method: form.payment_method || 'cash',
          payment_notes: form.payment_notes || undefined,
          balance_due_on: form.balance_due_on || undefined,
          notes: form.notes || undefined,
        },
      })
      toast.success('تم إنشاء العقد.')
      setForm(emptyForm)
      setAccessoryIds([])
      setOpen(false)
      setCreatedVersion((v) => v + 1)
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  const value = useMemo(() => ({ openNewContract, createdVersion }), [openNewContract, createdVersion])
  const selected = vehicles.find((v) => String(v.id) === form.vehicle_id)
  const availableAccessories = selected?.accessories || []

  const estimatedDays = useMemo(() => {
    if (!form.starts_at || !form.planned_ends_at) return 0
    const a = new Date(form.starts_at)
    const b = new Date(form.planned_ends_at)
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime()) || b <= a) return 0
    const ms = b.getTime() - a.getTime()
    // مطابقة ن٫٢ / ContractPricingService::countRentalDays — أيام كاملة بلا ceil
    return Math.max(1, Math.floor(ms / 86400000))
  }, [form.starts_at, form.planned_ends_at])

  const rentTier = useMemo(() => resolveRentTier(estimatedDays), [estimatedDays])
  const rentEstimate = useMemo(() => {
    const rate = Number(form.daily_rate || 0)
    if (!estimatedDays || !rate) return 0
    return estimatePeriodRent(
      estimatedDays,
      rate,
      Number(form.half_monthly_rate || 0),
      Number(form.monthly_rate || 0),
    )
  }, [estimatedDays, form.daily_rate, form.half_monthly_rate, form.monthly_rate])

  const tierUnitRate = useMemo(() => {
    if (rentTier === 'half_monthly') {
      const half = Number(form.half_monthly_rate || 0)
      const daily = Number(form.daily_rate || 0)
      return half > 0 ? half : Math.round(daily * HALF_MONTHLY_FACTOR)
    }
    if (rentTier === 'monthly') {
      const monthly = Number(form.monthly_rate || 0)
      const daily = Number(form.daily_rate || 0)
      return monthly > 0 ? monthly : Math.round(daily * MONTHLY_FACTOR)
    }
    return Number(form.daily_rate || 0)
  }, [rentTier, form.daily_rate, form.half_monthly_rate, form.monthly_rate])

  // حقل «إيجار المدة» يعرض المحسوب؛ مسودة أثناء الكتابة حتى لا تقفز القيمة مع كل رقم.
  useEffect(() => {
    setPeriodRentDraft(null)
  }, [estimatedDays, form.vehicle_id, rentTier])

  const periodRentFieldValue = periodRentDraft ?? (rentEstimate > 0 ? String(rentEstimate) : '')
  const periodRentFieldError =
    rentTier === 'daily'
      ? errors.daily_rate
      : rentTier === 'half_monthly'
        ? errors.half_monthly_rate
        : errors.monthly_rate

  function applyPeriodRentEdit(raw: string) {
    setPeriodRentDraft(raw)
    const period = Number(raw)
    if (!Number.isFinite(period) || period < 0 || estimatedDays < 1) return
    const unit = unitRateFromPeriodRent(estimatedDays, rentTier, period)
    if (unit < 1 && period > 0) return
    if (rentTier === 'daily') {
      setForm({
        ...form,
        daily_rate: String(unit),
        half_monthly_rate: unit > 0 ? String(Math.round(unit * HALF_MONTHLY_FACTOR)) : form.half_monthly_rate,
        monthly_rate: unit > 0 ? String(Math.round(unit * MONTHLY_FACTOR)) : form.monthly_rate,
      })
      return
    }
    if (rentTier === 'half_monthly') {
      setForm({ ...form, half_monthly_rate: String(unit) })
      return
    }
    setForm({ ...form, monthly_rate: String(unit) })
  }

  const prepaidKmEstimate = Math.max(0, Number(form.prepaid_excess_km || 0))
  const prepaidAmountEstimate = prepaidKmEstimate * Math.max(0, Number(form.excess_km_rate || 0))
  const deliveryFee = form.has_delivery_service ? Math.max(0, Number(form.delivery_service_fee || 0)) : 0
  const depositEstimate = Math.max(0, Number(form.deposit || 0))
  const estimatedTotal = rentEstimate + deliveryFee + prepaidAmountEstimate
  const dueWithDepositLabel = rentEstimate + deliveryFee + prepaidAmountEstimate + depositEstimate

  const paidNow = Number(form.payment_amount || 0)
  const remainingBalance = Math.max(0, estimatedTotal - paidNow)

  return (
    <NewContractContext.Provider value={value}>
      {children}
      <Sheet
        open={open}
        title="عقد جديد"
        onClose={close}
        footer={
          <>
            <Button loading={saving} onClick={() => void onSave()}>حفظ</Button>
            <Button variant="secondary" onClick={close}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <div className="rpm-inline-create">
            <SelectField
              label="العميل"
              required
              options={customers.map((c) => ({ value: String(c.id), label: `${c.full_name} · ${c.phone}` }))}
              value={form.customer_id}
              error={errors.customer_id}
              onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
              onRemoteSearch={searchCustomers}
              remoteSearching={customerSearching}
              searchPlaceholder="ابحث بالاسم أو الهاتف…"
            />
            <Button type="button" variant="secondary" size="sm" onClick={() => setInlineCustomer((v) => !v)}>
              + تسجيل عميل جديد
            </Button>
          </div>

          {inlineCustomer ? (
            <div className="rpm-inline-box">
              <p className="rpm-inline-box__title">تسجيل عميل كامل</p>
              <TextField label="الاسم الكامل" required value={newCustomer.full_name} onChange={(e) => setNewCustomer({ ...newCustomer, full_name: e.target.value })} />
              <TextField label="الهاتف" required value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
              <TextField label="هاتف بديل" value={newCustomer.phone_alt} onChange={(e) => setNewCustomer({ ...newCustomer, phone_alt: e.target.value })} />
              <NationalityField
                value={newCustomer.nationality}
                onChange={(nationality) => setNewCustomer({ ...newCustomer, nationality })}
              />
              <TextField label="الرقم القومي / رقم الهوية" value={newCustomer.national_id} onChange={(e) => setNewCustomer({ ...newCustomer, national_id: e.target.value })} />
              <TextField label="صادرة من (البطاقة)" value={newCustomer.national_id_issued_from} onChange={(e) => setNewCustomer({ ...newCustomer, national_id_issued_from: e.target.value })} />
              <TextField label="تاريخ صدور البطاقة" type="month" value={newCustomer.national_id_issued_on} onChange={(e) => setNewCustomer({ ...newCustomer, national_id_issued_on: e.target.value })} />
              <TextField label="محل الإقامة" value={newCustomer.address} onChange={(e) => setNewCustomer({ ...newCustomer, address: e.target.value })} />
              <TextField label="تاريخ الميلاد" required type="date" value={newCustomer.birth_date} onChange={(e) => setNewCustomer({ ...newCustomer, birth_date: e.target.value })} />
              <TextField label="رقم الرخصة" required value={newCustomer.license_number} onChange={(e) => setNewCustomer({ ...newCustomer, license_number: e.target.value })} />
              <TextField label="صادرة من (الرخصة)" value={newCustomer.license_issued_from} onChange={(e) => setNewCustomer({ ...newCustomer, license_issued_from: e.target.value })} />
              <TextField label="انتهاء الرخصة" required type="date" value={newCustomer.license_expires_on} onChange={(e) => setNewCustomer({ ...newCustomer, license_expires_on: e.target.value })} />
              <TextAreaField label="ملاحظات" value={newCustomer.notes} onChange={(e) => setNewCustomer({ ...newCustomer, notes: e.target.value })} />
              <MediaUpload
                label="صورة البطاقة — وش"
                value={newCustomer.national_id_image_path || null}
                collection="customers_id"
                purpose="national_id"
                accept="image"
                onChange={(path) => setNewCustomer({ ...newCustomer, national_id_image_path: path || '' })}
              />
              <MediaUpload
                label="صورة البطاقة — ضهر"
                value={newCustomer.national_id_back_path || null}
                collection="customers_id"
                purpose="national_id_back"
                accept="image"
                onChange={(path) => setNewCustomer({ ...newCustomer, national_id_back_path: path || '' })}
              />
              <MediaUpload
                label="صورة الرخصة — وش"
                value={newCustomer.license_image_path || null}
                collection="customers_id"
                purpose="license"
                accept="image"
                onChange={(path) => setNewCustomer({ ...newCustomer, license_image_path: path || '' })}
              />
              <MediaUpload
                label="صورة الرخصة — ضهر"
                value={newCustomer.license_back_path || null}
                collection="customers_id"
                purpose="license_back"
                accept="image"
                onChange={(path) => setNewCustomer({ ...newCustomer, license_back_path: path || '' })}
              />
              <Button type="button" size="sm" onClick={() => void addInlineCustomer()}>حفظ واختيار</Button>
            </div>
          ) : null}

          <DateTimeField
            label="بداية الحجز"
            required
            value={form.starts_at}
            error={errors.starts_at}
            onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
          />
          <DateTimeField
            label="نهاية الحجز المخططة"
            required
            value={form.planned_ends_at}
            error={errors.planned_ends_at}
            onChange={(e) => setForm({ ...form, planned_ends_at: e.target.value })}
          />

          <SelectField
            label="العربية المتاحة في الفترة"
            required
            hint={form.starts_at && form.planned_ends_at ? 'تُستبعد العربيات المحجوزة أو المؤجرة في نفس الفترة' : 'حدد فترة الحجز أولاً لتصفية المتاح'}
            options={[
              { value: '', label: vehicles.length ? 'اختر عربية…' : 'لا عربيات متاحة في هذه الفترة' },
              ...vehicles.map((v) => ({
                value: String(v.id),
                label: `${v.brand} ${v.model} · ${v.plate}`,
              })),
            ]}
            value={form.vehicle_id}
            error={errors.vehicle_id}
            onRemoteSearch={searchVehicles}
            remoteSearching={vehicleSearching}
            searchPlaceholder="ابحث باللوحة أو الموديل…"
            onChange={(e) => {
              const id = e.target.value
              const v = vehicles.find((x) => String(x.id) === id)
              setForm(applyVehicleDefaults(v, { ...form, vehicle_id: id }))
              setAccessoryIds((v?.accessories || []).map((a) => a.id))
            }}
          />

          {estimatedDays < 1 ? (
            <p className="muted" style={{ gridColumn: '1 / -1' }}>
              حدد بداية ونهاية الحجز أولاً لعرض إيجار المدة حسب شريحة التسعير.
            </p>
          ) : (
            <MoneyField
              label="إيجار المدة"
              required
              value={periodRentFieldValue}
              error={periodRentFieldError}
              onChange={(e) => applyPeriodRentEdit(e.target.value)}
              hint={tierBasisHint(rentTier, tierUnitRate, estimatedDays)}
            />
          )}
          <MoneyField
            label="التأمين النقدي المتفق"
            value={form.deposit}
            unit={form.deposit_currency?.trim() || DEFAULT_DEPOSIT_CURRENCY}
            onChange={(e) => setForm({ ...form, deposit: e.target.value })}
          />
          <TextField
            label="عملة التأمين"
            required
            value={form.deposit_currency}
            onChange={(e) => setForm({ ...form, deposit_currency: e.target.value })}
            hint="اكتب العملة كما هي — جنيه مصري · دولار · ريال · يورو …"
          />
          <NumberField
            label="بدل الكيلومتر اليومي"
            required
            value={form.daily_km_allowance}
            error={errors.daily_km_allowance}
            onChange={(e) => setForm({ ...form, daily_km_allowance: e.target.value })}
            hint="كم مسموح يومياً — يُجمَّد مع العقد"
          />
          <MoneyField
            label="سعر الكيلومتر الزائد"
            required
            value={form.excess_km_rate}
            error={errors.excess_km_rate}
            onChange={(e) => setForm({ ...form, excess_km_rate: e.target.value })}
            hint="جنيه لكل كم زيادة عن البدل"
          />
          <NumberField
            label="كيلومترات زيادة مدفوعة مقدماً"
            value={form.prepaid_excess_km}
            error={errors.prepaid_excess_km}
            onChange={(e) => setForm({ ...form, prepaid_excess_km: e.target.value })}
            hint={
              prepaidAmountEstimate > 0
                ? `يُحسب الآن: ${formatMoney(prepaidAmountEstimate)} · غير المستخدم لا يُرد`
                : 'اختياري — باقة كم زيادة تُدفع مع الاتفاق؛ الزائد عنها يُحسب عند الاستلام'
            }
          />
          <NumberField
            label="العداد الحالي"
            value={form.odometer}
            error={errors.odometer}
            onChange={(e) => setForm({ ...form, odometer: e.target.value })}
            hint="يُحدَّث عداد العربية عند الحفظ"
          />

          <div style={{ gridColumn: '1 / -1' }}>
            <SwitchField
              label="ملزم بثمن العربية في العقد والإقرار"
              checked={form.include_vehicle_value_obligation}
              onChange={(checked) => {
                const selected = vehicles.find((v) => String(v.id) === form.vehicle_id)
                const suggested = suggestVehicleValueText(selected?.purchase_value_estimate)
                setForm({
                  ...form,
                  include_vehicle_value_obligation: checked,
                  vehicle_value_text: checked
                    ? (form.vehicle_value_text.trim() || suggested)
                    : form.vehicle_value_text,
                })
              }}
            />
          </div>
          {form.include_vehicle_value_obligation ? (
            <div style={{ gridColumn: '1 / -1' }}>
              <TextAreaField
                label="نص ثمن العربية"
                required
                value={form.vehicle_value_text}
                error={errors.vehicle_value_text}
                onChange={(e) => setForm({ ...form, vehicle_value_text: e.target.value })}
                hint="نص حر (أرقام وأحرف) يُطبع في البند السادس والإقرار — مثال: 50% من قيمة السيارة وهو مبلغ وقدره 500.000 فقط خمسمائة ألف جنيه"
                placeholder="مثال: قيمة السيارة وهو مبلغ وقدره 1000.000 فقط مليون جنيه"
              />
            </div>
          ) : null}

          <div style={{ gridColumn: '1 / -1' }}>
            <SwitchField
              label="خدمة تسليم واستلام"
              checked={form.has_delivery_service}
              onChange={(checked) => setForm({
                ...form,
                has_delivery_service: checked,
                delivery_service_fee: checked
                  ? (form.delivery_service_fee || String(DEFAULT_DELIVERY_FEE))
                  : form.delivery_service_fee,
              })}
            />
          </div>
          {form.has_delivery_service ? (
            <>
              <MoneyField
                label="مبلغ الخدمة (ج.م)"
                required
                value={form.delivery_service_fee}
                error={errors.delivery_service_fee}
                onChange={(e) => setForm({ ...form, delivery_service_fee: e.target.value })}
                hint={`الافتراضي ${formatMoney(DEFAULT_DELIVERY_FEE)}`}
              />
              <TextField
                label="ملاحظات التسليم/المكان"
                value={form.delivery_service_notes}
                error={errors.delivery_service_notes}
                placeholder="مثال: تسليم مطار القاهرة، استلام من الشيخ زايد..."
                onChange={(e) => setForm({ ...form, delivery_service_notes: e.target.value })}
              />
            </>
          ) : null}

          {estimatedDays > 0 && rentEstimate > 0 ? (
            <div className="rpm-inline-box" style={{ margin: 0, gridColumn: '1 / -1' }}>
              <p className="rpm-inline-box__title">ملخص المستحق</p>
              <p style={{ margin: 0 }}>
                قيمة الإيجار {formatMoney(rentEstimate)}
                {prepaidAmountEstimate > 0 ? ` + كم مدفوع مقدماً ${formatMoney(prepaidAmountEstimate)}` : ''}
                {deliveryFee > 0 ? ` + خدمة تسليم واستلام ${formatMoney(deliveryFee)}` : ''}
                {depositEstimate > 0 ? ` + التأمين ${formatDepositAmount(depositEstimate, form.deposit_currency)}` : ''}
                {' = '}
                <strong>{formatMoney(dueWithDepositLabel)}</strong>
              </p>
              <p className="muted" style={{ marginBlockStart: '0.35rem' }}>
                إجمالي بنود العقد (بدون التأمين) {formatMoney(estimatedTotal)}
                {paidNow > 0 ? ` · مدفوع الآن ${formatMoney(paidNow)} · المتبقي ${formatMoney(remainingBalance)}` : ' · دفعة عند الإنشاء مطلوبة'}
              </p>
            </div>
          ) : null}

          <MoneyField
            label="دفعة عند الإنشاء"
            required
            value={form.payment_amount}
            error={errors.payment_amount}
            onChange={(e) => setForm({ ...form, payment_amount: e.target.value })}
            hint="مطلوبة · أكبر من صفر · لا تتجاوز إجمالي العقد التقديري (بدون التأمين)"
          />
          <SelectField
            label="طريقة الدفعة"
            value={form.payment_method}
            onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
            options={[
              { value: 'cash', label: 'نقدي' },
              { value: 'card', label: 'بطاقة' },
              { value: 'transfer', label: 'تحويل' },
            ]}
          />
          {remainingBalance > 0 && paidNow > 0 ? (
            <DateField
              label="تاريخ تحصيل باقي المبلغ"
              required
              value={form.balance_due_on}
              error={errors.balance_due_on}
              onChange={(e) => setForm({ ...form, balance_due_on: e.target.value })}
              hint="يصل إشعار قبل الموعد بيومين ويتكرر حتى استلام الباقي بالكامل"
            />
          ) : null}
          <TextField
            label="ملاحظة الدفعة"
            value={form.payment_notes}
            onChange={(e) => setForm({ ...form, payment_notes: e.target.value })}
            hint="اختياري — نص حر على إيصال الدفعة"
          />

          {availableAccessories.length > 0 ? (
            <fieldset className="rpm-inline-box" style={{ margin: 0 }}>
              <legend className="rpm-inline-box__title">مستلزمات العربية</legend>
              <div className="rpm-form-grid" style={{ gap: '0.5rem' }}>
                {availableAccessories.map((a) => {
                  const checked = accessoryIds.includes(a.id)
                  return (
                    <label key={a.id} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => {
                          setAccessoryIds((prev) =>
                            checked ? prev.filter((id) => id !== a.id) : [...prev, a.id],
                          )
                        }}
                      />
                      <span>{a.name}{a.qty ? ` × ${a.qty}` : ''}</span>
                    </label>
                  )
                })}
              </div>
            </fieldset>
          ) : null}

          <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} hint="نص حر فقط — لا ينشئ قواعد مالية" />
        </div>
      </Sheet>
    </NewContractContext.Provider>
  )
}
