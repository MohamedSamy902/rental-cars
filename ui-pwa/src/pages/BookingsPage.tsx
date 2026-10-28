import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, FilterBar, Button, StatusPill, Sheet, Fab, DetailList, DataTable,
  TextField, SelectField, NumberField, MoneyField, TextAreaField, SignatureField, MediaUpload,
  useToast, SkeletonBlock, ErrorState, IconEye, IconCheck, IconKey, IconReturn, IconCash,
  IconCalendar, IconCar, IconBan, IconPrinter, IconWallet,
  type DataColumn, type MenuItem,
} from '@/shared/ui'
import { useNewContract } from '@/shared/contracts/newContractContext'
import './pages.css'

type Deposit = { id: number; original_amount: number; status: string; deductions_total: number }
type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  odometer_out?: number
  final_total?: number
  vehicle_id?: number
  customer?: { id: number; full_name: string }
  vehicle?: { id: number; brand: string; model: string; plate: string; odometer?: number }
  deposit?: Deposit | null
}

type Vehicle = { id: number; brand: string; model: string; plate: string; daily_rate: number; default_deposit: number; odometer?: number }

type ActionKind = 'handover' | 'return' | 'cancel' | 'extend' | 'swap' | 'deposit' | 'deduct' | 'refund' | null

const statusLabel: Record<string, string> = {
  pending: 'معلّق', approved: 'معتمد', active: 'نشط', completed: 'مكتمل', cancelled: 'ملغى',
}

const depositLabel: Record<string, { label: string; tone: 'success' | 'warning' | 'steel' | 'muted' | 'danger' }> = {
  received: { label: 'مقبوض', tone: 'success' },
  partial: { label: 'مخصوم جزئياً', tone: 'warning' },
  settled: { label: 'مستهلك بالخصم', tone: 'steel' },
  returned: { label: 'مردود', tone: 'muted' },
}

function DepositBadge({ deposit }: { deposit?: Deposit | null }) {
  if (!deposit) return <StatusPill status="none" tone="danger" label="غير مقبوض" />
  const meta = depositLabel[deposit.status] ?? { label: deposit.status, tone: 'muted' as const }
  return <StatusPill status={deposit.status} tone={meta.tone} label={meta.label} />
}

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'pending', label: 'معلّق' },
  { id: 'approved', label: 'معتمد' },
  { id: 'active', label: 'نشط' },
  { id: 'completed', label: 'مكتمل' },
]

