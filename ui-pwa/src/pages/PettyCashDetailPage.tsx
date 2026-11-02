import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatDate } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, Button, BackNavButton, Sheet, TextAreaField, MoneyField,
  useToast, SkeletonBlock, ErrorState, StatusPill, MetricCardGrid, FilterBar, DataTable,
  IconWallet, IconCash, IconTrend, IconReceipt, IconWrench, IconRefresh,
  type DataColumn,
} from '@/shared/ui'
import { ExpenseSheet } from '@/shared/forms/ExpenseSheet'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
import './pages.css'
import './PettyCashDetailPage.css'

type Account = {
  id: number
  employee_id: number
  limit_amount: number
  balance: number
  is_active: boolean
  employee?: { id: number; name: string; job_title?: string | null }
}

type Summary = {
  employee_name?: string | null
  employee_role?: string | null
  limit_amount: number
  total_topup: number
  total_spent: number
  total_returned: number
  balance: number
  is_active: boolean
  ledger_count: number
}

type LedgerRow = {
  id: string
  movement_id?: number | null
  occurred_on: string
  category: 'topup' | 'expense' | 'maintenance' | 'settlement' | string
  direction: 'in' | 'out'
  label: string
  amount: number
  signed_amount: number
  running_balance: number
  notes?: string | null
  expense?: {
    id: number
    title: string
    category?: string | null
    amount: number
    spent_on?: string | null
    vehicle_id?: number | null
    work_order_id?: number | null
  } | null
  work_order?: {
    id: number
    workshop?: string | null
    status?: string | null
    opened_on?: string | null
    vehicle_id?: number | null
  } | null
  vehicle?: {
    id: number
    brand?: string
    model?: string
    plate?: string
    label?: string
  } | null
  parts?: Array<{ id: number; description?: string | null; quantity?: number; amount?: number }>
}

const LedgerTabs = ['all', 'topup', 'expense', 'maintenance', 'settlement'] as const

const categoryLabel: Record<string, string> = {
  topup: 'تغذية',
  expense: 'مصروف',
  maintenance: 'صيانة',
  settlement: 'تسوية',
}

