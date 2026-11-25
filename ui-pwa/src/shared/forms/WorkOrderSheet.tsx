import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { civilDateOnly, nowLocalDateTimeValue } from '@/shared/lib/dates'
import { formatMoney } from '@/shared/lib/format'
import {
  MAINTENANCE_ITEM_PRESETS,
  normalizeMaintenanceItemLabel,
} from '@/shared/maintenance/maintenanceItems'
import {
  Button, Sheet, SelectField, NumberField, MoneyField, TextField, SwitchField,
  DateField, DateTimeField, useToast, FieldShell,
} from '@/shared/ui'
import './WorkOrderSheet.css'

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Locks the work order to one vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string; odometer?: number }
  /** Prefill funding from a custody employee (petty-cash detail quick action) */
  custodyEmployee?: { id: number; name?: string }
}

type VehicleOption = { id: number; brand: string; model: string; plate: string; odometer?: number }
type Item = { id: number; name: string; code: string; remaining?: number; unit_cost?: number | null }
type EmployeeOption = { id: number; name: string }

type LinePayload = {
  description: string
  part_source: 'stock' | 'purchase'
  inventory_item_id?: number
  quantity: number
  unit_price?: number
  from_petty_cash?: boolean
  petty_cash_employee_id?: number
  petty_cash_amount?: number
}

const emptyForm = {
  vehicle_id: '',
  odometer: '',
  workshop: '',
  inventory_item_id: '',
  quantity: '1',
  unit_price: '',
  opened_at: nowLocalDateTimeValue(),
  funding: 'stock' as 'stock' | 'custody',
  employee_id: '',
  next_service_on: '',
  next_service_odometer: '',
  customItem: '',
}

