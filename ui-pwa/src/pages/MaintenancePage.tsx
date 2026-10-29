import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, Fab, DataTable, StatusPill, useToast,
  IconCheck, IconWrench, IconCash, type DataColumn,
} from '@/shared/ui'
import { WorkOrderSheet } from '@/shared/forms/WorkOrderSheet'
import './pages.css'

type WorkOrder = {
  id: number
  status: string
  workshop?: string
  parts_cost: number
  labor_cost: number
  vehicle?: { brand: string; model: string; plate: string }
}

const statusLabel: Record<string, string> = {
  open: 'مفتوح', in_progress: 'قيد التنفيذ', closed: 'مغلق', cancelled: 'ملغى',
}

const columns: DataColumn<WorkOrder>[] = [
  {
    id: 'vehicle',
    header: 'المركبة',
    cell: (r) => (
      <>
        <strong className="rpm-table__primary">{r.vehicle?.brand} {r.vehicle?.model}</strong>
        <span className="rpm-table__sub">{r.vehicle?.plate}</span>
      </>
    ),
    sortValue: (r) => `${r.vehicle?.brand ?? ''} ${r.vehicle?.model ?? ''}`,
    mobile: 'title',
  },
  { id: 'workshop', header: 'الورشة', cell: (r) => r.workshop || '—', sortValue: (r) => r.workshop, mobile: 'subtitle' },
  { id: 'parts', header: 'القطع', cell: (r) => formatMoney(r.parts_cost), sortValue: (r) => Number(r.parts_cost) },
  { id: 'labor', header: 'العمالة', cell: (r) => formatMoney(r.labor_cost), sortValue: (r) => Number(r.labor_cost) },
  {
    id: 'status',
    header: 'الحالة',
    cell: (r) => <StatusPill status={r.status === 'closed' ? 'completed' : 'maintenance'} label={statusLabel[r.status] || r.status} />,
    sortValue: (r) => r.status,
    mobile: 'badge',
  },
]

export function MaintenancePage() {
  const toast = useToast()
  const [rows, setRows] = useState<WorkOrder[]>([])
  const [summary, setSummary] = useState({ open: 0, open_only: 0, in_progress: 0, closed: 0 })
  const [chip, setChip] = useState('all')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'open',
        label: 'مفتوح',
        attention: summary.open_only > 0 ? ('critical' as const) : null,
        attentionLabel: `${summary.open_only} أمر مفتوح`,
      },
      {
        id: 'in_progress',
        label: 'قيد التنفيذ',
        attention: summary.in_progress > 0 ? ('warning' as const) : null,
        attentionLabel: `${summary.in_progress} أمر قيد التنفيذ`,
      },
      { id: 'closed', label: 'مغلق' },
    ],
    [summary.open_only, summary.in_progress],
  )

  async function load() {
    setLoading(true)
    try {
      const qs = new URLSearchParams()
      if (chip !== 'all') qs.set('status', chip)
      const res = await api<{ data: WorkOrder[]; summary: typeof summary }>(`/work-orders?${qs}`)
      const data = res.data.filter((r) => {
        if (!search.trim()) return true
        const q = search.trim().toLowerCase()
        return (
          (r.vehicle?.plate || '').toLowerCase().includes(q)
          || (r.vehicle?.brand || '').toLowerCase().includes(q)
          || (r.vehicle?.model || '').toLowerCase().includes(q)
          || (r.workshop || '').toLowerCase().includes(q)
        )
      })
      setRows(data)
      setSummary({
        open: res.summary.open ?? 0,
        open_only: res.summary.open_only ?? 0,
        in_progress: res.summary.in_progress ?? 0,
        closed: res.summary.closed ?? 0,
      })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => void load(), 200)
    return () => window.clearTimeout(t)
  }, [chip, search])

  async function closeOrder(id: number) {
    try {
      await api(`/work-orders/${id}/close`, { method: 'POST' })
      toast.success('تم إغلاق أمر الشغل.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="أوامر الشغل" subtitle="صرف FIFO بترحيل محاسبي · شراء من العهدة اختياري" action={<Button onClick={() => setOpen(true)}>أمر شغل</Button>} mobileFab />
      <Fab label="أمر شغل" onClick={() => setOpen(true)} />
      <MetricCardGrid
        label="ملخص أوامر الشغل"
        mobile="wrap"
        items={[
          { id: 'open', label: 'أوامر مفتوحة', value: summary.open, tone: 'warning', icon: <IconWrench size={16} /> },
          { id: 'closed', label: 'أوامر مغلقة', value: summary.closed, tone: 'success', icon: <IconCheck size={16} /> },
          {
            id: 'cost', label: 'تكلفة الأوامر المعروضة', format: 'money', icon: <IconCash size={16} />,
            value: rows.reduce((sum, r) => sum + Number(r.parts_cost) + Number(r.labor_cost), 0),
          },
        ]}
      />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث باللوحة أو الورشة…"
      />
      <DataTable
        label="أوامر الشغل"
        rows={rows}
        columns={columns}
        rowKey={(r) => r.id}
        rowLabel={(r) => `أمر شغل · ${r.vehicle?.plate ?? ''}`}
        loading={loading}
        rowActions={(r) =>
          r.status !== 'closed'
            ? [{ id: 'close', label: 'إغلاق أمر الشغل', icon: <IconCheck size={16} />, onSelect: () => void closeOrder(r.id) }]
            : []
        }
        emptyTitle="لا توجد أوامر شغل"
        emptyDescription="افتح أمر شغل لصرف قطع من المخزون أو تسجيل شراء."
      />

      <WorkOrderSheet open={open} onClose={() => setOpen(false)} onSaved={() => void load()} />
    </div>
  )
}
