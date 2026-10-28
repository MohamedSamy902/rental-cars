import { Button } from '@/shared/ui'
import { type ActionKind, type Contract } from './contractModel'

type Props = {
  contract: Contract
  onApprove: () => void
  onAction: (kind: Exclude<ActionKind, null>) => void
  onPrint: () => void
}

export function ContractActionsBar({ contract: b, onApprove, onAction, onPrint }: Props) {
  return (
    <div className="rpm-card__actions">
      {b.status === 'pending' ? (
        <>
          <Button type="button" size="sm" onClick={onApprove}>اعتماد</Button>
          <Button type="button" size="sm" variant="danger" onClick={() => onAction('cancel')}>إلغاء العقد</Button>
        </>
      ) : null}
      {b.status === 'approved' ? (
        <>
          <Button type="button" size="sm" onClick={() => onAction('handover')}>تسليم</Button>
          {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deposit')}>قبض تأمين</Button> : null}
          <Button type="button" size="sm" variant="danger" onClick={() => onAction('cancel')}>إلغاء العقد</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button>
        </>
      ) : null}
      {b.status === 'active' ? (
        <>
          <Button type="button" size="sm" onClick={() => onAction('return')}>استلام</Button>
          {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deposit')}>قبض تأمين</Button> : null}
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('swap')}>تبديل عطل</Button>
        </>
      ) : null}
      {b.status === 'completed' ? (
        <Button type="button" size="sm" variant="secondary" onClick={() => onAction('extend')}>تمديد</Button>
      ) : null}
      {b.deposit && b.deposit.status !== 'returned' ? (
        <>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('deduct')}>خصم تأمين</Button>
          <Button type="button" size="sm" variant="secondary" onClick={() => onAction('refund')}>رد تأمين</Button>
        </>
      ) : null}
      <Button type="button" size="sm" variant="secondary" onClick={onPrint}>طباعة</Button>
    </div>
  )
}
