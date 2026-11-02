import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatDate } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, TextAreaField, SelectField, MoneyField,
  useToast, SkeletonBlock, ErrorState, StatusPill, DetailList,
} from '@/shared/ui'
import './pages.css'

type Account = {
  id: number
  employee_id: number
  limit_amount: number
  balance: number
  is_active: boolean
  employee?: { id: number; name: string; job_title?: string }
}

type Movement = {
  id: number
  type: string
  amount: number
  moved_on: string
  notes?: string | null
}

type ExpenseRow = { id: number; title: string; amount: number; spent_on: string; category?: string }
type WoLine = {
  id: number
  description?: string
  petty_cash_amount?: number
  work_order?: { id: number; workshop?: string; opened_on?: string; status?: string }
}

const typeLabel: Record<string, string> = {
  topup: 'تسليم / تعزيز',
  spend: 'استخدام',
  adjust: 'تسوية رصيد',
}

export function PettyCashDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const [account, setAccount] = useState<Account | null>(null)
  const [movements, setMovements] = useState<Movement[]>([])
  const [expenses, setExpenses] = useState<ExpenseRow[]>([])
  const [workOrders, setWorkOrders] = useState<WoLine[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [moveOpen, setMoveOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [move, setMove] = useState({ type: 'topup', amount: '', notes: '' })

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const res = await api<{
        data: {
          account: Account
          movements: Movement[]
          linked_expenses: ExpenseRow[]
          linked_work_orders: WoLine[]
        }
      }>(`/petty-cash/${id}`)
      setAccount(res.data.account)
      setMovements(res.data.movements || [])
      setExpenses(res.data.linked_expenses || [])
      setWorkOrders(res.data.linked_work_orders || [])
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id])

  async function saveMove() {
    if (!account) return
    setSaving(true)
    try {
      await api(`/petty-cash/${account.id}/move`, {
        method: 'POST',
        json: { type: move.type, amount: Number(move.amount), notes: move.notes || undefined },
      })
      toast.success('تم تسجيل حركة العهدة.')
      setMoveOpen(false)
      setMove({ type: 'topup', amount: '', notes: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !account) {
    return (
      <div className="rpm-stack">
        <ErrorState title="تعذّر تحميل العهدة" description={error || 'غير موجودة'} action={<Button onClick={() => navigate('/petty-cash')}>رجوع</Button>} />
      </div>
    )
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title={`عهدة · ${account.employee?.name || `#${account.employee_id}`}`}
        subtitle={account.employee?.job_title || 'تفاصيل العهدة النقدية وسجل الحركات'}
        action={
          <>
            <Button variant="secondary" onClick={() => navigate('/petty-cash')}>رجوع للقائمة</Button>
            <Button onClick={() => { setMove({ type: 'topup', amount: '', notes: '' }); setMoveOpen(true) }}>تعزيز / تسوية</Button>
          </>
        }
      />

      <DetailList
        rows={[
          { label: 'الموظف', value: account.employee?.name || `موظف #${account.employee_id}` },
          { label: 'الوظيفة', value: account.employee?.job_title || '—' },
          { label: 'الرصيد الحالي', value: <strong>{formatMoney(account.balance)}</strong> },
          { label: 'حد العهدة', value: formatMoney(account.limit_amount) },
          { label: 'الحالة', value: <StatusPill status={account.is_active ? 'active' : 'cancelled'} label={account.is_active ? 'نشطة' : 'موقوفة'} /> },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>سجل الحركات</h3>
          <Button size="sm" variant="secondary" onClick={() => { setMove({ type: 'spend', amount: '', notes: '' }); setMoveOpen(true) }}>تسجيل استخدام</Button>
        </div>
        {movements.length === 0 ? <p className="muted">لا حركات بعد.</p> : (
          <div className="rpm-card-list">
            {movements.map((m) => (
              <article key={m.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{typeLabel[m.type] || m.type}</strong>
                  <strong className="tabular-nums">{formatMoney(m.amount)}</strong>
                </div>
                <p className="muted">{formatDate(m.moved_on)}{m.notes ? ` · ${m.notes}` : ''}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>مصروفات مرتبطة بالعهدة</h3></div>
        {expenses.length === 0 ? <p className="muted">لا مصروفات مرتبطة.</p> : (
          <div className="rpm-card-list">
            {expenses.map((e) => (
              <article key={e.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{e.title}</strong>
                  <strong className="tabular-nums">{formatMoney(e.amount)}</strong>
                </div>
                <p className="muted">{formatDate(e.spent_on)} · {e.category || '—'}</p>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>صيانة صُرفت من العهدة</h3></div>
        {workOrders.length === 0 ? <p className="muted">لا أوامر شغل مرتبطة.</p> : (
          <div className="rpm-card-list">
            {workOrders.map((w) => (
              <article key={w.id} className="rpm-card">
                <div className="rpm-card__row">
                  <strong>{w.description || `بند #${w.id}`}</strong>
                  <strong className="tabular-nums">{formatMoney(w.petty_cash_amount || 0)}</strong>
                </div>
                <p className="muted">
                  أمر #{w.work_order?.id || '—'} · {w.work_order?.workshop || '—'} · {formatDate(w.work_order?.opened_on)}
                </p>
              </article>
            ))}
          </div>
        )}
      </section>

      <p className="muted"><Link to="/petty-cash">← العودة لقائمة العهد</Link></p>

      <Sheet
        open={moveOpen}
        title="حركة عهدة"
        onClose={() => setMoveOpen(false)}
        footer={<><Button loading={saving} onClick={() => void saveMove()}>تأكيد</Button><Button variant="secondary" onClick={() => setMoveOpen(false)}>إلغاء</Button></>}
      >
        <div className="rpm-form-grid">
          <SelectField
            label="نوع الحركة"
            required
            value={move.type}
            onChange={(e) => setMove({ ...move, type: e.target.value })}
            options={[
              { value: 'topup', label: 'تسليم / تعزيز' },
              { value: 'spend', label: 'استخدام' },
              { value: 'adjust', label: 'تسوية رصيد' },
            ]}
          />
          <MoneyField label="المبلغ" required value={move.amount} onChange={(e) => setMove({ ...move, amount: e.target.value })} />
          <TextAreaField label="ملاحظات" value={move.notes} onChange={(e) => setMove({ ...move, notes: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