export function BookingsPage() {
  const toast = useToast()
  const [searchParams, setSearchParams] = useSearchParams()
  const [rows, setRows] = useState<Contract[]>([])
  const [summary, setSummary] = useState({ total: 0, pending: 0, approved: 0, active: 0, completed: 0 })
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const { openNewContract, createdVersion } = useNewContract()
  const [action, setAction] = useState<ActionKind>(null)
  const [selected, setSelected] = useState<Contract | null>(null)
  const [detail, setDetail] = useState<Contract | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [actionForm, setActionForm] = useState({
    odometer_out: '', odometer_in: '', damage_fee: '', reason: '', planned_ends_at: '',
    replacement_vehicle_id: '', method: 'cash', receipt_number: '',
    fuel_level: '50', notes: '', signature_data: '', damage_position: '', damage_description: '',
    damage_photo_path: '', deduct_amount: '', deduct_reason: '',
  })

  async function openDetail(id: number) {
    setDetailLoading(true)
    try {
      const res = await api<{ data: Contract }>(`/contracts/${id}`)
      setDetail(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setDetailLoading(false)
    }
  }

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

  // Legacy deep links (/bookings?new=1) open the shared sheet, then drop the flag
  const wantNew = searchParams.get('new') === '1'
  useEffect(() => {
    if (!wantNew) return
    openNewContract()
    const next = new URLSearchParams(searchParams)
    next.delete('new')
    setSearchParams(next, { replace: true })
  }, [wantNew, openNewContract, searchParams, setSearchParams])

  async function openAction(kind: ActionKind, contract: Contract) {
    setSelected(contract)
    setAction(kind)
    setActionForm({
      odometer_out: String(contract.vehicle?.odometer ?? contract.odometer_out ?? ''),
      odometer_in: String(contract.odometer_out ?? contract.vehicle?.odometer ?? ''),
      damage_fee: '',
      reason: '',
      planned_ends_at: '',
      replacement_vehicle_id: '',
      method: 'cash',
      receipt_number: `R-${contract.number}`,
      fuel_level: '50',
      notes: '',
      signature_data: '',
      damage_position: '',
      damage_description: '',
      damage_photo_path: '',
      deduct_amount: '',
      deduct_reason: '',
    })
    if (kind === 'swap' || kind === 'extend') {
      const v = await api<{ data: Vehicle[] }>('/vehicles?per_page=100')
      setVehicles(v.data)
    }
  }

  async function approve(id: number) {
    try {
      await api(`/contracts/${id}/approve`, { method: 'POST' })
      toast.success('تم اعتماد العقد وتثبيت الأسعار.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function runAction() {
    if (!selected || !action) return
    setSaving(true)
    try {
      if (action === 'handover') {
        await api(`/contracts/${selected.id}/handover`, {
          method: 'POST',
          json: {
            odometer_out: Number(actionForm.odometer_out),
            fuel_level: actionForm.fuel_level ? Number(actionForm.fuel_level) : 50,
            notes: actionForm.notes || undefined,
            signature_data: actionForm.signature_data || undefined,
          },
        })
        toast.success('تم التسليم وتفعيل العقد.')
      } else if (action === 'return') {
        const damages = actionForm.damage_position && actionForm.damage_description
          ? [{
              position: actionForm.damage_position,
              description: actionForm.damage_description,
              photo_path: actionForm.damage_photo_path || undefined,
            }]
          : []
        await api(`/contracts/${selected.id}/return`, {
          method: 'POST',
          json: {
            odometer_in: Number(actionForm.odometer_in),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
            fuel_level: actionForm.fuel_level ? Number(actionForm.fuel_level) : 50,
            notes: actionForm.notes || undefined,
            signature_data: actionForm.signature_data || undefined,
            damages,
          },
        })
        toast.success('تم الاستلام وإقفال الإجمالي.')
      } else if (action === 'cancel') {
        await api(`/contracts/${selected.id}/cancel`, {
          method: 'POST',
          json: { reason: actionForm.reason },
        })
        toast.success('تم إلغاء العقد.')
      } else if (action === 'extend') {
        await api(`/contracts/${selected.id}/extend`, {
          method: 'POST',
          json: { planned_ends_at: actionForm.planned_ends_at },
        })
        toast.success('تم إنشاء عقد التمديد.')
      } else if (action === 'swap') {
        await api(`/contracts/${selected.id}/swap`, {
          method: 'POST',
          json: {
            replacement_vehicle_id: Number(actionForm.replacement_vehicle_id),
            odometer_in: Number(actionForm.odometer_in),
            odometer_out: Number(actionForm.odometer_out),
            damage_fee: actionForm.damage_fee ? Number(actionForm.damage_fee) : 0,
          },
        })
        toast.success('تم التبديل بسبب عطل.')
      } else if (action === 'deposit') {
        await api(`/contracts/${selected.id}/deposit`, {
          method: 'POST',
          json: {
            method: actionForm.method,
            receipt_number: actionForm.receipt_number,
            amount: selected.frozen_agreed_deposit,
          },
        })
        toast.success('تم قبض التأمين النقدي.')
      } else if (action === 'deduct') {
        await api(`/contracts/${selected.id}/deposit/deduct`, {
          method: 'POST',
          json: {
            amount: Number(actionForm.deduct_amount),
            reason: actionForm.deduct_reason,
          },
        })
        toast.success('تم خصم المبلغ من التأمين.')
      } else if (action === 'refund') {
        await api(`/contracts/${selected.id}/deposit/return`, {
          method: 'POST',
          json: {
            method: actionForm.method,
            receipt_number: actionForm.receipt_number,
          },
        })
        toast.success('تم رد متبقي التأمين.')
      }
      setAction(null)
      setSelected(null)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const actionTitle: Record<Exclude<ActionKind, null>, string> = {
    handover: 'تسليم السيارة',
    return: 'استلام وإقفال',
    cancel: 'إلغاء العقد',
    extend: 'تمديد (عقد جديد)',
    swap: 'تبديل بسبب عطل',
    deposit: 'قبض التأمين النقدي',
    deduct: 'خصم من التأمين',
    refund: 'رد متبقي التأمين',
  }

  async function printContract(id: number) {
    try {
      const res = await api<{ data: { tenant: { center_name?: string; print_footer?: string }; contract: any } }>(`/contracts/${id}/print`)
      const { tenant, contract } = res.data
      await api('/print-snapshots', {
        method: 'POST',
        json: {
          type: 'contract',
          record_type: 'contract',
          record_id: id,
          payload: { tenant, contract },
        },
      }).catch(() => undefined)
      const w = window.open('', '_blank', 'noopener,noreferrer,width=900,height=700')
      if (!w) {
        toast.error('اسمح بالنوافذ المنبثقة للطباعة.')
        return
      }
      w.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>عقد ${contract.number}</title>
        <style>body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a} h1{margin:0 0 4px} .muted{color:#64748b} table{width:100%;border-collapse:collapse;margin-top:16px} td,th{border:1px solid #cbd5e1;padding:8px;text-align:start} .foot{margin-top:24px;color:#64748b;font-size:12px}</style>
        </head><body>
        <h1>${tenant.center_name || 'عقد تأجير'}</h1>
        <p class="muted">عقد رقم ${contract.number} · ${statusLabel[contract.status] || contract.status}</p>
        <table>
          <tr><th>العميل</th><td>${contract.customer?.full_name || '—'}</td></tr>
          <tr><th>المركبة</th><td>${contract.vehicle ? `${contract.vehicle.brand} ${contract.vehicle.model} · ${contract.vehicle.plate}` : '—'}</td></tr>
          <tr><th>البداية</th><td>${formatDateTime(contract.starts_at)}</td></tr>
          <tr><th>النهاية المخططة</th><td>${formatDateTime(contract.planned_ends_at)}</td></tr>
          <tr><th>السعر اليومي</th><td>${formatMoney(contract.frozen_daily_rate)}</td></tr>
          <tr><th>التأمين النقدي</th><td>${formatMoney(contract.frozen_agreed_deposit || 0)}</td></tr>
          <tr><th>الإجمالي النهائي</th><td>${contract.final_total != null ? formatMoney(contract.final_total) : '—'}</td></tr>
        </table>
        <p class="foot">${tenant.print_footer || ''}</p>
        <script>window.onload=()=>window.print()<\/script>
        </body></html>`)
      w.document.close()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  function contractActions(b: Contract) {
    return (
      <div className="rpm-card__actions">
        {b.status === 'pending' ? (
          <>
            <Button type="button" size="sm" onClick={() => void approve(b.id)}>اعتماد</Button>
            <Button type="button" size="sm" variant="danger" onClick={() => void openAction('cancel', b)}>إلغاء العقد</Button>
          </>
        ) : null}
        {b.status === 'approved' ? (
          <>
            <Button type="button" size="sm" onClick={() => void openAction('handover', b)}>تسليم</Button>
            {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('deposit', b)}>قبض تأمين</Button> : null}
            <Button type="button" size="sm" variant="danger" onClick={() => void openAction('cancel', b)}>إلغاء العقد</Button>
            <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
          </>
        ) : null}
        {b.status === 'active' ? (
          <>
            <Button type="button" size="sm" onClick={() => void openAction('return', b)}>استلام</Button>
            {!b.deposit ? <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('deposit', b)}>قبض تأمين</Button> : null}
            <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
            <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('swap', b)}>تبديل عطل</Button>
          </>
        ) : null}
        {b.status === 'completed' ? (
          <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('extend', b)}>تمديد</Button>
        ) : null}
        {b.deposit && b.deposit.status !== 'returned' ? (
          <>
            <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('deduct', b)}>خصم تأمين</Button>
            <Button type="button" size="sm" variant="secondary" onClick={() => void openAction('refund', b)}>رد تأمين</Button>
          </>
        ) : null}
        <Button type="button" size="sm" variant="secondary" onClick={() => void printContract(b.id)}>طباعة</Button>
      </div>
    )
  }

  function contractMenu(b: Contract): MenuItem[] {
    const items: MenuItem[] = [
      { id: 'view', label: 'عرض التفاصيل', icon: <IconEye size={16} />, onSelect: () => void openDetail(b.id) },
      { id: 'sep-1', separator: true },
    ]
    if (b.status === 'pending') {
      items.push({ id: 'approve', label: 'اعتماد', icon: <IconCheck size={16} />, onSelect: () => void approve(b.id) })
    }
    if (b.status === 'approved') {
      items.push({ id: 'handover', label: 'تسليم', icon: <IconKey size={16} />, onSelect: () => void openAction('handover', b) })
    }
    if (b.status === 'active') {
      items.push({ id: 'return', label: 'استلام وإقفال', icon: <IconReturn size={16} />, onSelect: () => void openAction('return', b) })
    }
    if ((b.status === 'approved' || b.status === 'active') && !b.deposit) {
      items.push({ id: 'deposit', label: 'قبض تأمين', icon: <IconCash size={16} />, onSelect: () => void openAction('deposit', b) })
    }
    if (b.status === 'approved' || b.status === 'active' || b.status === 'completed') {
      items.push({ id: 'extend', label: 'تمديد', icon: <IconCalendar size={16} />, onSelect: () => void openAction('extend', b) })
    }
    if (b.status === 'active') {
      items.push({ id: 'swap', label: 'تبديل عطل', icon: <IconCar size={16} />, onSelect: () => void openAction('swap', b) })
    }
    if (b.deposit && b.deposit.status !== 'returned') {
      items.push(
        { id: 'deduct', label: 'خصم تأمين', icon: <IconWallet size={16} />, onSelect: () => void openAction('deduct', b) },
        { id: 'refund', label: 'رد تأمين', icon: <IconReturn size={16} />, onSelect: () => void openAction('refund', b) },
      )
    }
    items.push({ id: 'print', label: 'طباعة', icon: <IconPrinter size={16} />, onSelect: () => void printContract(b.id) })
    if (b.status === 'pending' || b.status === 'approved') {
      items.push(
        { id: 'sep-2', separator: true },
        { id: 'cancel', label: 'إلغاء العقد', icon: <IconBan size={16} />, tone: 'danger', onSelect: () => void openAction('cancel', b) },
      )
    }
    return items
  }

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
  ]

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العقود والحجوزات"
        subtitle="تمديد = عقد جديد · تبديل عطل = عقد جديد · بلا رصيد دائن"
        action={<Button onClick={openNewContract}>إضافة حجز</Button>}
        mobileFab
      />
      <Fab label="إضافة حجز" onClick={openNewContract} />

      <SummaryStrip
        items={[
          { label: 'الإجمالي', value: String(summary.total) },
          { label: 'معلّق', value: String(summary.pending), tone: 'warning' },
          { label: 'نشط', value: String(summary.active), tone: 'success' },
          { label: 'معتمد', value: String(summary.approved) },
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
          onRowClick={(b) => void openDetail(b.id)}
          rowActions={contractMenu}
          emptyTitle="لا توجد عقود مطابقة"
          emptyDescription="غيّر البحث أو الفلتر، أو أضف حجزاً جديداً."
        />
      )}

      <Sheet
        open={!!detail || detailLoading}
        title={detail ? `عقد ${detail.number}` : 'تفاصيل العقد'}
        onClose={() => setDetail(null)}
        closeLabel="إغلاق"
      >
        {detailLoading && !detail ? <SkeletonBlock rows={6} /> : null}
        {detail ? (
          <>
            <DetailList
              rows={[
                { label: 'الحالة', value: <StatusPill status={detail.status} label={statusLabel[detail.status] || detail.status} /> },
                { label: 'حالة التأمين', value: <DepositBadge deposit={detail.deposit} /> },
                { label: 'العميل', value: detail.customer?.full_name || '—', wide: true },
                { label: 'المركبة', value: detail.vehicle ? `${detail.vehicle.brand} ${detail.vehicle.model} · ${detail.vehicle.plate}` : '—', wide: true },
                { label: 'البداية', value: formatDateTime(detail.starts_at) },
                { label: 'النهاية المخططة', value: formatDateTime(detail.planned_ends_at) },
                { label: 'السعر اليومي', value: formatMoney(detail.frozen_daily_rate) },
                { label: 'التأمين المتفق', value: formatMoney(detail.frozen_agreed_deposit || 0) },
                { label: 'عداد الخروج', value: detail.odometer_out != null ? `${formatNumber(detail.odometer_out)} كم` : '—' },
                { label: 'الإجمالي النهائي', value: detail.final_total != null ? formatMoney(detail.final_total) : '—' },
              ]}
            />
            <div className="rpm-detail-actions">{contractActions(detail)}</div>
          </>
        ) : null}
      </Sheet>

      <Sheet
        open={!!action && !!selected}
        title={action ? `${actionTitle[action]} · ${selected?.number}` : ''}
        onClose={() => { setAction(null); setSelected(null) }}
        footer={
          <>
            <Button loading={saving} onClick={runAction}>تأكيد</Button>
            <Button variant="secondary" onClick={() => { setAction(null); setSelected(null) }}>إغلاق</Button>
          </>
        }
      >
        <div className="rpm-form-grid">
          {action === 'handover' || action === 'swap' ? (
            <NumberField label={action === 'swap' ? 'عداد السيارة البديلة (خروج)' : 'عداد الخروج'} required value={actionForm.odometer_out} onChange={(e) => setActionForm({ ...actionForm, odometer_out: e.target.value })} />
          ) : null}
          {action === 'return' || action === 'swap' ? (
            <NumberField label={action === 'swap' ? 'عداد السيارة القديمة (عودة)' : 'عداد العودة'} required value={actionForm.odometer_in} onChange={(e) => setActionForm({ ...actionForm, odometer_in: e.target.value })} />
          ) : null}
          {action === 'handover' || action === 'return' ? (
            <NumberField label="مستوى البنزين %" value={actionForm.fuel_level} onChange={(e) => setActionForm({ ...actionForm, fuel_level: e.target.value })} />
          ) : null}
          {action === 'return' || action === 'swap' ? (
            <MoneyField label="رسوم أضرار" value={actionForm.damage_fee} onChange={(e) => setActionForm({ ...actionForm, damage_fee: e.target.value })} />
          ) : null}
          {action === 'return' ? (
            <>
              <TextField label="موضع الضرر (إن وُجد)" value={actionForm.damage_position} onChange={(e) => setActionForm({ ...actionForm, damage_position: e.target.value })} />
              <TextAreaField label="وصف الضرر" value={actionForm.damage_description} onChange={(e) => setActionForm({ ...actionForm, damage_description: e.target.value })} />
              <MediaUpload
                label="صورة / فيديو الضرر"
                value={actionForm.damage_photo_path || null}
                collection="inspection_damage"
                purpose="damage"
                accept="both"
                attachableType="contract"
                attachableId={selected?.id}
                onChange={(path) => setActionForm({ ...actionForm, damage_photo_path: path || '' })}
                hint="اختياري — يُحفظ محلياً مع سجل الفحص"
              />
            </>
          ) : null}
          {action === 'handover' || action === 'return' ? (
            <>
              <TextAreaField label="ملاحظات الفحص" value={actionForm.notes} onChange={(e) => setActionForm({ ...actionForm, notes: e.target.value })} />
              <SignatureField
                required={action === 'handover' || Boolean(actionForm.damage_fee) || Boolean(actionForm.damage_position)}
                value={actionForm.signature_data}
                onChange={(signature_data) => setActionForm({ ...actionForm, signature_data })}
              />
            </>
          ) : null}
          {action === 'cancel' ? (
            <TextAreaField label="سبب الإلغاء" required value={actionForm.reason} onChange={(e) => setActionForm({ ...actionForm, reason: e.target.value })} />
          ) : null}
          {action === 'extend' ? (
            <TextField label="نهاية عقد التمديد" required type="datetime-local" value={actionForm.planned_ends_at} onChange={(e) => setActionForm({ ...actionForm, planned_ends_at: e.target.value })} />
          ) : null}
          {action === 'swap' ? (
            <SelectField
              label="السيارة البديلة"
              required
              options={vehicles
                .filter((v) => v.id !== selected?.vehicle_id)
                .map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
              value={actionForm.replacement_vehicle_id}
              onChange={(e) => setActionForm({ ...actionForm, replacement_vehicle_id: e.target.value })}
            />
          ) : null}
          {action === 'deposit' || action === 'refund' ? (
            <>
              <SelectField
                label={action === 'refund' ? 'طريقة الرد' : 'طريقة التحصيل'}
                required
                options={[
                  { value: 'cash', label: 'نقدي' },
                  { value: 'card', label: 'بطاقة' },
                  { value: 'transfer', label: 'تحويل' },
                ]}
                value={actionForm.method}
                onChange={(e) => setActionForm({ ...actionForm, method: e.target.value })}
              />
              <TextField label="رقم الإيصال" required value={actionForm.receipt_number} onChange={(e) => setActionForm({ ...actionForm, receipt_number: e.target.value })} />
              {action === 'deposit' ? <p className="muted">المبلغ: {formatMoney(selected?.frozen_agreed_deposit || 0)} (خارج إجمالي العقد)</p> : null}
            </>
          ) : null}
          {action === 'deduct' ? (
            <>
              <MoneyField label="مبلغ الخصم" required value={actionForm.deduct_amount} onChange={(e) => setActionForm({ ...actionForm, deduct_amount: e.target.value })} />
              <TextAreaField label="سبب الخصم" required value={actionForm.deduct_reason} onChange={(e) => setActionForm({ ...actionForm, deduct_reason: e.target.value })} />
            </>
          ) : null}
        </div>
      </Sheet>
    </div>
  )
}
