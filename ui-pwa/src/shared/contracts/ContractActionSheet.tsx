import {
  Button, Sheet, TextField, SelectField, NumberField, MoneyField, TextAreaField, SignatureField, MediaUpload,
} from '@/shared/ui'
import { formatMoney } from '@/shared/lib/format'
import { actionTitle, type ActionKind, type Contract, type VehicleOption } from './contractModel'

type Form = {
  odometer_out: string
  odometer_in: string
  damage_fee: string
  reason: string
  planned_ends_at: string
  replacement_vehicle_id: string
  method: string
  receipt_number: string
  fuel_level: string
  notes: string
  signature_data: string
  damage_position: string
  damage_description: string
  damage_photo_path: string
  deduct_amount: string
  deduct_reason: string
}

type Props = {
  action: ActionKind
  selected: Contract | null
  vehicles: VehicleOption[]
  saving: boolean
  form: Form
  onChange: (next: Form) => void
  onConfirm: () => void
  onClose: () => void
}

export function ContractActionSheet({ action, selected, vehicles, saving, form, onChange, onConfirm, onClose }: Props) {
  const patch = (partial: Partial<Form>) => onChange({ ...form, ...partial })
  return (
    <Sheet
      open={!!action && !!selected}
      title={action && selected ? `${actionTitle[action]} · ${selected.number}` : ''}
      onClose={onClose}
      footer={
        <>
          <Button loading={saving} onClick={onConfirm}>تأكيد</Button>
          <Button variant="secondary" onClick={onClose}>إغلاق</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        {action === 'handover' || action === 'swap' ? (
          <NumberField
            label={action === 'swap' ? 'عداد السيارة البديلة (خروج)' : 'عداد الخروج'}
            required
            value={form.odometer_out}
            onChange={(e) => patch({ odometer_out: e.target.value })}
          />
        ) : null}
        {action === 'return' || action === 'swap' ? (
          <NumberField
            label={action === 'swap' ? 'عداد السيارة القديمة (عودة)' : 'عداد العودة'}
            required
            value={form.odometer_in}
            onChange={(e) => patch({ odometer_in: e.target.value })}
          />
        ) : null}
        {action === 'handover' || action === 'return' || action === 'swap' ? (
          <NumberField label="مستوى البنزين %" value={form.fuel_level} onChange={(e) => patch({ fuel_level: e.target.value })} />
        ) : null}
        {action === 'return' || action === 'swap' ? (
          <MoneyField label="رسوم أضرار" value={form.damage_fee} onChange={(e) => patch({ damage_fee: e.target.value })} />
        ) : null}
        {action === 'return' || action === 'swap' ? (
          <>
            <TextField label="موضع الضرر (إن وُجد)" value={form.damage_position} onChange={(e) => patch({ damage_position: e.target.value })} />
            <TextAreaField label="وصف الضرر" value={form.damage_description} onChange={(e) => patch({ damage_description: e.target.value })} />
            <MediaUpload
              label="صورة / فيديو الضرر"
              value={form.damage_photo_path || null}
              collection="inspection_damage"
              purpose="damage"
              accept="both"
              attachableType="contract"
              attachableId={selected?.id}
              onChange={(path) => patch({ damage_photo_path: path || '' })}
              hint="اختياري — يُحفظ محلياً مع سجل الفحص"
            />
          </>
        ) : null}
        {action === 'handover' || action === 'return' || action === 'swap' ? (
          <>
            <TextAreaField label="ملاحظات الفحص" value={form.notes} onChange={(e) => patch({ notes: e.target.value })} />
            <SignatureField
              required={action === 'handover' || action === 'swap' || Boolean(form.damage_fee) || Boolean(form.damage_position)}
              value={form.signature_data}
              onChange={(signature_data) => patch({ signature_data })}
            />
          </>
        ) : null}
        {action === 'cancel' ? (
          <TextAreaField label="سبب الإلغاء" required value={form.reason} onChange={(e) => patch({ reason: e.target.value })} />
        ) : null}
        {action === 'extend' ? (
          <>
            <TextField
              label="تاريخ نهاية التمديد"
              required
              type="date"
              value={form.planned_ends_at.slice(0, 10)}
              onChange={(e) => {
                const date = e.target.value
                const time = form.planned_ends_at.includes('T') ? form.planned_ends_at.slice(11, 16) : '12:00'
                patch({ planned_ends_at: date ? `${date}T${time}` : '' })
              }}
            />
            <TextField
              label="وقت النهاية"
              required
              type="time"
              value={form.planned_ends_at.includes('T') ? form.planned_ends_at.slice(11, 16) : '12:00'}
              onChange={(e) => {
                const date = form.planned_ends_at.slice(0, 10) || new Date().toISOString().slice(0, 10)
                patch({ planned_ends_at: `${date}T${e.target.value}` })
              }}
            />
          </>
        ) : null}
        {action === 'swap' ? (
          <SelectField
            label="السيارة البديلة"
            required
            options={vehicles
              .filter((v) => v.id !== selected?.vehicle_id)
              .map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={form.replacement_vehicle_id}
            onChange={(e) => patch({ replacement_vehicle_id: e.target.value })}
          />
        ) : null}
        {action === 'deposit' || action === 'refund' ? (
          <>
            <SelectField
              label={action === 'refund' ? 'طريقة الرد' : 'طريقة التحصيل'}
              required
              options={[
                { value: 'cash', label: 'نقدي' },
                { value: 'card', label: 'بطاقة' },
                { value: 'transfer', label: 'تحويل' },
              ]}
              value={form.method}
              onChange={(e) => patch({ method: e.target.value })}
            />
            <TextField label="رقم الإيصال" required value={form.receipt_number} onChange={(e) => patch({ receipt_number: e.target.value })} />
            {action === 'deposit' ? <p className="muted">المبلغ: {formatMoney(selected?.frozen_agreed_deposit || 0)} (خارج إجمالي العقد)</p> : null}
          </>
        ) : null}
        {action === 'deduct' ? (
          <>
            <MoneyField label="مبلغ الخصم" required value={form.deduct_amount} onChange={(e) => patch({ deduct_amount: e.target.value })} />
            <TextAreaField label="سبب الخصم" required value={form.deduct_reason} onChange={(e) => patch({ deduct_reason: e.target.value })} />
          </>
        ) : null}
      </div>
    </Sheet>
  )
}
