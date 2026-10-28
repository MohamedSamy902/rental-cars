import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatDateTime } from '@/shared/lib/format'
import {
  PageHeader, Button, Fab, MetricCardGrid, useToast, DataTable, Badge,
  IconReceipt, IconCalendar, IconWallet, IconChart, type DataColumn,
} from '@/shared/ui'
import { ExpenseSheet } from '@/shared/forms/ExpenseSheet'
import './pages.css'

type Expense = {
  id: number
  title: string
  category: string
  amount: number
  spent_on: string
  payment_method: string
  from_petty_cash: boolean
  notes?: string | null
}

const methodLabel: Record<string, string> = { cash: 'نقدي', transfer: 'تحويل', card: 'بطاقة' }

const columns: DataColumn<Expense>[] = [
  {
    id: 'title',
    header: 'البند',
    cell: (r) => (
      <>
        <strong className="rpm-table__primary">{r.title}</strong>
        {r.notes ? <span className="rpm-table__sub">{r.notes}</span> : null}
      </>
    ),
    sortValue: (r) => r.title,
    mobile: 'title',
  },
  { id: 'date', header: 'التاريخ', cell: (r) => formatDateTime(r.spent_on).split('،')[0] || r.spent_on.slice(0, 10), sortValue: (r) => r.spent_on, mobile: 'subtitle' },
  { id: 'category', header: 'التصنيف', cell: (r) => <Badge tone="muted" dot={false}>{r.category}</Badge>, sortValue: (r) => r.category },
  {
    id: 'method',
    header: 'الدفع',
    cell: (r) => (r.from_petty_cash ? 'من العهدة' : methodLabel[r.payment_method] || r.payment_method),
    sortValue: (r) => r.payment_method,
  },
  { id: 'amount', header: 'المبلغ', cell: (r) => <strong>{formatMoney(r.amount)}</strong>, sortValue: (r) => Number(r.amount), mobile: 'badge' },
]

export function ExpensesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Expense[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Expense[] }>('/expenses')
      setRows(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  const stats = useMemo(() => {
    const ym = new Date().toISOString().slice(0, 7)
    const month = rows.filter((r) => r.spent_on?.startsWith(ym))
    const byCategory = new Map<string, number>()
    for (const r of rows) byCategory.set(r.category, (byCategory.get(r.category) ?? 0) + Number(r.amount))
    const top = [...byCategory.entries()].sort((a, b) => b[1] - a[1])[0]
    return {
      monthTotal: month.reduce((s, r) => s + Number(r.amount), 0),
      monthCount: month.length,
      total: rows.reduce((s, r) => s + Number(r.amount), 0),
      petty: rows.filter((r) => r.from_petty_cash).reduce((s, r) => s + Number(r.amount), 0),
      top,
    }
  }, [rows])

  return (
    <div className="rpm-stack">
      <PageHeader title="المصروفات" subtitle="تشغيل · صيانة · أخرى مع ترحيل محاسبي" action={<Button onClick={() => setOpen(true)}>مصروف جديد</Button>} mobileFab />
      <Fab label="مصروف جديد" onClick={() => setOpen(true)} />

      <MetricCardGrid
        label="ملخص المصروفات"
        items={[
          { id: 'month', label: 'مصروفات هذا الشهر', value: stats.monthTotal, format: 'money', tone: 'warning', icon: <IconCalendar size={16} />, hint: `${stats.monthCount} حركة` },
          { id: 'total', label: 'إجمالي المسجّل', value: stats.total, format: 'money', icon: <IconReceipt size={16} />, hint: `${rows.length} بند` },
          { id: 'petty', label: 'من العهدة', value: stats.petty, format: 'money', tone: 'info', icon: <IconWallet size={16} /> },
          {
            id: 'top', label: 'أعلى تصنيف', value: stats.top ? stats.top[0] : '—', icon: <IconChart size={16} />,
            hint: stats.top ? formatMoney(stats.top[1]) : undefined,
          },
        ]}
      />

      <DataTable
        label="المصروفات"
        rows={rows}
        columns={columns}
        rowKey={(r) => r.id}
        loading={loading}
        defaultSort={{ id: 'date', dir: 'desc' }}
        emptyTitle="لا مصروفات بعد"
        emptyDescription="سجّل أول مصروف ليظهر هنا مع ترحيله المحاسبي."
      />
      <ExpenseSheet open={open} onClose={() => setOpen(false)} onSaved={() => void load()} />
    </div>
  )
}
