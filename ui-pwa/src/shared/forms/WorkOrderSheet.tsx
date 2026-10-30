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

  const lockedId = vehicle?.id
  const lockedOdometer = vehicle?.odometer

  const selectedItem = useMemo(
    () => items.find((i) => String(i.id) === form.inventory_item_id) || null,
    [items, form.inventory_item_id],
  )

  useEffect(() => {
    if (!open) return
    setForm({
      ...emptyForm,
      opened_at: nowLocal(),
      vehicle_id: lockedId ? String(lockedId) : '',
      odometer: lockedOdometer != null ? String(lockedOdometer) : '',
    })
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

  async function save() {
    setSaving(true)
    try {
      const fromStock = form.funding === 'stock'
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
              inventory_item_id: fromStock ? Number(form.inventory_item_id) : undefined,
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
      toast.error((e as ApiError).message)
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
            options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={form.vehicle_id}
            onChange={(e) => {
              const v = vehicles.find((x) => String(x.id) === e.target.value)
              setForm({ ...form, vehicle_id: e.target.value, odometer: v?.odometer != null ? String(v.odometer) : form.odometer })
            }}
          />
        ) : null}
        <DateTimeField
          label="تاريخ ووقت الصيانة"
          required
          value={form.opened_at}
          onChange={(e) => setForm({ ...form, opened_at: e.target.value })}
        />
        <TextField label="الورشة" value={form.workshop} onChange={(e) => setForm({ ...form, workshop: e.target.value })} />
        <NumberField label="العداد" required value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
        <TextField label="وصف البند" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <SwitchField label="يضع السيارة في صيانة" checked={putsInMaintenance} onChange={setPutsInMaintenance} />

        <SelectField
          label="مصدر التمويل / القطع"
          required
          value={form.funding}
          onChange={(e) => setForm({ ...form, funding: e.target.value as 'stock' | 'custody', inventory_item_id: '', employee_id: '', unit_price: '0' })}
          options={[
            { value: 'stock', label: 'من المخزون (سعر مسجّل تلقائياً)' },
            { value: 'custody', label: 'شراء من عهدة موظف' },
          ]}
        />

        {form.funding === 'stock' ? (
          <>
            <SelectField
              label="صنف المخزون"
              required
              options={items.map((i) => ({
                value: String(i.id),
                label: `${i.code} · ${i.name}${i.remaining != null ? ` · متبقي ${i.remaining}` : ''}${i.unit_cost != null ? ` · ${formatMoney(i.unit_cost)}` : ''}`,
              }))}
              value={form.inventory_item_id}
              onChange={(e) => setForm({ ...form, inventory_item_id: e.target.value })}
            />
            {selectedItem?.unit_cost != null ? (
              <TextField
                label="سعر الوحدة من المخزون"
                value={formatMoney(selectedItem.unit_cost)}
                disabled
                hint="يُحسب تلقائياً من أقدم دفعة (FIFO) ولا يُدخل يدوياً"
              />
            ) : null}
          </>
        ) : (
          <>
            <SelectField
              label="موظف العهدة"
              required
              options={[
                { value: '', label: 'اختر…' },
                ...employees.map((e) => ({ value: String(e.id), label: e.name })),
              ]}
              value={form.employee_id}
              onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
            />
            <MoneyField label="سعر شراء الوحدة" required value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value })} />
          </>
        )}

        <NumberField label="الكمية" required value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
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
