import { StatusPill, type MenuItem } from '@/shared/ui'
import {
  IconEye, IconCheck, IconKey, IconReturn, IconCash, IconCalendar, IconCar, IconBan, IconPrinter, IconWallet,
} from '@/shared/ui'

export type Deposit = { id: number; original_amount: number; status: string; deductions_total: number; remaining?: number }

export type InspectionDamage = { id?: number; position: string; description: string; photo_path?: string | null }

export type Inspection = {
  id: number
  type: string
  inspected_at?: string | null
  odometer?: number | null
  fuel_level?: number | null
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

export type TimelineItem = { id: number; action: string; at?: string | null; user?: string | null }

export type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  actual_ends_at?: string | null
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  odometer_out?: number
  odometer_in?: number
  final_total?: number
  period_rent?: number
  damage_fee?: number
  excess_km_amount?: number
  notes?: string | null
  vehicle_id?: number
  customer?: { id: number; full_name: string; phone?: string }
  vehicle?: { id: number; brand: string; model: string; plate: string; odometer?: number }
  deposit?: Deposit | null
  inspections?: Inspection[]
  payments?: ContractPayment[]
  documents?: ContractDoc[]
  related?: RelatedContract[]
  timeline?: TimelineItem[]
  ledger?: { contract_total: number; paid: number; balance: number; deposit_held: number }
}

export type VehicleOption = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number; odometer?: number }

export type ActionKind = 'handover' | 'return' | 'cancel' | 'extend' | 'swap' | 'deposit' | 'deduct' | 'refund' | null

export const statusLabel: Record<string, string> = {
  pending: 'معلّق', approved: 'معتمد', active: 'نشط', completed: 'مكتمل', cancelled: 'ملغى',
}

export const depositLabel: Record<string, { label: string; tone: 'success' | 'warning' | 'steel' | 'muted' | 'danger' }> = {
  received: { label: 'مقبوض', tone: 'success' },
  partial: { label: 'مخصوم جزئياً', tone: 'warning' },
  settled: { label: 'مستهلك بالخصم', tone: 'steel' },
  returned: { label: 'مردود', tone: 'muted' },
}

export const actionTitle: Record<Exclude<ActionKind, null>, string> = {
  handover: 'تسليم السيارة',
  return: 'استلام وإقفال',
  cancel: 'إلغاء العقد',
  extend: 'تمديد (عقد جديد)',
  swap: 'تبديل بسبب عطل',
  deposit: 'قبض التأمين النقدي',
  deduct: 'خصم من التأمين',
  refund: 'رد متبقي التأمين',
}

export const inspectionTypeLabel: Record<string, string> = {
  checkout: 'فحص التسليم',
  checkin: 'فحص الاستلام',
}

export const timelineLabel: Record<string, string> = {
  created: 'إنشاء',
  approved: 'اعتماد',
  handed_over: 'تسليم',
  returned: 'استلام',
  cancelled: 'إلغاء',
  extended: 'تمديد',
  swapped: 'تبديل عطل',
}

export function DepositBadge({ deposit }: { deposit?: Deposit | null }) {
  if (!deposit) return <StatusPill status="none" tone="danger" label="غير مقبوض" />
  const meta = depositLabel[deposit.status] ?? { label: deposit.status, tone: 'muted' as const }
  return <StatusPill status={deposit.status} tone={meta.tone} label={meta.label} />
}

export function contractMenu(
  b: Contract,
  handlers: {
    view: () => void
    approve: () => void
    openAction: (kind: Exclude<ActionKind, null>) => void
    print: () => void
  },
): MenuItem[] {
  const items: MenuItem[] = [
    { id: 'view', label: 'عرض التفاصيل', icon: <IconEye size={16} />, onSelect: handlers.view },
    { id: 'sep-1', separator: true },
  ]
  if (b.status === 'pending') {
    items.push({ id: 'approve', label: 'اعتماد', icon: <IconCheck size={16} />, onSelect: handlers.approve })
  }
  if (b.status === 'approved') {
    items.push({ id: 'handover', label: 'تسليم', icon: <IconKey size={16} />, onSelect: () => handlers.openAction('handover') })
  }
  if (b.status === 'active') {
    items.push({ id: 'return', label: 'استلام وإقفال', icon: <IconReturn size={16} />, onSelect: () => handlers.openAction('return') })
  }
  if ((b.status === 'approved' || b.status === 'active') && !b.deposit) {
    items.push({ id: 'deposit', label: 'قبض تأمين', icon: <IconCash size={16} />, onSelect: () => handlers.openAction('deposit') })
  }
  if (b.status === 'approved' || b.status === 'active' || b.status === 'completed') {
    items.push({ id: 'extend', label: 'تمديد', icon: <IconCalendar size={16} />, onSelect: () => handlers.openAction('extend') })
  }
  if (b.status === 'active') {
    items.push({ id: 'swap', label: 'تبديل عطل', icon: <IconCar size={16} />, onSelect: () => handlers.openAction('swap') })
  }
  if (b.deposit && b.deposit.status !== 'returned') {
    items.push(
      { id: 'deduct', label: 'خصم تأمين', icon: <IconWallet size={16} />, onSelect: () => handlers.openAction('deduct') },
      { id: 'refund', label: 'رد تأمين', icon: <IconReturn size={16} />, onSelect: () => handlers.openAction('refund') },
    )
  }
  items.push({ id: 'print', label: 'طباعة', icon: <IconPrinter size={16} />, onSelect: handlers.print })
  if (b.status === 'pending' || b.status === 'approved') {
    items.push(
      { id: 'sep-2', separator: true },
      { id: 'cancel', label: 'إلغاء العقد', icon: <IconBan size={16} />, tone: 'danger', onSelect: () => handlers.openAction('cancel') },
    )
  }
  return items
}
