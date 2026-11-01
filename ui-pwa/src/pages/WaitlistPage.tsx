import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate } from '@/shared/lib/format'
import { useAttentionSignals } from '@/shared/lib/useAttentionSignals'
import {
  PageHeader, Button, FilterBar, Sheet, Fab, SelectField, TextAreaField, NumberField, DateField,
  StatusPill, useToast, DataTable, MetricCardGrid, type DataColumn,
  IconQueue, IconUsers, IconCalendar, IconAlert,
} from '@/shared/ui'
import './pages.css'
import './WaitlistPage.css'

type Row = {
  id: number
  category: string
  status: string
  priority?: number | null
  desired_start?: string | null
  desired_end?: string | null
  notes?: string | null
  expires_at?: string | null
  created_at?: string | null
  preferred_vehicle_id?: number | null
  customer?: { id: number; full_name: string; phone: string }
  preferred_vehicle?: { id: number; brand: string; model: string; plate: string } | null
}
type Customer = { id: number; full_name: string; phone: string }
type VehicleOption = { id: number; brand: string; model: string; plate: string }

const statusMeta: Record<string, { label: string; tone: 'warning' | 'success' | 'muted' | 'danger' | 'steel' }> = {
  waiting: { label: 'منتظر', tone: 'warning' },
  notified: { label: 'تم الإشعار', tone: 'steel' },
  converted: { label: 'تحوّل لعقد', tone: 'success' },
  expired: { label: 'منتهٍ', tone: 'muted' },
  cancelled: { label: 'ملغى', tone: 'danger' },
}

const emptyForm = {
  customer_id: '',
  category: 'اقتصادي',
  preferred_vehicle_id: '',
  desired_start: '',
  desired_end: '',
  priority: '50',
  notes: '',
}

