import { formatDepositAmount } from '@/shared/lib/format'
import { StatusPill, type MenuItem } from '@/shared/ui'
import {
  IconEye, IconCheck, IconKey, IconReturn, IconCash, IconCalendar, IconCar, IconBan, IconPrinter, IconWallet, IconTrash, IconPhone,
} from '@/shared/ui'

export type Deposit = { id: number; original_amount: number; status: string; deductions_total: number; remaining?: number }

export type InspectionDamage = { id?: number; position: string; description: string; photo_path?: string | null }

export type Inspection = {
  id: number
  type: string
  inspected_at?: string | null
  odometer?: number | null
  notes?: string | null
  damages?: InspectionDamage[]
}

export type ContractPayment = {
  id: number
  date?: string | null
  direction: string
  method: string
  reference?: string | null
  link_type: string
  notes?: string | null
  amount: number
}

export type ContractDoc = {
  id: number
  kind: string
  original_name: string
  purpose?: string | null
  url: string
  created_at?: string | null
}

export type RelatedContract = {
  id: number
  number: string
  status: string
  starts_at?: string | null
  planned_ends_at?: string | null
  relation: string
}

export type TimelineItem = { id: number; action: string; summary?: string; at?: string | null; user?: string | null }

export type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  actual_ends_at?: string | null
  frozen_daily_rate: number
  rent_basis?: 'daily' | 'half_monthly' | 'monthly' | string
  frozen_half_monthly_rate?: number | null
  frozen_monthly_rate?: number | null
  frozen_daily_km_allowance?: number | null
  frozen_excess_km_rate?: number | null
  prepaid_excess_km?: number | null
  prepaid_excess_km_amount?: number | null
  frozen_agreed_deposit?: number
  frozen_deposit_currency?: string | null
  excess_km?: number | null
  approved_days?: number
  odometer_out?: number
  odometer_in?: number
  final_total?: number
  period_rent?: number
  damage_fee?: number
  other_fees?: number
  other_fees_description?: string | null
  has_delivery_service?: boolean
  delivery_service_fee?: number
  delivery_service_notes?: string | null
  frozen_operating_insurance_fee?: number | null
  include_vehicle_value_obligation?: boolean
  frozen_vehicle_value_text?: string | null
  discount?: number
  excess_km_amount?: number
  notes?: string | null
  balance_due_on?: string | null
  created_by?: string | null
  sales_user?: { id: number; name: string } | null
  vehicle_id?: number
  customer?: { id: number; full_name: string; phone?: string; national_id_image_path?: string | null; national_id_back_path?: string | null }
  vehicle?: { id: number; brand: string; model: string; plate: string; odometer?: number }
  deposit?: Deposit | null
  inspections?: Inspection[]
  payments?: ContractPayment[]
  documents?: ContractDoc[]
  related?: RelatedContract[]
  extension_history?: Array<{
    extended_at?: string | null
    previous_planned_ends_at?: string | null
    planned_ends_at?: string | null
    previous_approved_days?: number | null
    approved_days?: number | null
    previous_rent_basis?: string | null
    rent_basis?: string | null
    by_user_id?: number | null
  }>
  timeline?: TimelineItem[]
  open_violations?: Array<{
    id: number
    amount: number
    occurred_at?: string | null
    status: string
    photo_paths?: string[]
    created_by_name?: string | null
  }>
  open_violations_total?: number
  open_violations_count?: number
  ledger?: { contract_total: number; paid: number; balance: number; deposit_held: number; violations_due?: number }
  progress?: {
    status: string
    tone: 'normal' | 'warning' | 'danger' | 'muted'
    percent: number
    remaining_label: string
    overdue_days: number
    limiting_label?: string | null
    message: string
  } | null
}

export type VehicleOption = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number; odometer?: number }

export type ActionKind = 'handover' | 'return' | 'cancel' | 'extend' | 'swap' | 'deposit' | 'deduct' | 'refund' | 'collect' | null

export const statusLabel: Record<string, string> = {
  pending: 'قيد المراجعة',
  approved: 'بانتظار التسليم',
  active: 'تم التسليم',
  completed: 'تم الاستلام',
  cancelled: 'تم الإلغاء',
}

export const depositLabel: Record<string, { label: string; tone: 'success' | 'warning' | 'steel' | 'muted' | 'danger' }> = {
  received: { label: 'مقبوض', tone: 'success' },
  partial: { label: 'مخصوم جزئياً', tone: 'warning' },
  settled: { label: 'مستهلك بالخصم', tone: 'steel' },
  returned: { label: 'مردود', tone: 'muted' },
}

export const actionTitle: Record<Exclude<ActionKind, null>, string> = {
  handover: 'تسليم العربية',
  return: 'استلام / إنهاء مبكر',
  cancel: 'إلغاء العقد',
  extend: 'تمديد المدة',
  swap: 'تبديل بسبب عطل',
  deposit: 'قبض التأمين النقدي',
  deduct: 'خصم من التأمين',
  refund: 'رد متبقي التأمين',
  collect: 'تحصيل دفعة من المتبقي',
}

export const inspectionTypeLabel: Record<string, string> = {
  checkout: 'فحص التسليم',
  checkin: 'فحص الاستلام',
}

