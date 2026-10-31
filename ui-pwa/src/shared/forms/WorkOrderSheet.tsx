import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  Button, Sheet, SelectField, NumberField, MoneyField, TextField, SwitchField,
  DateField, DateTimeField, useToast,
} from '@/shared/ui'

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Locks the work order to one vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string; odometer?: number }
}

type VehicleOption = { id: number; brand: string; model: string; plate: string; odometer?: number }
type Item = { id: number; name: string; code: string; remaining?: number; unit_cost?: number | null }
type EmployeeOption = { id: number; name: string }

function nowLocal(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const emptyForm = {
  vehicle_id: '',
  odometer: '',
  workshop: '',
  labor_cost: '0',
  description: 'صيانة',
  inventory_item_id: '',
  quantity: '1',
  unit_price: '0',
  opened_at: nowLocal(),
  funding: 'stock' as 'stock' | 'custody',
  employee_id: '',
  next_service_on: '',
  next_service_odometer: '',
}

export function WorkOrderSheet({ open, onClose, onSaved, vehicle }: Props) {
  const toast = useToast()
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [items, setItems] = useState<Item[]>([])
  const [employees, setEmployees] = useState<EmployeeOption[]>([])
  const [saving, setSaving] = useState(false)
  const [putsInMaintenance, setPutsInMaintenance] = useState(true)
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const lockedId = vehicle?.id
  const lockedOdometer = vehicle?.odometer

  const stockItems = useMemo(
    () => items.filter((i) => (i.remaining ?? 0) > 0),
    [items],
  )

  const selectedItem = useMemo(
    () => stockItems.find((i) => String(i.id) === form.inventory_item_id) || null,
    [stockItems, form.inventory_item_id],
  )

  useEffect(() => {
    if (!open) return
    setForm({
      ...emptyForm,
      opened_at: nowLocal(),
      vehicle_id: lockedId ? String(lockedId) : '',
      odometer: lockedOdometer != null ? String(lockedOdometer) : '',
    })
    setFieldErrors({})
    setPutsInMaintenance(true)
    const requests: Promise<unknown>[] = [
      api<{ data: Item[] }>('/inventory').then((r) => setItems(r.data)).catch(() => setItems([])),
      api<{ data: EmployeeOption[] }>('/employees/lookup').then((r) => setEmployees(r.data || [])).catch(() => setEmployees([])),
    ]
    if (!lockedId) {
      requests.push(api<{ data: VehicleOption[] }>('/vehicles?per_page=100').then((r) => setVehicles(r.data)))
    }
    Promise.all(requests).catch((e) => toast.error((e as ApiError).message))
  }, [open, lockedId, lockedOdometer, toast])

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!form.vehicle_id) next.vehicle_id = 'اختر السيارة.'
    if (!form.odometer) next.odometer = 'أدخل قراءة العداد.'
    if (!form.opened_at) next.opened_at = 'أدخل تاريخ ووقت الصيانة.'
    if (form.funding === 'stock') {
      if (!form.inventory_item_id) next.inventory_item_id = 'اختر صنف المخزون.'
      else if (!selectedItem) next.inventory_item_id = 'الصنف غير متاح أو نفد رصيده.'
    } else {
      if (!form.employee_id) next.employee_id = 'اختر موظف العهدة.'
      if (!form.unit_price || Number(form.unit_price) < 0) next.unit_price = 'أدخل سعر الشراء.'
    }
    if (!form.quantity || Number(form.quantity) < 1) next.quantity = 'الكمية يجب أن تكون ١ فأكثر.'
    setFieldErrors(next)
    return Object.keys(next).length === 0
  }

  async function save() {
    if (!validate()) {
      toast.error('راجع الحقول المطلوبة قبل الحفظ.')
      return
    }
    setSaving(true)
    try {
      const fromStock = form.funding === 'stock'
      const inventoryItemId = Number(form.inventory_item_id)
      await api('/work-orders', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          odometer: Number(form.odometer),
          workshop: form.workshop || undefined,
          labor_cost: Number(form.labor_cost || 0),
          puts_in_maintenance: putsInMaintenance,
          force_after_resolution: true,
          opened_at: form.opened_at || undefined,
          opened_on: form.opened_at ? form.opened_at.slice(0, 10) : undefined,
          next_service_on: form.next_service_on || undefined,
          next_service_odometer: form.next_service_odometer !== '' ? Number(form.next_service_odometer) : undefined,
          lines: [
            {
              description: form.description,
              part_source: fromStock ? 'stock' : 'purchase',
              ...(fromStock && Number.isFinite(inventoryItemId) && inventoryItemId > 0
                ? { inventory_item_id: inventoryItemId }
                : {}),
              quantity: Number(form.quantity || 1),
              unit_price: fromStock ? undefined : Number(form.unit_price || 0),
              from_petty_cash: !fromStock,
              petty_cash_employee_id: !fromStock && form.employee_id ? Number(form.employee_id) : undefined,
              petty_cash_amount: !fromStock
                ? Number(form.unit_price || 0) * Number(form.quantity || 1)
                : undefined,
            },
          ],
        },
      })
      toast.success('تم فتح أمر الشغل.')
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
      title={vehicle ? `صيانة · ${vehicle.label}` : 'أمر شغل'}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={() => void save()}>فتح أمر الشغل</Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        {!vehicle ? (
          <SelectField
            label="السيارة"
            required
            error={fieldErrors.vehicle_id}
            options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={form.vehicle_id}
            onChange={(e) => {
              const v = vehicles.find((x) => String(x.id) === e.target.value)
              setForm({ ...form, vehicle_id: e.target.value, odometer: v?.odometer != null ? String(v.odometer) : form.odometer })
              setFieldErrors((prev) => ({ ...prev, vehicle_id: '' }))
            }}
          />
        ) : null}
        <DateTimeField
          label="تاريخ ووقت الصيانة"
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
        <TextField label="وصف البند" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <SwitchField label="يضع السيارة في صيانة" checked={putsInMaintenance} onChange={setPutsInMaintenance} />

        <SelectField
          label="مصدر التمويل / القطع"
          required
          value={form.funding}
          onChange={(e) => {
            setForm({ ...form, funding: e.target.value as 'stock' | 'custody', inventory_item_id: '', employee_id: '', unit_price: '0' })
            setFieldErrors((prev) => ({ ...prev, inventory_item_id: '', employee_id: '', unit_price: '' }))
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
            onChange={(e) => {
              setForm({ ...form, inventory_item_id: e.target.value })
              setFieldErrors((prev) => ({ ...prev, inventory_item_id: '' }))
            }}
          />
        ) : (
          <>
            <SelectField
              label="موظف العهدة"
              required
              error={fieldErrors.employee_id}
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
        <MoneyField label="تكلفة العمالة" value={form.labor_cost} onChange={(e) => setForm({ ...form, labor_cost: e.target.value })} />
        <DateField
          label="موعد الصيانة التالية"
          value={form.next_service_on}
          onChange={(e) => setForm({ ...form, next_service_on: e.target.value })}
          hint="تذكير زمني اختياري"
        />
        <NumberField
          label="عداد الصيانة التالية"
          value={form.next_service_odometer}
          onChange={(e) => setForm({ ...form, next_service_odometer: e.target.value })}
          hint="تذكير بالكيلومترات اختياري"
        />
      </div>
    </Sheet>
  )
}