export function WorkOrderSheet({ open, onClose, onSaved, vehicle, custodyEmployee }: Props) {
  const toast = useToast()
  const { user, can } = useAuth()
  const canManagePetty = can('petty_cash.manage')
  const ownEmployeeId = user?.employee_id ?? null
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [items, setItems] = useState<Item[]>([])
  const [employees, setEmployees] = useState<EmployeeOption[]>([])
  const [vehicleSearching, setVehicleSearching] = useState(false)
  const [inventorySearching, setInventorySearching] = useState(false)
  const [saving, setSaving] = useState(false)
  const [firstUpcoming, setFirstUpcoming] = useState(true)
  const [putsInMaintenance, setPutsInMaintenance] = useState(false)
  const [selectedItems, setSelectedItems] = useState<string[]>([])
  const [extraItems, setExtraItems] = useState<string[]>([])
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const lockedId = vehicle?.id
  const lockedOdometer = vehicle?.odometer
  const lockCustody = Boolean(custodyEmployee?.id)
  const baselineMode = firstUpcoming && !lockCustody
  /** Normal WO (and custody quick-action) must fund from stock or petty cash — baseline stays free. */
  const spendMode = !baselineMode

  const stockItems = useMemo(
    () => items.filter((i) => Number(i.remaining ?? 0) > 0),
    [items],
  )

  const selectedItem = useMemo(
    () => stockItems.find((i) => String(i.id) === form.inventory_item_id) || null,
    [stockItems, form.inventory_item_id],
  )

  const chosenLabels = useMemo(
    () => [...selectedItems, ...extraItems],
    [selectedItems, extraItems],
  )

  const searchVehicles = useCallback(async (q: string) => {
    setVehicleSearching(true)
    try {
      const params = new URLSearchParams({ per_page: '30' })
      if (q) params.set('search', q)
      const r = await api<{ data: VehicleOption[] }>(`/vehicles?${params}`)
      setVehicles(r.data)
    } finally {
      setVehicleSearching(false)
    }
  }, [])

  const searchInventory = useCallback(async (q: string) => {
    setInventorySearching(true)
    try {
      const params = new URLSearchParams({ per_page: '30' })
      if (q) params.set('search', q)
      const r = await api<{ data: Item[] }>(`/inventory?${params}`)
      setItems(Array.isArray(r.data) ? r.data : [])
    } finally {
      setInventorySearching(false)
    }
  }, [])

  useEffect(() => {
    if (!open) return
    const defaultEmployeeId = canManagePetty
      ? (lockCustody && custodyEmployee?.id ? String(custodyEmployee.id) : '')
      : (ownEmployeeId ? String(ownEmployeeId) : '')
    setForm({
      ...emptyForm,
      opened_at: nowLocalDateTimeValue(),
      vehicle_id: lockedId ? String(lockedId) : '',
      odometer: lockedOdometer != null ? String(lockedOdometer) : '',
      funding: lockCustody ? 'custody' : 'stock',
      employee_id: defaultEmployeeId,
    })
    setFieldErrors({})
    setSelectedItems([])
    setExtraItems([])
    setFirstUpcoming(!lockCustody)
    setPutsInMaintenance(false)
    const requests: Promise<unknown>[] = [
      api<{ data: Item[] }>('/inventory?per_page=30')
        .then((r) => setItems(Array.isArray(r.data) ? r.data : []))
        .catch((e) => {
          setItems([])
          toast.error((e as ApiError).message || 'تعذّر تحميل أصناف المخزون.')
        }),
    ]
    if (canManagePetty) {
      requests.push(
        api<{ data: EmployeeOption[] }>('/employees/lookup')
          .then((r) => setEmployees(r.data || []))
          .catch(() => setEmployees([])),
      )
    } else {
      setEmployees([])
    }
    if (!lockedId) {
      requests.push(api<{ data: VehicleOption[] }>('/vehicles?per_page=30').then((r) => setVehicles(r.data)))
    }
    Promise.all(requests).catch((e) => toast.error((e as ApiError).message))
  }, [open, lockedId, lockedOdometer, lockCustody, custodyEmployee?.id, toast, canManagePetty, ownEmployeeId])

  function togglePreset(label: string) {
    setSelectedItems((prev) => (
      prev.includes(label) ? prev.filter((x) => x !== label) : [...prev, label]
    ))
    setFieldErrors((prev) => ({ ...prev, items: '' }))
  }

  function addCustomItem() {
    const label = normalizeMaintenanceItemLabel(form.customItem)
    if (!label) {
      setFieldErrors((prev) => ({ ...prev, customItem: 'اكتب اسم البند أولاً.' }))
      return
    }
    const exists = [...MAINTENANCE_ITEM_PRESETS, ...extraItems, ...selectedItems]
      .some((x) => x === label)
    if (exists) {
      if (!selectedItems.includes(label) && (MAINTENANCE_ITEM_PRESETS as readonly string[]).includes(label)) {
        setSelectedItems((prev) => [...prev, label])
      }
      setForm((prev) => ({ ...prev, customItem: '' }))
      setFieldErrors((prev) => ({ ...prev, customItem: '', items: '' }))
      return
    }
    setExtraItems((prev) => [...prev, label])
    setForm((prev) => ({ ...prev, customItem: '' }))
    setFieldErrors((prev) => ({ ...prev, customItem: '', items: '' }))
  }

  function removeExtra(label: string) {
    setExtraItems((prev) => prev.filter((x) => x !== label))
  }

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!form.vehicle_id) next.vehicle_id = 'اختر العربية.'
    if (!form.odometer) next.odometer = 'أدخل قراءة العداد.'
    if (!form.opened_at) next.opened_at = 'أدخل تاريخ ووقت الصيانة.'
    if (chosenLabels.length < 1) next.items = 'اختر بند صيانة واحداً على الأقل، أو أضف بنداً.'
    if (spendMode) {
      if (!form.funding) next.funding = 'حدّد مصدر الفلوس: مخزون أو عهدة.'
      if (form.funding === 'stock') {
        if (!form.inventory_item_id) next.inventory_item_id = 'اختر صنف المخزون.'
        else if (!selectedItem) next.inventory_item_id = 'الصنف غير متاح أو نفد رصيده.'
      } else if (form.funding === 'custody') {
        if (!canManagePetty && !ownEmployeeId) {
          next.employee_id = 'حسابك غير مرتبط بموظف — إدارة العهدة فقط تخصم عهدة الغير.'
        } else if (!form.employee_id) {
          next.employee_id = canManagePetty ? 'اختر موظف العهدة.' : 'تعذّر تحديد عهدتك.'
        }
        if (!form.unit_price || Number(form.unit_price) < 1) next.unit_price = 'المبلغ لازم يكون أكبر من صفر.'
      }
      if (!form.quantity || Number(form.quantity) < 1) next.quantity = 'الكمية يجب أن تكون ١ فأكثر.'
    }
    const hasDate = Boolean(form.next_service_on)
    const hasOdo = form.next_service_odometer !== ''
    if (!hasDate && !hasOdo) {
      next.next_service_on = 'حدّد موعد أو عداد الصيانة التالية (واحد فقط).'
      next.next_service_odometer = 'حدّد موعد أو عداد الصيانة التالية (واحد فقط).'
    } else if (hasDate && hasOdo) {
      next.next_service_on = 'اختر واحداً فقط: موعد أو عداد.'
      next.next_service_odometer = 'اختر واحداً فقط: موعد أو عداد.'
    }
    setFieldErrors(next)
    return Object.keys(next).length === 0
  }

  function buildLines(): LinePayload[] {
    if (!spendMode) {
      return chosenLabels.map((description) => ({
        description,
        part_source: 'purchase',
        quantity: 1,
        unit_price: 0,
        from_petty_cash: false,
      }))
    }

    const fromStock = form.funding === 'stock'
    const inventoryItemId = Number(form.inventory_item_id)
    const joined = chosenLabels.join(' · ')
    return [{
      description: joined,
      part_source: fromStock ? 'stock' : 'purchase',
      ...(fromStock && Number.isFinite(inventoryItemId) && inventoryItemId > 0
        ? { inventory_item_id: inventoryItemId }
        : {}),
      quantity: Number(form.quantity || 1),
      unit_price: fromStock ? undefined : Number(form.unit_price || 0),
      from_petty_cash: !fromStock,
      petty_cash_employee_id: !fromStock
        ? Number(canManagePetty ? form.employee_id : ownEmployeeId)
        : undefined,
      petty_cash_amount: !fromStock
        ? Number(form.unit_price || 0) * Number(form.quantity || 1)
        : undefined,
    }]
  }

  async function save() {
    if (!validate()) {
      toast.error('راجع الحقول المطلوبة قبل الحفظ.')
      return
    }
    setSaving(true)
    try {
      const notes = baselineMode
        ? 'تسجيل أساس — أول صيانة قادمة'
        : undefined
      await api('/work-orders', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          odometer: Number(form.odometer),
          workshop: form.workshop || undefined,
          labor_cost: 0,
          puts_in_maintenance: baselineMode ? false : putsInMaintenance,
          force_after_resolution: true,
          opened_at: form.opened_at || undefined,
          opened_on: form.opened_at ? civilDateOnly(form.opened_at) : undefined,
          next_service_on: form.next_service_on || undefined,
          next_service_odometer: form.next_service_odometer !== '' ? Number(form.next_service_odometer) : undefined,
          baseline: baselineMode,
          notes,
          lines: buildLines(),
        },
      })
      toast.success(baselineMode ? 'تم تسجيل أول صيانة قادمة.' : 'تم فتح أمر الصيانة.')
      onClose()
      onSaved?.()
    } catch (e) {
      const err = e as ApiError
      const invErr = err.errors?.['lines.0.inventory_item_id']?.[0]
      if (invErr) {
        setFieldErrors((prev) => ({ ...prev, inventory_item_id: invErr.replace(/^lines\.0\.inventory_item_id\s*/, '') || invErr }))
      }
      toast.error(err.message.replace(/^lines\.0\.inventory_item_id\s*/, 'صنف المخزون: ') || err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Sheet
      open={open}
      title={vehicle
        ? (baselineMode ? `أول صيانة قادمة · ${vehicle.label}` : `صيانة · ${vehicle.label}`)
        : (baselineMode ? 'تسجيل أول صيانة قادمة' : 'صيانة جديدة')}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>
            {baselineMode ? 'حفظ الموعد' : 'فتح أمر الصيانة'}
          </Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        {!lockCustody ? (
          <SwitchField
            label="تسجيل أول صيانة قادمة (بدون مبلغ / عهدة)"
            checked={firstUpcoming}
            onChange={(v) => {
              setFirstUpcoming(v)
              if (v) {
                setPutsInMaintenance(false)
                setForm((prev) => ({ ...prev, unit_price: '', inventory_item_id: '' }))
              } else {
                setForm((prev) => ({
                  ...prev,
                  funding: prev.funding || 'stock',
                  unit_price: prev.unit_price === '0' ? '' : prev.unit_price,
                }))
              }
            }}
          />
        ) : null}

        {!vehicle ? (
          <SelectField
            label="العربية"
            required
            error={fieldErrors.vehicle_id}
            options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={form.vehicle_id}
            onRemoteSearch={searchVehicles}
            remoteSearching={vehicleSearching}
            searchPlaceholder="ابحث باللوحة أو الموديل…"
            onChange={(e) => {
              const v = vehicles.find((x) => String(x.id) === e.target.value)
              setForm({ ...form, vehicle_id: e.target.value, odometer: v?.odometer != null ? String(v.odometer) : form.odometer })
              setFieldErrors((prev) => ({ ...prev, vehicle_id: '' }))
            }}
          />
        ) : null}
        <DateTimeField
          label={baselineMode ? 'تاريخ آخر صيانة (نقطة الأساس)' : 'تاريخ ووقت الصيانة'}
          required
          error={fieldErrors.opened_at}
          value={form.opened_at}
          onChange={(e) => {
            setForm({ ...form, opened_at: e.target.value })
            setFieldErrors((prev) => ({ ...prev, opened_at: '' }))
          }}
        />
        <TextField label="الورشة" value={form.workshop} onChange={(e) => setForm({ ...form, workshop: e.target.value })} />
        <NumberField
          label="العداد"
          required
          error={fieldErrors.odometer}
          value={form.odometer}
          onChange={(e) => {
            setForm({ ...form, odometer: e.target.value })
            setFieldErrors((prev) => ({ ...prev, odometer: '' }))
          }}
        />

        <FieldShell label="بنود الصيانة" required error={fieldErrors.items} hint="اختر من القائمة أو أضف بنداً غير موجود">
          <div className="rpm-maint-items" role="group" aria-label="بنود الصيانة">
            {MAINTENANCE_ITEM_PRESETS.map((label) => {
              const active = selectedItems.includes(label)
              return (
                <button
                  key={label}
                  type="button"
                  className={`rpm-maint-item${active ? ' is-active' : ''}`}
                  aria-pressed={active}
                  onClick={() => togglePreset(label)}
                >
                  {label}
                </button>
              )
            })}
            {extraItems.map((label) => (
              <button
                key={`extra-${label}`}
                type="button"
                className="rpm-maint-item is-active is-custom"
                aria-pressed
                onClick={() => removeExtra(label)}
                title="اضغط للإزالة"
              >
                {label} ×
              </button>
            ))}
          </div>
        </FieldShell>

        <div className="rpm-maint-custom-row">
          <TextField
            label="بند إضافي غير موجود"
            value={form.customItem}
            error={fieldErrors.customItem}
            onChange={(e) => {
              setForm({ ...form, customItem: e.target.value })
              setFieldErrors((prev) => ({ ...prev, customItem: '' }))
            }}
            hint="مثال: بطانة فرامل"
          />
          <Button type="button" variant="secondary" onClick={addCustomItem}>إضافة</Button>
        </div>

        {!baselineMode ? (
          <SwitchField
            label="يضع العربية في صيانة"
            checked={putsInMaintenance}
            onChange={setPutsInMaintenance}
          />
        ) : null}

        {spendMode ? (
          <>
            <SelectField
              label="مصدر التمويل / القطع"
              required
              error={fieldErrors.funding}
              hint="إلزامي في أمر الصيانة العادي — مخزون أو عهدة (مش بدون فلوس)"
              value={form.funding}
              onChange={(e) => {
                const funding = e.target.value as 'stock' | 'custody'
                if (funding === 'custody' && !canManagePetty && !ownEmployeeId) {
                  toast.error('حسابك غير مرتبط بموظف — إدارة العهدة فقط تخصم عهدة الغير.')
                  return
                }
                setForm({
                  ...form,
                  funding,
                  inventory_item_id: '',
                  employee_id: funding === 'custody'
                    ? (canManagePetty
                      ? (custodyEmployee?.id ? String(custodyEmployee.id) : '')
                      : String(ownEmployeeId))
                    : '',
                  unit_price: '',
                })
                setFieldErrors((prev) => ({ ...prev, funding: '', inventory_item_id: '', employee_id: '', unit_price: '' }))
              }}
              options={[
                { value: 'stock', label: 'من المخزون (سعر مسجّل تلقائياً)' },
                { value: 'custody', label: 'شراء من عهدة موظف' },
              ]}
            />

            {form.funding === 'stock' ? (
              <SelectField
                label="صنف المخزون"
                required
                error={fieldErrors.inventory_item_id}
                hint={
                  stockItems.length === 0
                    ? 'لا أصناف متاحة برصيد — أضف دفعة مخزون أولاً'
                    : selectedItem?.unit_cost != null
                      ? `السعر من المخزون (FIFO): ${formatMoney(selectedItem.unit_cost)} — بلا إدخال يدوي`
                      : 'يُسحب السعر تلقائياً من أقدم دفعة عند الحفظ'
                }
                options={stockItems.map((i) => ({
                  value: String(i.id),
                  label: `${i.code} · ${i.name} · متبقي ${i.remaining ?? 0}${i.unit_cost != null ? ` · ${formatMoney(i.unit_cost)}` : ''}`,
                }))}
                value={form.inventory_item_id}
                onRemoteSearch={searchInventory}
                remoteSearching={inventorySearching}
                searchPlaceholder="ابحث عن صنف…"
                onChange={(e) => {
                  setForm({ ...form, inventory_item_id: e.target.value })
                  setFieldErrors((prev) => ({ ...prev, inventory_item_id: '' }))
                }}
              />
            ) : (
              <>
                {canManagePetty ? (
                  <SelectField
                    label="موظف العهدة"
                    required
                    error={fieldErrors.employee_id}
                    hint="الإدارة فقط تختار عهدة موظف آخر"
                    options={[
                      { value: '', label: 'اختر…' },
                      ...employees.map((e) => ({ value: String(e.id), label: e.name })),
                    ]}
                    value={form.employee_id}
                    onChange={(e) => {
                      setForm({ ...form, employee_id: e.target.value })
                      setFieldErrors((prev) => ({ ...prev, employee_id: '' }))
                    }}
                  />
                ) : (
                  <TextField
                    label="موظف العهدة"
                    value={custodyEmployee?.name || user?.name || `موظف #${ownEmployeeId}`}
                    disabled
                    onChange={() => undefined}
                    hint="يُخصم من عهدتك فقط — لا يمكن اختيار موظف آخر"
                    error={fieldErrors.employee_id}
                  />
                )}
                <MoneyField
                  label="سعر شراء الوحدة"
                  required
                  error={fieldErrors.unit_price}
                  value={form.unit_price}
                  onChange={(e) => {
                    setForm({ ...form, unit_price: e.target.value })
                    setFieldErrors((prev) => ({ ...prev, unit_price: '' }))
                  }}
                />
              </>
            )}

            <NumberField
              label="الكمية"
              required
              error={fieldErrors.quantity}
              value={form.quantity}
              onChange={(e) => {
                setForm({ ...form, quantity: e.target.value })
                setFieldErrors((prev) => ({ ...prev, quantity: '' }))
              }}
            />
          </>
        ) : baselineMode ? (
          <p className="rpm-maint-free-hint">بدون مبلغ ولا عهدة — البنود تُسجَّل توثيقاً فقط مع موعد/عداد الصيانة التالية.</p>
        ) : null}

        <DateField
          label="موعد الصيانة التالية"
          required={!form.next_service_odometer}
          error={fieldErrors.next_service_on}
          value={form.next_service_on}
          onChange={(e) => {
            setForm({
              ...form,
              next_service_on: e.target.value,
              next_service_odometer: e.target.value ? '' : form.next_service_odometer,
            })
            setFieldErrors((prev) => ({ ...prev, next_service_on: '', next_service_odometer: '' }))
          }}
          hint="للقطع التي تتغيّر بالوقت — إشعار قبل الموعد بيومين · واحد من الاثنين فقط"
        />
        <NumberField
          label="عداد الصيانة التالية"
          required={!form.next_service_on}
          error={fieldErrors.next_service_odometer}
          value={form.next_service_odometer}
          onChange={(e) => {
            setForm({
              ...form,
              next_service_odometer: e.target.value,
              next_service_on: e.target.value !== '' ? '' : form.next_service_on,
            })
            setFieldErrors((prev) => ({ ...prev, next_service_on: '', next_service_odometer: '' }))
          }}
          hint="للقطع التي تتغيّر بالكيلومتر — إشعار قبل ٢٠٠٠ كم · واحد من الاثنين فقط"
        />
      </div>
    </Sheet>
  )
}