export const timelineLabel: Record<string, string> = {
  created: 'إنشاء',
  approved: 'موافقة الإدارة',
  handed_over: 'تسليم',
  returned: 'استلام',
  cancelled: 'إلغاء',
  extended: 'تمديد',
  swapped: 'تبديل عطل',
}

/** Shows the deposit amount first; status is a secondary caption only. */
export function DepositBadge({
  deposit,
  agreedAmount,
  currency,
}: {
  deposit?: Deposit | null
  /** Fallback when deposit row not yet received (frozen/agreed on contract). */
  agreedAmount?: number | null
  currency?: string | null
}) {
  const amount = deposit?.original_amount ?? agreedAmount ?? null
  const meta = deposit
    ? (depositLabel[deposit.status] ?? { label: deposit.status, tone: 'muted' as const })
    : { label: 'غير مقبوض', tone: 'danger' as const }

  if (amount == null) {
    return <StatusPill status={deposit?.status || 'none'} tone={meta.tone} label={meta.label} />
  }

  return (
    <span className="rpm-deposit-amount">
      <strong className="rpm-table__primary tabular-nums">{formatDepositAmount(amount, currency)}</strong>
      <span className="rpm-table__sub">{meta.label}</span>
    </span>
  )
}

export function contractMenu(
  b: Contract,
  handlers: {
    view: () => void
    approve: () => void
    openAction: (kind: Exclude<ActionKind, null>) => void
    print: (kind?: 'contract' | 'invoice' | 'receipt') => void
    shareInvoice?: () => void
    softDelete?: () => void
    can?: (permission: string) => boolean
    isIntegrator?: boolean
  },
): MenuItem[] {
  const can = handlers.can ?? (() => true)
  const items: MenuItem[] = [
    { id: 'view', label: 'عرض التفاصيل', icon: <IconEye size={16} />, onSelect: handlers.view },
    { id: 'sep-1', separator: true },
  ]
  if (b.status === 'pending' && can('contracts.approve')) {
    items.push({ id: 'approve', label: 'موافقة الإدارة', icon: <IconCheck size={16} />, onSelect: handlers.approve })
  }
  if (b.status === 'approved' && can('contracts.update')) {
    items.push({ id: 'handover', label: 'تسليم', icon: <IconKey size={16} />, onSelect: () => handlers.openAction('handover') })
  }
  if (b.status === 'active' && can('contracts.update')) {
    items.push({ id: 'return', label: 'استلام / إنهاء مبكر', icon: <IconReturn size={16} />, onSelect: () => handlers.openAction('return') })
  }
  if ((b.status === 'approved' || b.status === 'active') && !b.deposit && can('deposits.collect')) {
    items.push({ id: 'deposit', label: 'قبض تأمين', icon: <IconCash size={16} />, onSelect: () => handlers.openAction('deposit') })
  }
  if (
    (b.status === 'pending' || b.status === 'approved' || b.status === 'active' || b.status === 'completed')
    && can('payments.create')
  ) {
    items.push({ id: 'collect', label: 'تحصيل متبقي', icon: <IconWallet size={16} />, onSelect: () => handlers.openAction('collect') })
  }
  if ((b.status === 'approved' || b.status === 'active') && can('contracts_extend.execute')) {
    items.push({ id: 'extend', label: 'تمديد', icon: <IconCalendar size={16} />, onSelect: () => handlers.openAction('extend') })
  }
  if (b.status === 'active' && can('contracts_swap.execute')) {
    items.push({ id: 'swap', label: 'تبديل عطل', icon: <IconCar size={16} />, onSelect: () => handlers.openAction('swap') })
  }
  if (b.deposit && b.deposit.status !== 'returned' && can('deposits.settle')) {
    items.push(
      { id: 'deduct', label: 'خصم تأمين', icon: <IconWallet size={16} />, onSelect: () => handlers.openAction('deduct') },
      { id: 'refund', label: 'رد تأمين', icon: <IconReturn size={16} />, onSelect: () => handlers.openAction('refund') },
    )
  }
  if (can('contracts.print')) {
    items.push(
      { id: 'print-contract', label: 'طباعة العقد', icon: <IconPrinter size={16} />, onSelect: () => handlers.print('contract') },
      { id: 'print-invoice', label: 'الفاتورة', icon: <IconPrinter size={16} />, onSelect: () => handlers.print('invoice') },
    )
    if (handlers.shareInvoice && b.customer?.phone?.trim()) {
      items.push({
        id: 'wa-invoice',
        label: 'واتساب الفاتورة',
        icon: <IconPhone size={16} />,
        onSelect: handlers.shareInvoice,
      })
    }
    items.push(
      { id: 'print-receipt', label: 'إقرار استلام سيارة', icon: <IconPrinter size={16} />, onSelect: () => handlers.print('receipt') },
    )
  }
  if ((b.status === 'pending' || b.status === 'approved') && can('contracts_cancel.approve')) {
    items.push(
      { id: 'sep-2', separator: true },
      { id: 'cancel', label: 'إلغاء العقد', icon: <IconBan size={16} />, tone: 'danger', onSelect: () => handlers.openAction('cancel') },
    )
  }
  if (handlers.isIntegrator && handlers.softDelete) {
    items.push(
      { id: 'sep-soft-delete', separator: true },
      {
        id: 'soft-delete',
        label: 'حذف',
        icon: <IconTrash size={16} />,
        tone: 'danger',
        onSelect: handlers.softDelete,
      },
    )
  }
  return items
}
