import { useMemo } from 'react'
import {
  Button, Sheet, TextField, SelectField, NumberField, MoneyField, TextAreaField, MediaUpload, DateField,
} from '@/shared/ui'
import { formatDate, formatDateTime, formatDepositAmount, formatMoney } from '@/shared/lib/format'
import { actionTitle, type ActionKind, type Contract, type VehicleOption } from './contractModel'
import {
  addDaysIso,
  countDaysBetween,
  estimatePeriodRentWithBasis,
  extendPriceHint,
  resolveBasisAfterExtension,
  unitRateForBasis,
} from './extendPricing'

type Form = {
  odometer_out: string
  odometer_in: string
  damage_fee: string
  reason: string
  planned_ends_at: string
  extension_days: string
  period_rent: string
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
  balance_due_on: string
}

type Props = {
  action: ActionKind
  selected: Contract | null
  vehicles: VehicleOption[]
  saving: boolean
  form: Form
  onChange: (next: Form) => void
  onConfirm: (override?: Partial<Form>) => void
  onClose: () => void
}

export function ContractActionSheet({ action, selected, vehicles, saving, form, onChange, onConfirm, onClose }: Props) {
  const patch = (partial: Partial<Form>) => onChange({ ...form, ...partial })
  const estimatedTotal = selected
    ? Math.max(
        0,
        selected.final_total
          ?? selected.period_rent
          ?? (selected.frozen_daily_rate || 0) * Math.max(1, selected.approved_days || 1),
      )
    : 0

  const previousDays = useMemo(() => {
    if (!selected) return 0
    if (selected.approved_days && selected.approved_days > 0) return selected.approved_days
    return countDaysBetween(selected.starts_at, selected.planned_ends_at)
  }, [selected])

  const extensionDaysNum = Math.max(0, Number(form.extension_days || 0))
  const extendPreview = useMemo(() => {
    if (!selected || extensionDaysNum < 1) {
      return {
        newEnd: '', newDays: 0, basis: 'daily' as const,
        estimate: 0, unit: 0, previousRent: 0,
      }
    }
    const newEnd = addDaysIso(selected.planned_ends_at, extensionDaysNum)
    const newDays = countDaysBetween(selected.starts_at, newEnd)
    const basis = resolveBasisAfterExtension(selected.rent_basis, previousDays, newDays)
    const daily = selected.frozen_daily_rate || 0
    const half = selected.frozen_half_monthly_rate
    const monthly = selected.frozen_monthly_rate
    const previousBasis = resolveBasisAfterExtension(selected.rent_basis, previousDays, previousDays)
    const previousRent = previousDays > 0
      ? estimatePeriodRentWithBasis(previousDays, previousBasis, daily, half, monthly)
      : 0
    // إيجار مدة التمديد فقط (ليس إجمالي العقد)
    const estimate = estimatePeriodRentWithBasis(extensionDaysNum, basis, daily, half, monthly)
    const unit = unitRateForBasis(basis, daily, half, monthly)
    return { newEnd, newDays, basis, estimate, unit, previousRent }
  }, [selected, extensionDaysNum, previousDays])

  const periodRentValue = form.period_rent
    || (extendPreview.estimate > 0 ? String(extendPreview.estimate) : '')

  function patchExtensionDays(raw: string) {
    const days = Math.max(0, Number(raw || 0))
    const newEnd = selected && days > 0 ? addDaysIso(selected.planned_ends_at, days) : ''
    const newDays = selected && newEnd ? countDaysBetween(selected.starts_at, newEnd) : 0
    const basis = selected
      ? resolveBasisAfterExtension(selected.rent_basis, previousDays, newDays)
      : 'daily'
    const estimate = selected && days > 0
      ? estimatePeriodRentWithBasis(
          days,
          basis,
          selected.frozen_daily_rate || 0,
          selected.frozen_half_monthly_rate,
          selected.frozen_monthly_rate,
        )
      : 0
    onChange({
      ...form,
      extension_days: raw,
      planned_ends_at: newEnd,
      period_rent: estimate > 0 ? String(estimate) : '',
    })
  }

  return (
    <Sheet
      open={!!action && !!selected}
      title={action && selected ? `${actionTitle[action]} · ${selected.number}` : ''}
      onClose={onClose}
      footer={
        <>
          <Button
            loading={saving}
            onClick={() => {
              if (action === 'extend') {
                const rent = Number(periodRentValue || 0)
                onConfirm({
                  period_rent: rent > 0 ? String(Math.round(rent)) : form.period_rent,
                  planned_ends_at: form.planned_ends_at || extendPreview.newEnd,
                  extension_days: form.extension_days,
                })
                return
              }
              onConfirm()
            }}
          >
            تأكيد
          </Button>
          <Button variant="secondary" onClick={onClose}>إغلاق</Button>
        </>
      }
    >
      <div className="rpm-form-grid">
        {action === 'return' ? (
          <p className="muted" style={{ margin: 0, gridColumn: '1 / -1' }}>
            إنهاء الإيجار (حتى لو مبكر بعد يوم أو يومين): يسجّل أيام التشغيل الفعلية ويقفل العقد ويرجع العربية للمتاح فوراً.
          </p>
        ) : null}
        {action === 'extend' ? (
          <p className="muted" style={{ margin: 0, gridColumn: '1 / -1' }}>
            تمديد داخل نفس العقد — بلا عقد جديد وبلا استلام/تسليم. أدخل مدة التمديد وسعر مدة التمديد فقط؛ إيجار المدة السابقة يبقى كما هو ويُجمع عليه.
          </p>
        ) : null}
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
            <span className="muted" style={{ color: 'inherit', opacity: 0.85 }}>للمتابعة التشغيلية</span>
            {(selected?.open_violations?.length ?? 0) > 0 ? (
              <ul style={{ margin: 0, paddingInlineStart: '1.1rem' }}>
                {(selected?.open_violations || []).map((v) => (
                  <li key={v.id}>
                    {formatMoney(v.amount)}
                    {v.occurred_at ? ` · ${formatDate(v.occurred_at)}` : ''}
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
            {Number(form.payment_amount || 0) > 0 && Number(form.payment_amount || 0) < estimatedTotal ? (
              <DateField
                label="تاريخ تحصيل باقي المبلغ"
                required
                value={form.balance_due_on}
                onChange={(e) => patch({ balance_due_on: e.target.value })}
                hint="إشعار قبل الموعد بيومين حتى اكتمال السداد"
              />
            ) : null}
          </>
        ) : null}
        {action === 'collect' ? (
          <>
            <p className="muted">
              المتبقي الحالي: {formatMoney(selected?.ledger?.balance || 0)}
              {selected?.balance_due_on ? ` · استحقاق ${formatDate(selected.balance_due_on)}` : ''}
            </p>
            <MoneyField
              label="المبلغ المحصّل الآن"
              required
              value={form.payment_amount}
              onChange={(e) => patch({ payment_amount: e.target.value })}
            />
            <SelectField
              label="طريقة التحصيل"
              options={[
                { value: 'cash', label: 'نقدي' },
                { value: 'card', label: 'بطاقة' },
                { value: 'transfer', label: 'تحويل' },
              ]}
              value={form.payment_method}
              onChange={(e) => patch({ payment_method: e.target.value })}
            />
            {Number(form.payment_amount || 0) > 0
              && Number(form.payment_amount || 0) < (selected?.ledger?.balance || 0) ? (
                <DateField
                  label="تاريخ تحصيل الباقي التالي"
                  required
                  value={form.balance_due_on}
                  onChange={(e) => patch({ balance_due_on: e.target.value })}
                  hint="يُحدَّث موعد الاستحقاق ويُعاد التنبيه قبلها بيومين"
                />
              ) : (
                <p className="muted">إن سدّدت المتبقي بالكامل يُغلق التنبيه تلقائياً.</p>
              )}
            <TextAreaField label="ملاحظة" value={form.notes} onChange={(e) => patch({ notes: e.target.value })} />
          </>
        ) : null}
        {action === 'cancel' ? (
          <TextAreaField label="سبب الإلغاء" required value={form.reason} onChange={(e) => patch({ reason: e.target.value })} />
        ) : null}
        {action === 'extend' && selected ? (
          <>
            <NumberField
              label="مدة التمديد (أيام)"
              required
              value={form.extension_days}
              onChange={(e) => patchExtensionDays(e.target.value)}
              hint={
                extendPreview.newEnd
                  ? `النهاية الحالية ${formatDate(selected.planned_ends_at)} → ${formatDateTime(extendPreview.newEnd)} · إجمالي ${extendPreview.newDays} يوماً`
                  : `النهاية الحالية ${formatDate(selected.planned_ends_at)}`
              }
            />
            {extendPreview.newDays > 0 ? (
              <MoneyField
                label="إيجار مدة التمديد"
                required
                value={periodRentValue}
                onChange={(e) => patch({ period_rent: e.target.value })}
                hint={extendPriceHint(
                  extendPreview.basis,
                  extensionDaysNum,
                  extendPreview.unit,
                  extendPreview.previousRent,
                  Number(periodRentValue || 0),
                )}
              />
            ) : (
              <p className="muted" style={{ gridColumn: '1 / -1', margin: 0 }}>
                أدخل عدد أيام التمديد لعرض إيجار مدة التمديد فقط تلقائياً.
              </p>
            )}
          </>
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
            {action === 'deposit' ? (
              <p className="muted">
                المبلغ: {formatDepositAmount(selected?.frozen_agreed_deposit || 0, selected?.frozen_deposit_currency)} (خارج إجمالي العقد)
              </p>
            ) : null}
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