export function WaitlistPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Row[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [vehicles, setVehicles] = useState<VehicleOption[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [form, setForm] = useState(emptyForm)
  const { counts: attention } = useAttentionSignals(true)

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Row[] }>('/waitlist')
      const sorted = [...(res.data || [])].sort((a, b) => {
        const aKey = a.desired_start || a.created_at || ''
        const bKey = b.desired_start || b.created_at || ''
        return aKey.localeCompare(bKey)
      })
      setRows(sorted)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function openSheet() {
    setForm(emptyForm)
    setOpen(true)
    const [c, v] = await Promise.all([
      api<{ data: Customer[] }>('/customers?per_page=100'),
      api<{ data: VehicleOption[] }>('/vehicles?per_page=100').catch(() => ({ data: [] as VehicleOption[] })),
    ])
    setCustomers(c.data)
    setVehicles(v.data)
  }

  async function onSave() {
    setSaving(true)
    try {
      await api('/waitlist', {
        method: 'POST',
        json: {
          customer_id: Number(form.customer_id),
          category: form.category,
          preferred_vehicle_id: form.preferred_vehicle_id ? Number(form.preferred_vehicle_id) : undefined,
          desired_start: form.desired_start || undefined,
          desired_end: form.desired_end || undefined,
          priority: form.priority !== '' ? Number(form.priority) : undefined,
          notes: form.notes || undefined,
        },
      })
      toast.success('تم إضافة طلب الانتظار.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const summary = useMemo(() => {
    const waiting = rows.filter((r) => r.status === 'waiting').length
    const soon = rows.filter((r) => r.desired_start && r.desired_start <= new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10)).length
    return { total: rows.length, waiting, soon }
  }, [rows])

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
      const hay = `${r.customer?.full_name ?? ''} ${r.customer?.phone ?? ''} ${r.category} ${r.notes ?? ''}`
      return hay.includes(q)
    })
  }, [rows, chip, search])

  const columns: DataColumn<Row>[] = [
    {
      id: 'when',
      header: 'الفترة المطلوبة',
      cell: (r) => (
        <>
          <span className="rpm-table__primary">{r.desired_start ? formatDate(r.desired_start) : '—'}</span>
          <span className="rpm-table__sub">
            {r.desired_end ? `حتى ${formatDate(r.desired_end)}` : 'بدون نهاية محددة'}
          </span>
        </>
      ),
      sortValue: (r) => r.desired_start || r.created_at || '',
      mobile: 'subtitle',
      wide: true,
    },
    {
      id: 'customer',
      header: 'العميل',
      cell: (r) => (
        <>
          <strong className="rpm-table__primary">{r.customer?.full_name || '—'}</strong>
          <span className="rpm-table__sub" dir="ltr">{r.customer?.phone || ''}</span>
        </>
      ),
      sortValue: (r) => r.customer?.full_name,
      mobile: 'title',
    },
    {
      id: 'category',
      header: 'الفئة / العربية',
      cell: (r) => (
        <>
          <span className="rpm-table__primary">{r.category}</span>
          <span className="rpm-table__sub">
            {r.preferred_vehicle
              ? `${r.preferred_vehicle.brand} ${r.preferred_vehicle.model} · ${r.preferred_vehicle.plate}`
              : 'أي عربية من الفئة'}
          </span>
        </>
      ),
      sortValue: (r) => r.category,
      wide: true,
    },
    {
      id: 'priority',
      header: 'الأولوية',
      cell: (r) => <span className="tabular-nums">{r.priority ?? 50}</span>,
      sortValue: (r) => r.priority ?? 50,
    },
    {
      id: 'status',
      header: 'الحالة',
      cell: (r) => {
        const meta = statusMeta[r.status] || { label: r.status, tone: 'muted' as const }
        return <StatusPill status={r.status} tone={meta.tone} label={meta.label} />
      },
      sortValue: (r) => r.status,
      mobile: 'badge',
    },
    {
      id: 'expires',
      header: 'ينتهي',
      cell: (r) => (r.expires_at ? formatDate(r.expires_at) : '—'),
      sortValue: (r) => r.expires_at || '',
    },
  ]

  return (
    <div className="rpm-stack wl">
      <PageHeader
        title="قائمة الانتظار"
        subtitle="طلبات مرتّبة زمنياً حسب الفترة المرغوبة — بلا إنشاء عقد تلقائي"
        action={<Button onClick={() => void openSheet()}>طلب جديد</Button>}
        mobileFab
      />
      <Fab label="طلب جديد" onClick={() => void openSheet()} />

      <MetricCardGrid
        label="ملخص الانتظار"
        items={[
          { id: 'total', label: 'إجمالي الطلبات', value: summary.total, icon: <IconQueue size={16} /> },
          { id: 'waiting', label: 'بانتظار التوفر', value: summary.waiting, tone: 'warning', icon: <IconUsers size={16} /> },
          { id: 'soon', label: 'خلال ٧ أيام', value: summary.soon, tone: 'info', icon: <IconCalendar size={16} /> },
          { id: 'alert', label: 'بحاجة متابعة', value: summary.waiting, tone: summary.waiting ? 'danger' : undefined, icon: <IconAlert size={16} /> },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالعميل أو الفئة…"
      />

      <DataTable
        label="طلبات الانتظار — ترتيب زمني"
        rows={visible}
        columns={columns}
        rowKey={(r) => r.id}
        loading={loading}
        emptyTitle="لا طلبات انتظار"
        emptyDescription="سجّل طلب عميل للفترة والفئة غير المتاحة حالياً."
      />

      <Sheet
        open={open}
        title="طلب انتظار جديد"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={() => void onSave()}>حفظ الطلب</Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          <SelectField
            label="العميل"
            required
            options={[
              { value: '', label: 'اختر…' },
              ...customers.map((c) => ({ value: String(c.id), label: `${c.full_name} · ${c.phone}` })),
            ]}
            value={form.customer_id}
            onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
          />
          <SelectField
            label="الفئة المطلوبة"
            required
            value={form.category}
            onChange={(e) => setForm({ ...form, category: e.target.value })}
            options={[
              { value: 'اقتصادي', label: 'اقتصادي' },
              { value: 'دفع رباعي', label: 'دفع رباعي' },
              { value: 'فاخر', label: 'فاخر' },
            ]}
          />
          <SelectField
            label="عربية مفضّلة (اختياري)"
            value={form.preferred_vehicle_id}
            onChange={(e) => setForm({ ...form, preferred_vehicle_id: e.target.value })}
            options={[
              { value: '', label: '— أي عربية من الفئة —' },
              ...vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` })),
            ]}
          />
          <DateField
            label="من تاريخ"
            value={form.desired_start}
            onChange={(e) => setForm({ ...form, desired_start: e.target.value })}
          />
          <DateField
            label="إلى تاريخ"
            value={form.desired_end}
            onChange={(e) => setForm({ ...form, desired_end: e.target.value })}
          />
          <NumberField
            label="الأولوية"
            value={form.priority}
            onChange={(e) => setForm({ ...form, priority: e.target.value })}
            hint="من ٠ إلى ١٠٠"
          />
          <TextAreaField
            label="ملاحظات"
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
          />
        </div>
      </Sheet>
    </div>
  )
}