export function PettyCashDetailPage() {
  const { id } = useParams()
  const toast = useToast()
  const [account, setAccount] = useState<Account | null>(null)
  const [summary, setSummary] = useState<Summary | null>(null)
  const [ledger, setLedger] = useState<LedgerRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: LedgerTabs, defaultValue: 'all' })
  const [moveOpen, setMoveOpen] = useState(false)
  const [moveMode, setMoveMode] = useState<'topup' | 'return'>('topup')
  const [saving, setSaving] = useState(false)
  const [move, setMove] = useState({ amount: '', notes: '' })
  const [expenseOpen, setExpenseOpen] = useState(false)
  const [woOpen, setWoOpen] = useState(false)

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const res = await api<{
        data: {
          account: Account
          summary: Summary
          ledger: LedgerRow[]
        }
      }>(`/petty-cash/${id}`)
      setAccount(res.data.account)
      setSummary(res.data.summary)
      setLedger(res.data.ledger || [])
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id])

  const chips = useMemo(() => {
    const counts = {
      all: ledger.length,
      topup: ledger.filter((r) => r.category === 'topup').length,
      expense: ledger.filter((r) => r.category === 'expense').length,
      maintenance: ledger.filter((r) => r.category === 'maintenance').length,
      settlement: ledger.filter((r) => r.category === 'settlement').length,
    }
    return [
      { id: 'all', label: `الكل (${counts.all})` },
      { id: 'topup', label: `تغذية (${counts.topup})` },
      { id: 'expense', label: `مصروفات (${counts.expense})` },
      { id: 'maintenance', label: `صيانة (${counts.maintenance})` },
      { id: 'settlement', label: `تسويات (${counts.settlement})` },
    ]
  }, [ledger])

  const filtered = useMemo(() => {
    const q = search.trim()
    return ledger.filter((r) => {
      if (chip !== 'all' && r.category !== chip) return false
      if (!q) return true
      const hay = `${r.label} ${r.notes || ''} ${r.vehicle?.label || ''} ${r.vehicle?.plate || ''} ${r.expense?.title || ''} ${r.work_order?.workshop || ''}`
      return hay.includes(q)
    })
  }, [ledger, chip, search])

  const columns: DataColumn<LedgerRow>[] = [
    {
      id: 'date',
      header: 'التاريخ',
      cell: (r) => formatDate(r.occurred_on),
      sortValue: (r) => r.occurred_on,
      mobile: 'subtitle',
    },
    {
      id: 'kind',
      header: 'النوع',
      cell: (r) => (
        <span className={`pcd-badge pcd-badge--${r.category}`}>
          {categoryLabel[r.category] || r.category}
        </span>
      ),
      sortValue: (r) => r.category,
      mobile: 'badge',
    },
    {
      id: 'label',
      header: 'البيان',
      cell: (r) => (
        <div className="pcd-ledger-main">
          <strong className="rpm-table__primary">{r.label}</strong>
          {r.expense?.category ? <span className="rpm-table__sub">تصنيف: {r.expense.category}</span> : null}
          {r.parts && r.parts.length > 0 ? (
            <span className="rpm-table__sub">
              قطع: {r.parts.map((p) => p.description || `بند #${p.id}`).join(' · ')}
            </span>
          ) : null}
          {r.notes ? <span className="rpm-table__sub">{r.notes}</span> : null}
        </div>
      ),
      sortValue: (r) => r.label,
      mobile: 'title',
    },
    {
      id: 'vehicle',
      header: 'العربية',
      cell: (r) => (
        r.vehicle ? (
          <Link className="pcd-link" to={`/vehicles/${r.vehicle.id}`}>
            {r.vehicle.label || `${r.vehicle.brand} ${r.vehicle.model} · ${r.vehicle.plate}`}
          </Link>
        ) : '—'
      ),
      sortValue: (r) => r.vehicle?.plate,
    },
    {
      id: 'amount',
      header: 'المبلغ',
      cell: (r) => (
        <strong className={r.direction === 'in' ? 'pcd-amt pcd-amt--in' : 'pcd-amt pcd-amt--out'}>
          {r.direction === 'in' ? '+' : '−'}{formatMoney(r.amount)}
        </strong>
      ),
      sortValue: (r) => Number(r.signed_amount),
    },
    {
      id: 'balance',
      header: 'الرصيد بعد الحركة',
      cell: (r) => <span className="tabular-nums">{formatMoney(r.running_balance)}</span>,
      sortValue: (r) => Number(r.running_balance),
    },
    {
      id: 'links',
      header: 'الربط',
      cell: (r) => (
        <div className="pcd-links">
          {r.expense ? <Link className="pcd-link" to="/expenses">مصروف #{r.expense.id}</Link> : null}
          {r.work_order ? (
            <Link className="pcd-link" to={r.work_order.vehicle_id ? `/vehicles/${r.work_order.vehicle_id}?tab=maintenance` : '/maintenance'}>
              أمر شغل #{r.work_order.id}
            </Link>
          ) : null}
          {!r.expense && !r.work_order ? '—' : null}
        </div>
      ),
    },
  ]

  async function saveMove() {
    if (!account) return
    setSaving(true)
    try {
      await api(`/petty-cash/${account.id}/move`, {
        method: 'POST',
        json: {
          type: moveMode,
          amount: Number(move.amount),
          notes: move.notes || (moveMode === 'topup' ? 'تغذية عهدة' : 'رد / تسوية عهدة'),
        },
      })
      toast.success(moveMode === 'topup' ? 'تم تغذية العهدة.' : 'تم تسجيل التسوية.')
      setMoveOpen(false)
      setMove({ amount: '', notes: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="rpm-stack"><SkeletonBlock rows={8} /></div>
  if (error || !account || !summary) {
    return (
      <div className="rpm-stack">
        <ErrorState
          title="تعذّر تحميل العهدة"
          description={error || 'غير موجودة'}
          action={<BackNavButton to="/petty-cash" label="رجوع للعهدة" />}
        />
      </div>
    )
  }

  const employeeName = summary.employee_name || account.employee?.name || `موظف #${account.employee_id}`
  const employeeRole = summary.employee_role || account.employee?.job_title || '—'

  return (
    <div className="rpm-stack pcd">
      <PageHeader
        title={`عهدة · ${employeeName}`}
        subtitle={`${employeeRole} · سجل كامل لحركات الدخول والخروج`}
        action={
          <>
            <BackNavButton to="/petty-cash" label="رجوع للعهدة" />
            <Button
              variant="secondary"
              iconStart={<IconRefresh size={16} />}
              onClick={() => { setMoveMode('topup'); setMove({ amount: '', notes: '' }); setMoveOpen(true) }}
            >
              تغذية العهدة
            </Button>
            <Button
              variant="secondary"
              onClick={() => { setMoveMode('return'); setMove({ amount: '', notes: '' }); setMoveOpen(true) }}
            >
              تسوية / رد مبلغ
            </Button>
            <Button variant="accent" onClick={() => setExpenseOpen(true)}>مصروف من العهدة</Button>
            <Button onClick={() => setWoOpen(true)}>صيانة من العهدة</Button>
          </>
        }
      />

      <div className="pcd-identity">
        <div>
          <p className="pcd-identity__role">{employeeRole}</p>
          <h2>{employeeName}</h2>
        </div>
        <StatusPill
          status={account.is_active ? 'active' : 'cancelled'}
          label={account.is_active ? 'عهدة نشطة' : 'موقوفة'}
        />
      </div>

      <MetricCardGrid
        label="ملخص العهدة"
        items={[
          { id: 'limit', label: 'حد العهدة', value: summary.limit_amount, format: 'money', icon: <IconWallet size={16} /> },
          { id: 'topup', label: 'إجمالي المستلم / التغذية', value: summary.total_topup, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'spent', label: 'إجمالي المنصرف', value: summary.total_spent, format: 'money', tone: 'warning', icon: <IconReceipt size={16} /> },
          { id: 'returned', label: 'إجمالي المرتجع / التسويات', value: summary.total_returned, format: 'money', tone: 'info', icon: <IconTrend size={16} /> },
          { id: 'balance', label: 'الرصيد المتبقي الحالي', value: summary.balance, format: 'money', tone: summary.balance > 0 ? 'success' : 'warning', icon: <IconWrench size={16} /> },
        ]}
      />

      <section className="rpm-panel pcd-ledger">
        <div className="rpm-panel__head">
          <h3>سجل حركات العهدة الكامل</h3>
          <span className="muted">{summary.ledger_count} حركة</span>
        </div>
        <FilterBar
          search={search}
          onSearchChange={setSearch}
          chips={chips}
          activeChip={chip}
          onChipChange={setChip}
          placeholder="بحث بالبيان أو اللوحة أو الورشة…"
        />
        <DataTable
          label="حركات العهدة"
          rows={filtered}
          columns={columns}
          rowKey={(r) => r.id}
          rowLabel={(r) => r.label}
          defaultSort={{ id: 'date', dir: 'desc' }}
          emptyTitle="لا حركات في هذا الفلتر"
          emptyDescription="سجّل تغذية أو مصروفًا أو أمر شغل من العهدة ليظهر هنا."
        />
      </section>

      <Sheet
        open={moveOpen}
        title={moveMode === 'topup' ? 'تغذية العهدة' : 'تسوية / رد مبلغ'}
        onClose={() => setMoveOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={() => void saveMove()}>تأكيد</Button>
            <Button variant="secondary" onClick={() => setMoveOpen(false)}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <MoneyField
            label="المبلغ"
            required
            value={move.amount}
            onChange={(e) => setMove({ ...move, amount: e.target.value })}
            hint={moveMode === 'return' ? 'يُخصم من الرصيد الحالي كمرتجع/تسوية' : 'يُضاف إلى رصيد العهدة'}
          />
          <TextAreaField label="ملاحظات" value={move.notes} onChange={(e) => setMove({ ...move, notes: e.target.value })} />
        </div>
      </Sheet>

      <ExpenseSheet
        open={expenseOpen}
        custodyEmployee={{ id: account.employee_id, name: employeeName }}
        onClose={() => setExpenseOpen(false)}
        onSaved={() => void load()}
      />
      <WorkOrderSheet
        open={woOpen}
        custodyEmployee={{ id: account.employee_id, name: employeeName }}
        onClose={() => setWoOpen(false)}
        onSaved={() => void load()}
      />
    </div>
  )
}
