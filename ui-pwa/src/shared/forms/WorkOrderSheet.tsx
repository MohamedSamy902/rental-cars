import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  Button, Sheet, SelectField, NumberField, MoneyField, TextField, SwitchField, useToast,
} from '@/shared/ui'

type Props = {
  open: boolean
  onClose: () => void
  onSaved?: () => void
  /** Locks the work order to one vehicle (vehicle page quick action) */
  vehicle?: { id: number; label: string; odometer?: number }
}

type VehicleOption = { id: number; brand: string; model: string; plate: string; odometer?: number }
type Item = { id: number; name: string; code: string }

const emptyForm = {
  vehicle_id: '', odometer: '', workshop: '', labor_cost: '0', description: 'صيانة',
  inventory_item_id: '', quantity: '1', unit_price: '0',
}

export function WorkOrderSheet({ open, onClose, onSaved, vehicle }: Props) {
  const toast = useToast()
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [items, setItems] = useState<Item[]>([])
  const [saving, setSaving] = useState(false)
  const [fromStock, setFromStock] = useState(true)
  const [putsInMaintenance, setPutsInMaintenance] = useState(true)
  const [form, setForm] = useState(emptyForm)

  const lockedId = vehicle?.id
  const lockedOdometer = vehicle?.odometer

  useEffect(() => {
    if (!open) return
    setForm({
      ...emptyForm,
      vehicle_id: lockedId ? String(lockedId) : '',
      odometer: lockedOdometer != null ? String(lockedOdometer) : '',
    })
    const requests: Promise<unknown>[] = [
      api<{ data: Item[] }>('/inventory').then((r) => setItems(r.data)).catch(() => setItems([])),
    ]
    if (!lockedId) {
      requests.push(api<{ data: VehicleOption[] }>('/vehicles?per_page=100').then((r) => setVehicles(r.data)))
    }
    Promise.all(requests).catch((e) => toast.error((e as ApiError).message))
  }, [open, lockedId, lockedOdometer, toast])

  async function save() {
    setSaving(true)
    try {
      await api('/work-orders', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          odometer: Number(form.odometer),
          workshop: form.workshop || undefined,
          labor_cost: Number(form.labor_cost || 0),
          puts_in_maintenance: putsInMaintenance,
          force_after_resolution: true,
          lines: [
            {
              description: form.description,
              part_source: fromStock ? 'stock' : 'purchase',
              inventory_item_id: fromStock ? Number(form.inventory_item_id) : undefined,
              quantity: Number(form.quantity || 1),
              unit_price: fromStock ? undefined : Number(form.unit_price || 0),
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
        <TextField label="الورشة" value={form.workshop} onChange={(e) => setForm({ ...form, workshop: e.target.value })} />
        <NumberField label="العداد" required value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
        <TextField label="وصف البند" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <SwitchField label="يضع السيارة في صيانة" checked={putsInMaintenance} onChange={setPutsInMaintenance} />
        <SwitchField label="القطعة من المخزون (FIFO)" checked={fromStock} onChange={setFromStock} />
        {fromStock ? (
          <SelectField
            label="صنف المخزون"
            required
            options={items.map((i) => ({ value: String(i.id), label: `${i.code} · ${i.name}` }))}
            value={form.inventory_item_id}
            onChange={(e) => setForm({ ...form, inventory_item_id: e.target.value })}
          />
        ) : (
          <MoneyField label="سعر شراء الوحدة" required value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value })} />
        )}
        <NumberField label="الكمية" required value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
        <MoneyField label="تكلفة العمالة" value={form.labor_cost} onChange={(e) => setForm({ ...form, labor_cost: e.target.value })} />
      </div>
    </Sheet>
  )
}
