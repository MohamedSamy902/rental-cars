import {
  Button, Sheet, TextField, SelectField, NumberField, MoneyField, TextAreaField, MediaUpload,
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
  notes: string
  damage_position: string
  damage_description: string
  damage_photo_path: string
  deduct_amount: string
  deduct_reason: string
  payment_amount: string
  payment_method: string
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
  const estimatedTotal = selected
    ? Math.max(
        0,
        (selected.final_total
          ?? (selected.frozen_daily_rate || 0) * Math.max(1, selected.approved_days || 1))
          - 0,
      )
    : 0

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
            label={action === 'swap' ? 'عداد العربية البديلة (خروج)' : 'عداد الخروج'}
            required
            value={form.odometer_out}
            onChange={(e) => patch({ odometer_out: e.target.value })}
          />
        ) : null}
        {action === 'return' || action === 'swap' ? (
          <NumberField
            label={action === 'swap' ? 'عداد العربية القديمة (عودة)' : 'عداد العودة'}
            required
            value={form.odometer_in}
            onChange={(e) => patch({ odometer_in: e.target.value })}
          />
        ) : null}
        {action === 'return' && (selected?.open_violations_total ?? 0) > 0 ? (
          <div
            className="rpm-due-banner"
            role="status"
            style={{
              padding: '0.85rem 1rem',
              borderRadius: 'var(--radius-sm, 12px)',
              background: 'var(--warning-soft)',
              color: 'var(--warning-ink)',
              border: '1px solid color-mix(in srgb, var(--warning) 35%, var(--border))',
              display: 'grid',
              gap: '0.35rem',
            }}
          >
            <strong>مبلغ مطلوب · مخالفات مفتوحة</strong>
            <span style={{ fontSize: '1.15rem', fontWeight: 800 }}>{formatMoney(selected?.open_violations_total ?? 0)}</span>
            <span className="muted" style={{ color: 'inherit', opacity: 0.85 }}>للمتابعة فقط — بلا ترحيل محاسبي</span>
            {(selected?.open_violations?.length ?? 0) > 0 ? (
              <ul style={{ margin: 0, paddingInlineStart: '1.1rem' }}>
                {(selected?.open_violations || []).map((v) => (
                  <li key={v.id}>
                    {formatMoney(v.amount)}
                    {v.occurred_at ? ` · ${v.occurred_at}` : ''}
                    {v.created_by_name ? ` · رفعها ${v.created_by_name}` : ''}
                  </li>
                ))}
              </ul>
            ) : (selected?.open_violations_count ?? 0) > 0 ? (
              <span>{selected?.open_violations_count} مخالفة مفتوحة على هذا الحجز</span>
            ) : null}
          </div>
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
          <TextAreaField label="ملاحظات الفحص" value={form.notes} onChange={(e) => patch({ notes: e.target.value })} />
        ) : null}
        {action === 'handover' ? (
          <>
            <MoneyField
              label="دفعة عند التسليم"
              value={form.payment_amount}
              onChange={(e) => patch({ payment_amount: e.target.value })}
              hint={`يمكن دفع جزء من الإيجار — تقدير العقد ${formatMoney(estimatedTotal)}`}
            />
            <SelectField
              label="طريقة الدفعة"
              options={[
                { value: 'cash', label: 'نقدي' },
                { value: 'card', label: 'بطاقة' },
                { value: 'transfer', label: 'تحويل' },
              ]}
              value={form.payment_method}
              onChange={(e) => patch({ payment_method: e.target.value })}
            />
          </>
        ) : null}
        {action === 'cancel' ? (
          <TextAreaField label="سبب الإلغاء" required value={form.reason} onChange={(e) => patch({ reason: e.target.value })} />
        ) : null}
        {action === 'extend' ? (
          <TextField
            label="تاريخ نهاية التمديد"
            required
            type="date"
            value={form.planned_ends_at.slice(0, 10)}
            onChange={(e) => {
              const date = e.target.value
              patch({ planned_ends_at: date ? `${date}T00:00` : '' })
            }}
          />
        ) : null}
        {action === 'swap' ? (
          <SelectField
            label="العربية البديلة"
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
