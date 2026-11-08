import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { currentCivilMonth } from '@/shared/lib/dates'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import {
  PageHeader, Button, Fab, FilterBar, MetricCardGrid, useToast, DataTable, Badge,
  IconReceipt, IconCalendar, IconWallet, IconChart, IconEdit, type DataColumn,
} from '@/shared/ui'
import { ExpenseSheet, type ExpenseEditInitial } from '@/shared/forms/ExpenseSheet'
import './pages.css'

type Expense = ExpenseEditInitial & {
  category: string
  payment_method: string
  from_petty_cash: boolean
  recorded_by_name?: string | null
  scope?: 'vehicle' | 'general' | string | null
}

const methodLabel: Record<string, string> = { cash: 'نقدي', transfer: 'تحويل', card: 'بطاقة', petty_cash: 'عهدة' }
const ExpensesPageTabs = ['all', 'vehicle', 'general'] as const

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
  { id: 'date', header: 'التاريخ', cell: (r) => formatDate(r.spent_on), sortValue: (r) => r.spent_on, mobile: 'subtitle' },
  {
    id: 'scope',
    header: 'النطاق',
    cell: (r) => (
      <Badge tone={r.vehicle_id ? 'brand' : 'muted'} dot={false}>
        {r.vehicle_id
          ? (r.vehicle ? `${r.vehicle.plate || r.vehicle.brand || 'عربية'}` : 'عربية')
          : 'عام / فرع'}
      </Badge>
    ),
    sortValue: (r) => (r.vehicle_id ? 1 : 0),
  },
  { id: 'category', header: 'التصنيف', cell: (r) => <Badge tone="muted" dot={false}>{r.category}</Badge>, sortValue: (r) => r.category },
  {
    id: 'who',
    header: 'من سجّل',
    cell: (r) => r.recorded_by_name || '—',
    sortValue: (r) => r.recorded_by_name || '',
  },
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
  const [editing, setEditing] = useState<ExpenseEditInitial | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [chip, setChip] = useQueryTab({ allowed: ExpensesPageTabs, defaultValue: 'all' })

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      { id: 'vehicle', label: 'عربيات' },
      { id: 'general', label: 'عام / فرع' },
    ],
    [],
  )

  async function load(nextPage = page) {
    setLoading(true)
    try {
      const qs = new URLSearchParams()
      qs.set('page', String(nextPage))
      qs.set('per_page', '50')
      if (chip === 'general' || chip === 'vehicle') qs.set('scope', chip)
      const res = await api<{ data: Expense[]; meta?: { current_page: number; last_page: number; total: number } }>(`/expenses?${qs}`)
      setRows(res.data)
      setMeta({
        current_page: res.meta?.current_page ?? nextPage,
        last_page: res.meta?.last_page ?? 1,
        total: res.meta?.total ?? res.data.length,
      })
      setPage(res.meta?.current_page ?? nextPage)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load(page) }, [chip, page])

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return rows
    return rows.filter((r) => {
      const hay = [
        r.title,
        r.category,
        r.notes,
        r.recorded_by_name,
        r.vehicle?.plate,
        r.vehicle?.brand,
        r.vehicle?.model,
      ].filter(Boolean).join(' ').toLowerCase()
      return hay.includes(q)
    })
  }, [rows, search])

  const stats = useMemo(() => {
    const ym = currentCivilMonth()
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

  function openCreate() {
    setEditing(null)
    setOpen(true)
  }

  function openEdit(row: Expense) {
    setEditing(row)
    setOpen(true)
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="المصروفات" subtitle="تشغيل · فرع · صيانة · مع تتبّع من دفع ومتى" action={<Button onClick={openCreate}>مصروف جديد</Button>} mobileFab />
      <Fab label="مصروف جديد" onClick={openCreate} />

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

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        placeholder="بحث بالبند أو من سجّل أو اللوحة…"
        chips={chips}
        activeChip={chip}
        onChipChange={(id) => { setChip(id); setPage(1) }}
      />

      <DataTable
        label="المصروفات"
        rows={visible}
        columns={columns}
        rowKey={(r) => r.id}
        loading={loading}
        defaultSort={{ id: 'date', dir: 'desc' }}
        rowActions={(r) => [
          { id: 'edit', label: 'تعديل', icon: <IconEdit size={16} />, onSelect: () => openEdit(r) },
        ]}
        emptyTitle="لا مصروفات بعد"
        emptyDescription="سجّل مصروفاً عاماً للفرع أو مرتبطاً بعربية ليظهر هنا."
        serverPagination={{
          page: meta.current_page,
          lastPage: meta.last_page,
          total: meta.total,
          perPage: 50,
          onPageChange: setPage,
        }}
      />
      <ExpenseSheet
        open={open}
        initial={editing}
        onClose={() => { setOpen(false); setEditing(null) }}
        onSaved={() => void load()}
      />
    </div>
  )
}
