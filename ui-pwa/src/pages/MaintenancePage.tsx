import { useState } from 'react'
import {
  PageHeader,
  SummaryStrip,
  Button,
  Sheet,
  TextField,
  SelectField,
  NumberField,
  MoneyField,
  SwitchField,
  useToast,
  StatusPill,
} from '@/shared/ui'
import { vehicles } from '@/shared/lib/mock-data'
import './pages.css'

type WorkOrder = {
  id: string
  vehicle: string
  status: 'open' | 'in_progress' | 'closed'
  partsCost: number
  laborCost: number
  source: string
}

const statusLabel = {
  open: 'مفتوح',
  in_progress: 'قيد التنفيذ',
  closed: 'مغلق',
} as const

export function MaintenancePage() {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [fromStock, setFromStock] = useState(true)
  const [fromPettyCash, setFromPettyCash] = useState(false)
  const [putsInMaintenance, setPutsInMaintenance] = useState(true)
  const [rows, setRows] = useState<WorkOrder[]>([
    {
      id: 'wo1',
      vehicle: 'نيسان صني · ق ر ش 3320',
      status: 'in_progress',
      partsCost: 850,
      laborCost: 300,
      source: 'من المخزون (FIFO)',
    },
  ])

  async function onSave() {
    setSaving(true)
    await new Promise((r) => setTimeout(r, 400))
    setRows((prev) => [
      {
        id: `wo-${Date.now()}`,
        vehicle: 'هيونداي إلنترا · أ ب ج 1234',
        status: 'open',
        partsCost: 0,
        laborCost: 0,
        source: fromStock ? 'من المخزون (FIFO)' : fromPettyCash ? 'شراء من العهدة' : 'شراء من المعرض',
      },
      ...prev,
    ])
    setSaving(false)
    setOpen(false)
    toast.success('تم فتح أمر الشغل.')
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الصيانة"
        subtitle="أوامر شغل · استهلاك مخزون بالأقدم أولاً أو شراء"
        action={<Button onClick={() => setOpen(true)}>أمر شغل</Button>}
      />

      <SummaryStrip
        items={[
          { label: 'مفتوحة', value: String(rows.filter((r) => r.status !== 'closed').length), tone: 'warning' },
          { label: 'سيارات في صيانة', value: String(vehicles.filter((v) => v.status === 'maintenance').length), tone: 'danger' },
          { label: 'أصناف تحت التنبيه', value: '2' },
          { label: 'عهد نشطة', value: '3' },
        ]}
      />

      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{r.vehicle}</strong>
              <StatusPill status={r.status === 'closed' ? 'completed' : r.status === 'open' ? 'pending' : 'maintenance'} label={statusLabel[r.status]} />
            </div>
            <p className="muted">{r.source}</p>
            <div className="rpm-card__meta">
              <span>قطع: {r.partsCost} ج.م</span>
              <span>عمالة: {r.laborCost} ج.م</span>
            </div>
          </article>
        ))}
      </div>

      <Sheet
        open={open}
        title="أمر شغل"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>
              حفظ
            </Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              إلغاء
            </Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <SelectField
            label="السيارة"
            required
            options={vehicles.map((v) => ({ value: v.id, label: `${v.brand} ${v.model} · ${v.plate}` }))}
            defaultValue=""
          />
          <TextField label="الورشة" placeholder="اسم الورشة نص حر" />
          <NumberField label="العداد" required />
          <SwitchField label="يضع السيارة في صيانة" checked={putsInMaintenance} onChange={setPutsInMaintenance} />
          <SwitchField
            label="القطعة من المخزون (FIFO)"
            checked={fromStock}
            onChange={(v) => {
              setFromStock(v)
              if (v) setFromPettyCash(false)
            }}
          />
          {!fromStock ? (
            <>
              <MoneyField label="سعر شراء الوحدة" required />
              <SwitchField label="الشراء من العهدة" checked={fromPettyCash} onChange={setFromPettyCash} />
              {fromPettyCash ? <SelectField label="موظف العهدة" required options={[{ value: '1', label: 'محمود — عهدة' }]} /> : null}
            </>
          ) : (
            <SelectField
              label="صنف المخزون"
              required
              options={[
                { value: 'oil', label: 'زيت محرك 5W30' },
                { value: 'filter', label: 'فلتر زيت' },
              ]}
            />
          )}
          <NumberField label="الكمية" required defaultValue={1} />
          <MoneyField label="تكلفة العمالة" />
        </div>
      </Sheet>
    </div>
  )
}
