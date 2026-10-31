import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useAttentionSignals } from '@/shared/lib/useAttentionSignals'
import {
  PageHeader, Button, FilterBar, Sheet, Fab, SelectField, TextField, StatusPill, useToast, DataTable, type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Row = { id: number; category: string; status: string; customer?: { full_name: string; phone: string } }
type Customer = { id: number; full_name: string; phone: string }

const columns: DataColumn<Row>[] = [
  {
    id: 'customer',
    header: 'العميل',
    cell: (r) => <strong className="rpm-table__primary">{r.customer?.full_name || '—'}</strong>,
    sortValue: (r) => r.customer?.full_name,
    mobile: 'title',
  },
  { id: 'phone', header: 'الهاتف', cell: (r) => <span dir="ltr">{r.customer?.phone || '—'}</span>, sortValue: (r) => r.customer?.phone, mobile: 'subtitle' },
  { id: 'category', header: 'الفئة المطلوبة', cell: (r) => r.category, sortValue: (r) => r.category },
  {
    id: 'status',
    header: 'الحالة',
    cell: (r) => <StatusPill status="pending" label={r.status === 'waiting' ? 'منتظر' : r.status} />,
    sortValue: (r) => r.status,
    mobile: 'badge',
  },
]

export function WaitlistPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Row[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [form, setForm] = useState({ customer_id: '', category: 'اقتصادي' })
  const { counts: attention } = useAttentionSignals(true)

  const waitingCount = attention.waiting_requests
  const chips = useMemo(
    () => [
      { id: 'all', label: 'الكل' },
      {
        id: 'waiting',
        label: 'منتظر',
        attention: waitingCount > 0 ? ('warning' as const) : null,
        attentionLabel: `${waitingCount} طلب انتظار قائم`,
      },
    ],
    [waitingCount],
  )
  const visible = useMemo(() => {
    const q = search.trim()
    return rows.filter((r) => {
      if (chip === 'waiting' && r.status !== 'waiting') return false
      if (!q) return true
      const hay = `${r.customer?.full_name ?? ''} ${r.customer?.phone ?? ''} ${r.category}`
      return hay.includes(q)
    })
  }, [rows, chip, search])

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Row[] }>('/waitlist')
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function onSave() {
    try {
      await api('/waitlist', { method: 'POST', json: { customer_id: Number(form.customer_id), category: form.category } })
      toast.success('تم إضافة طلب الانتظار.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="قائمة الانتظار" subtitle="إشعار عند التوفر — بلا إنشاء عقد تلقائي" action={<Button onClick={async () => { setOpen(true); const c = await api<{ data: Customer[] }>('/customers'); setCustomers(c.data) }}>طلب جديد</Button>} mobileFab />
      <Fab label="طلب جديد" onClick={async () => { setOpen(true); const c = await api<{ data: Customer[] }>('/customers'); setCustomers(c.data) }} />
      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالعميل أو الفئة…"
      />
      <DataTable
        label="قائمة الانتظار"
        rows={visible}
        columns={columns}
        rowKey={(r) => r.id}
        loading={loading}
        emptyTitle="لا طلبات انتظار"
        emptyDescription="سجّل طلب عميل لفئة غير متاحة حالياً."
      />
      <Sheet open={open} title="طلب انتظار" onClose={() => setOpen(false)} footer={<><Button onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="العميل" required options={customers.map((c) => ({ value: String(c.id), label: `${c.full_name} · ${c.phone}` }))} value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })} />
          <TextField label="الفئة المطلوبة" required value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
        </div>
      </Sheet>
    </div>
  )
}
