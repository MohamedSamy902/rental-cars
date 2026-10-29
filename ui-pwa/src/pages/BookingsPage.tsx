import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, StatusPill, Fab, DataTable, RentalProgressBar,
  useToast, ErrorState, IconClipboard, IconAlert, IconCheck, IconKey,
  type DataColumn,
} from '@/shared/ui'
import { useNewContract } from '@/shared/contracts/newContractContext'
import {
  contractMenu, DepositBadge, statusLabel, type Contract,
} from '@/shared/contracts/contractModel'
import { ContractActionSheet } from '@/shared/contracts/ContractActionSheet'
import { useContractLifecycle } from '@/shared/contracts/useContractLifecycle'
import { printContract } from '@/shared/contracts/printContract'
import './pages.css'

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'pending', label: 'معلّق' },
  { id: 'approved', label: 'معتمد' },
  { id: 'active', label: 'نشط' },
  { id: 'completed', label: 'مكتمل' },
]

export function BookingsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [rows, setRows] = useState<Contract[]>([])
  const [summary, setSummary] = useState({ total: 0, pending: 0, approved: 0, active: 0, completed: 0 })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const { openNewContract, createdVersion } = useNewContract()
  const life = useContractLifecycle(() => void load())

  async function load() {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (search.trim()) qs.set('search', search.trim())
      if (chip !== 'all') qs.set('status', chip)
      const res = await api<{ data: Contract[]; summary: typeof summary }>(`/contracts?${qs}`)
      setRows(res.data)
      setSummary(res.summary)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => load(), 250)
    return () => window.clearTimeout(t)
  }, [search, chip, createdVersion])

  const wantNew = searchParams.get('new') === '1'
  useEffect(() => {
    if (!wantNew) return
    openNewContract()
    const next = new URLSearchParams(searchParams)
    next.delete('new')
    setSearchParams(next, { replace: true })
  }, [wantNew, openNewContract, searchParams, setSearchParams])

  const columns: DataColumn<Contract>[] = [
    {
      id: 'number',
      header: 'رقم العقد',
      cell: (b) => <span className="rpm-table__code">{b.number}</span>,
      sortValue: (b) => b.number,
      mobile: 'title',
    },
    {
      id: 'customer',
      header: 'العميل',
      cell: (b) => <strong className="rpm-table__primary">{b.customer?.full_name || '—'}</strong>,
      sortValue: (b) => b.customer?.full_name,
      mobile: 'subtitle',
    },
    {
      id: 'vehicle',
      header: 'المركبة',
      cell: (b) =>
        b.vehicle ? (
          <>
            <span className="rpm-table__primary">{b.vehicle.brand} {b.vehicle.model}</span>
            <span className="rpm-table__sub">{b.vehicle.plate}</span>
          </>
        ) : '—',
      sortValue: (b) => (b.vehicle ? `${b.vehicle.brand} ${b.vehicle.model}` : null),
      wide: true,
    },
    {
      id: 'period',
      header: 'الفترة',
      cell: (b) => (
        <>
          <span className="rpm-table__primary">{formatDateTime(b.starts_at)}</span>
          <span className="rpm-table__sub">حتى {formatDateTime(b.planned_ends_at)}</span>
        </>
      ),
      sortValue: (b) => b.starts_at,
      wide: true,
    },
    {
      id: 'rate',
      header: 'اليومي',
      cell: (b) => formatMoney(b.frozen_daily_rate),
      sortValue: (b) => Number(b.frozen_daily_rate),
    },
    {
      id: 'deposit',
      header: 'التأمين',
      cell: (b) => <DepositBadge deposit={b.deposit} />,
      sortValue: (b) => b.deposit?.status ?? '',
    },
    {
      id: 'status',
      header: 'الحالة',
      cell: (b) => <StatusPill status={b.status} label={statusLabel[b.status] || b.status} />,
      sortValue: (b) => b.status,
      mobile: 'badge',
    },
    {
      id: 'progress',
      header: 'التقدّم',
      cell: (b) => <RentalProgressBar progress={b.progress} compact />,
      sortValue: (b) => b.progress?.percent ?? 0,
      wide: true,
    },
  ]

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العقود والحجوزات"
        subtitle="تمديد = عقد جديد · تبديل عطل = نفس العقد · رصيد دائن عند الإنهاء المبكر"
        action={<Button onClick={() => openNewContract()}>إضافة حجز</Button>}
        mobileFab
      />
      <Fab label="إضافة حجز" onClick={() => openNewContract()} />

      <MetricCardGrid
        label="ملخص العقود"
        items={[
          { id: 'total', label: 'الإجمالي', value: summary.total, icon: <IconClipboard size={16} /> },
          { id: 'pending', label: 'معلّق', value: summary.pending, tone: 'warning', icon: <IconAlert size={16} /> },
          { id: 'active', label: 'نشط', value: summary.active, tone: 'success', icon: <IconKey size={16} /> },
          { id: 'approved', label: 'معتمد', value: summary.approved, tone: 'info', icon: <IconCheck size={16} /> },
        ]}
      />

      <FilterBar search={search} onSearchChange={setSearch} chips={chips} activeChip={chip} onChipChange={setChip} placeholder="بحث برقم العقد أو العميل…" />

      {error ? (
        <ErrorState title="تعذّر تحميل العقود" description={error} action={<Button onClick={() => load()}>إعادة المحاولة</Button>} />
      ) : (
        <DataTable
          label="العقود"
          rows={rows}
          columns={columns}
          rowKey={(b) => b.id}
          rowLabel={(b) => `عقد ${b.number}`}
          loading={loading}
          onRowClick={(b) => navigate(`/contracts/${b.id}`)}
          rowActions={(b) =>
            contractMenu(b, {
              view: () => navigate(`/contracts/${b.id}`),
              approve: () => void life.approve(b.id),
              openAction: (kind) => void life.openAction(kind, b),
              print: () => void printContract(b.id, toast.error),
            })
          }
          emptyTitle="لا توجد عقود مطابقة"
          emptyDescription="غيّر البحث أو الفلتر، أو أضف حجزاً جديداً."
        />
      )}

      <ContractActionSheet
        action={life.action}
        selected={life.selected}
        vehicles={life.vehicles}
        saving={life.saving}
        form={life.actionForm}
        onChange={life.setActionForm}
        onConfirm={() => void life.runAction()}
        onClose={life.closeAction}
      />
    </div>
  )
}
