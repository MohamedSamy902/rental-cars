import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { useQueryTab } from '@/shared/lib/useQueryTab'
import { useAttentionSignals } from '@/shared/lib/useAttentionSignals'
import { useAuth } from '@/shared/auth/AuthContext'
import {
  PageHeader, MetricCardGrid, FilterBar, Button, StatusPill, Fab, DataTable, RentalProgressBar,
  useToast, ErrorState, IconClipboard, IconAlert, IconCheck, IconKey, PhoneActions,
  type DataColumn,
} from '@/shared/ui'
import { useNewContract } from '@/shared/contracts/newContractContext'
import {
  contractMenu, DepositBadge, statusLabel, type Contract,
} from '@/shared/contracts/contractModel'
import { ContractActionSheet } from '@/shared/contracts/ContractActionSheet'
import { useContractLifecycle } from '@/shared/contracts/useContractLifecycle'
import { printContractDocument } from '@/shared/contracts/printContract'
import './pages.css'

const BookingsPageTabs = ['all', 'handover_today', 'upcoming', 'pending', 'approved', 'active', 'completed'] as const

type PageMeta = { current_page: number; last_page: number; total: number }
const LIST_PER_PAGE = 50

export function BookingsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [rows, setRows] = useState<Contract[]>([])
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<PageMeta>({ current_page: 1, last_page: 1, total: 0 })
  const [summary, setSummary] = useState({
    total: 0, pending: 0, approved: 0, active: 0, completed: 0, upcoming: 0, handover_today: 0,
  })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useQueryTab({ allowed: BookingsPageTabs, defaultValue: 'all' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const { openNewContract, createdVersion } = useNewContract()
  const life = useContractLifecycle(() => void load(page))
  const { counts: attention } = useAttentionSignals(true)

  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'handover_today',
        label: 'تسليم العربية',
        attention: summary.handover_today > 0 ? ('warning' as const) : null,
        attentionLabel: 'تسليمات اليوم',
      },
      {
        id: 'upcoming',
        label: 'الحجوزات القادمة',
        attention: summary.upcoming > 0 ? ('warning' as const) : null,
        attentionLabel: 'حجوزات مستقبلية',
      },
      {
        id: 'pending',
        label: 'معلّق',
        attention: summary.pending > 0 ? ('warning' as const) : null,
        attentionLabel: 'اعتماد معلّق',
      },
      {
        id: 'approved',
        label: 'معتمد',
        attention: attention.pickups_today > 0 ? ('warning' as const) : null,
        attentionLabel: 'تسليمات اليوم',
      },
      {
        id: 'active',
        label: 'نشط',
        attention:
          attention.overdue_returns > 0
            ? ('critical' as const)
            : attention.returning_today > 0
              ? ('warning' as const)
              : null,
        attentionLabel: attention.overdue_returns > 0 ? 'عقود متأخرة' : 'استلامات اليوم',
      },
      { id: 'completed', label: 'مكتمل' },
    ],
    [summary.pending, summary.upcoming, summary.handover_today, attention.pickups_today, attention.overdue_returns, attention.returning_today],
  )

  async function load(nextPage = page) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      qs.set('page', String(nextPage))
      qs.set('per_page', String(LIST_PER_PAGE))
      if (search.trim()) qs.set('search', search.trim())
      if (chip === 'upcoming' || chip === 'handover_today') {
        qs.set('tab', chip)
      } else if (chip !== 'all') {
        qs.set('status', chip)
      }
      const res = await api<{ data: Contract[]; summary: typeof summary; meta?: PageMeta }>(`/contracts?${qs}`)
      setRows(res.data)
      setMeta({
        current_page: res.meta?.current_page ?? nextPage,
        last_page: res.meta?.last_page ?? 1,
        total: res.meta?.total ?? res.data.length,
      })
      setPage(res.meta?.current_page ?? nextPage)
      setSummary({
        total: res.summary.total ?? 0,
        pending: res.summary.pending ?? 0,
        approved: res.summary.approved ?? 0,
        active: res.summary.active ?? 0,
        completed: res.summary.completed ?? 0,
        upcoming: res.summary.upcoming ?? 0,
        handover_today: res.summary.handover_today ?? 0,
      })
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(() => void load(page), 250)
    return () => window.clearTimeout(t)
  }, [search, chip, createdVersion, page])

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
      cell: (b) => (
        <div>
          <strong className="rpm-table__primary">{b.customer?.full_name || '—'}</strong>
          {b.customer?.phone ? (
            <div className="rpm-table__sub">
              <PhoneActions phone={b.customer.phone} name={b.customer.full_name} compact />
            </div>
          ) : null}
        </div>
      ),
      sortValue: (b) => b.customer?.full_name,
      mobile: 'subtitle',
    },
    {
      id: 'vehicle',
      header: 'العربية',
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
          <span className="rpm-table__primary">{formatDate(b.starts_at)}</span>
          <span className="rpm-table__sub">حتى {formatDate(b.planned_ends_at)}</span>
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
      cell: (b) => <DepositBadge deposit={b.deposit} agreedAmount={b.frozen_agreed_deposit} />,
      sortValue: (b) => b.deposit?.original_amount ?? b.frozen_agreed_deposit ?? 0,
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
        subtitle="تمديد = عقد جديد · تبديل عطل = عقد جديد · بلا رصيد دائن"
        action={<Button onClick={() => openNewContract()}>عقد جديد</Button>}
        mobileFab
      />
      <Fab label="عقد جديد" onClick={() => openNewContract()} />

      <MetricCardGrid
        label="ملخص العقود"
        items={[
          { id: 'total', label: 'الإجمالي', value: summary.total, icon: <IconClipboard size={16} /> },
          { id: 'pending', label: 'معلّق', value: summary.pending, tone: 'warning', icon: <IconAlert size={16} /> },
          { id: 'active', label: 'نشط', value: summary.active, tone: 'success', icon: <IconKey size={16} /> },
          { id: 'approved', label: 'معتمد', value: summary.approved, tone: 'info', icon: <IconCheck size={16} /> },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={(v) => { setSearch(v); setPage(1) }}
        chips={chips}
        activeChip={chip}
        onChipChange={(id) => { setChip(id); setPage(1) }}
        placeholder="بحث برقم العقد أو العميل…"
      />

      {error ? (
        <ErrorState title="تعذّر تحميل العقود" description={error} action={<Button onClick={() => void load(page)}>إعادة المحاولة</Button>} />
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
              print: (kind = 'contract') => void printContractDocument(b.id, kind, toast.error),
              can,
            })
          }
          emptyTitle="لا توجد عقود مطابقة"
          emptyDescription="غيّر البحث أو الفلتر، أو أضف حجزاً جديداً."
          serverPagination={{
            page: meta.current_page,
            lastPage: meta.last_page,
            total: meta.total,
            perPage: LIST_PER_PAGE,
            onPageChange: setPage,
          }}
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
