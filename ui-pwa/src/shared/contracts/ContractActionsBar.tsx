import { useAuth } from '@/shared/auth/AuthContext'
import { Button } from '@/shared/ui'
import { type ActionKind, type Contract } from './contractModel'
import type { PrintDocKind } from './printContract'

type Props = {
  contract: Contract
  onApprove: () => void
  onAction: (kind: Exclude<ActionKind, null>) => void
  onPrint: (kind?: PrintDocKind) => void
}

export function ContractActionsBar({ contract: b, onApprove, onAction, onPrint }: Props) {
  const { can } = useAuth()
  const canApprove = can('contracts.approve')
  const canUpdate = can('contracts.update')
  const canCancel = can('contracts_cancel.approve')
  const canExtend = can('contracts_extend.execute')
  const canSwap = can('contracts_swap.execute')
  const canDeposit = can('deposits.collect')
  const canSettle = can('deposits.settle')
  const canPrint = can('contracts.print')

  return (
    <div className="rpm-card__actions">
      {b.status === 'pending' ? (
        <>
          {canApprove ? <Button type="button" size="sm" onClick={onApprove}>اعتماد</Button> : null}
          {canCancel ? <Button type="button" size="sm" variant="danger" onClick={() => onAction('cancel')}>إلغاء العقد</Button> : null}
        </>
      ) : null}
      {b.status === 'approved' ? (
        <>
          {canUpdate ? <Button type="button" size="sm" onClick={() => onAction('handover')}>تسليم</Button> : null}
          {!b.deposit && canDeposit ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deposit')}>قبض تأمين</Button> : null}
          {canCancel ? <Button type="button" size="sm" variant="danger" onClick={() => onAction('cancel')}>إلغاء العقد</Button> : null}
          {canExtend ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button> : null}
        </>
      ) : null}
      {b.status === 'active' ? (
        <>
          {canUpdate ? <Button type="button" size="sm" onClick={() => onAction('return')}>استلام</Button> : null}
          {!b.deposit && canDeposit ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deposit')}>قبض تأمين</Button> : null}
          {canExtend ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button> : null}
          {canSwap ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('swap')}>تبديل عطل</Button> : null}
        </>
      ) : null}
      {b.status === 'completed' && canExtend ? (
        <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button>
      ) : null}
      {b.deposit && b.deposit.status !== 'returned' && canSettle ? (
        <>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deduct')}>خصم تأمين</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('refund')}>رد تأمين</Button>
        </>
      ) : null}
      {canPrint ? (
        <div className="cd-print-actions" role="group" aria-label="طباعة المستندات">
          <Button type="button" size="sm" variant="secondary" className="cd-print-actions__btn" onClick={() => onPrint('contract')}>طباعة العقد</Button>
          <Button type="button" size="sm" variant="secondary" className="cd-print-actions__btn" onClick={() => onPrint('invoice')}>الفاتورة / كشف الحساب</Button>
          <Button type="button" size="sm" variant="secondary" className="cd-print-actions__btn" onClick={() => onPrint('receipt')}>إيصال الاستلام والتأمين</Button>
        </div>
      ) : null}
    </div>
  )
}
