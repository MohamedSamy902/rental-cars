import { useEffect, useRef, useState } from 'react'
import { api, apiUpload, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import {
  PageHeader, Button, Sheet, Fab, SelectField, TextField, MoneyField, TextAreaField, StatusPill, useToast, DataTable, IconCheck,
  MediaUpload, type DataColumn,
} from '@/shared/ui'
import './pages.css'

type Violation = {
  id: number
  amount: number
  occurred_at: string
  status: string
  location?: string
  source?: string
  needs_review?: boolean
  external_number?: string | null
  vehicle?: { brand: string; model: string; plate: string }
  customer?: { full_name: string }
}

type Vehicle = { id: number; brand: string; model: string; plate: string }

type PortalInfo = {
  status: string
  message: string
  portal_url: string
  alt_url: string
  plate_hint?: string | null
}

type SyncRun = {
  id: number
  channel: string
  status: string
  rows_created: number
  rows_skipped: number
  rows_seen: number
  created_at?: string
}

const statusLabel: Record<string, string> = {
  new: 'جديدة', assigned: 'مسندة', confirmed: 'مؤكّد السداد', paid: 'مدفوعة للجهة',
}

const sourceLabel: Record<string, string> = {
  manual: 'يدوي',
  assisted: 'مساعد بوابة',
  csv_import: 'CSV',
}

const columns: DataColumn<Violation>[] = [
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
  { id: 'customer', header: 'العميل', cell: (r) => r.customer?.full_name || (r.needs_review ? 'يحتاج مراجعة' : 'بلا إسناد بعد'), sortValue: (r) => r.customer?.full_name, mobile: 'subtitle' },
  { id: 'date', header: 'الوقت', cell: (r) => formatDateTime(r.occurred_at), sortValue: (r) => r.occurred_at },
  { id: 'location', header: 'المكان', cell: (r) => r.location || '—', sortValue: (r) => r.location },
  { id: 'amount', header: 'المبلغ', cell: (r) => formatMoney(r.amount), sortValue: (r) => Number(r.amount) },
  {
    id: 'source',
    header: 'المصدر',
    cell: (r) => sourceLabel[r.source || 'manual'] || r.source || 'يدوي',
    sortValue: (r) => r.source,
    mobile: 'hidden',
  },
  {
    id: 'status',
    header: 'الحالة',
    cell: (r) => <StatusPill status={r.status === 'confirmed' ? 'completed' : 'pending'} label={statusLabel[r.status] || r.status} />,
    sortValue: (r) => r.status,
    mobile: 'badge',
  },
]

const emptyAssisted = {
  vehicle_id: '', occurred_at: '', amount: '', location: '', external_number: '', notes: '', photo_path: '',
}

export function ViolationsPage() {
  const toast = useToast()
  const fileRef = useRef<HTMLInputElement>(null)
  const [rows, setRows] = useState<Violation[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [assistOpen, setAssistOpen] = useState(false)
  const [portal, setPortal] = useState<PortalInfo | null>(null)
  const [runs, setRuns] = useState<SyncRun[]>([])
  const [form, setForm] = useState({ vehicle_id: '', occurred_at: '', amount: '', location: '', photo_path: '' })
  const [assist, setAssist] = useState(emptyAssisted)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [importing, setImporting] = useState(false)

  async function loadVehicles() {
    const v = await api<{ data: Vehicle[] }>('/vehicles')
    setVehicles(v.data)
    return v.data
  }

  async function load() {
    setLoading(true)
    try {
      const [list, sync] = await Promise.all([
        api<{ data: Violation[] }>('/violations'),
        api<{ data: SyncRun[] }>('/violations/sync-runs').catch(() => ({ data: [] as SyncRun[] })),
      ])
      setRows(list.data)
      setRuns(sync.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  async function openAssist() {
    const list = await loadVehicles()
    const info = await api<{ data: PortalInfo }>('/violations/portal')
    setPortal(info.data)
    setAssist({ ...emptyAssisted, vehicle_id: list[0] ? String(list[0].id) : '' })
    setFieldErrors({})
    setAssistOpen(true)
  }

  function selectedPlate(): string {
    const v = vehicles.find((x) => String(x.id) === assist.vehicle_id)
    return v?.plate || ''
  }

  function openPortal() {
    const url = portal?.portal_url || 'https://www.ppo.gov.eg/ppo/r/ppoportal/ppoportal/traffic'
    window.open(url, '_blank', 'noopener,noreferrer')
  }

  async function onSave() {
    setFieldErrors({})
    try {
      await api('/violations', {
        method: 'POST',
        json: {
          vehicle_id: Number(form.vehicle_id),
          occurred_at: form.occurred_at,
          amount: Number(form.amount),
          location: form.location || undefined,
          photo_path: form.photo_path || undefined,
        },
      })
      toast.success('تم التسجيل للمتابعة بلا ترحيل محاسبي.')
      setOpen(false)
      await load()
    } catch (e) {
      mapErrors(e)
    }
  }

  async function onAssistSave() {
    setFieldErrors({})
    try {
      await api('/violations/assisted', {
        method: 'POST',
        json: {
          vehicle_id: Number(assist.vehicle_id),
          occurred_at: assist.occurred_at,
          amount: Number(assist.amount),
          location: assist.location || undefined,
          external_number: assist.external_number || undefined,
          notes: assist.notes || undefined,
          photo_path: assist.photo_path || undefined,
        },
      })
      toast.success('تم الحفظ بعد الاستعلام اليدوي.')
      setAssistOpen(false)
      await load()
    } catch (e) {
      mapErrors(e)
    }
  }

  function mapErrors(e: unknown) {
    const apiErr = e as ApiError
    const next: Record<string, string> = {}
    if (apiErr.errors) {
      for (const [k, v] of Object.entries(apiErr.errors)) next[k] = v[0]
    }
    setFieldErrors(next)
    toast.error(apiErr.message)
  }

  async function onImportFile(file: File | null) {
    if (!file) return
    setImporting(true)
    try {
      const fd = new FormData()
      fd.append('file', file)
      const res = await apiUpload<{ data: { created_count: number; errors: string[] }; message: string }>(
        '/violations/import-csv',
        fd,
      )
      toast.success(res.message)
      if (res.data.errors?.length) {
        toast.error(res.data.errors.slice(0, 3).join(' · '))
      }
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setImporting(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  async function confirm(id: number) {
    try {
      await api(`/violations/${id}/confirm`, { method: 'POST', json: { collection_note: 'تم التأكيد' } })
      toast.success('تم تأكيد السداد دون قيد.')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المخالفات"
        subtitle="متابعة تشغيلية · استعلام البوابة يدوي (لا سحب آلي) · استيراد CSV"
        action={(
          <div className="rpm-inline-actions">
            <Button variant="secondary" onClick={() => void openAssist()}>استعلام + حفظ</Button>
            <Button variant="secondary" loading={importing} onClick={() => fileRef.current?.click()}>استيراد CSV</Button>
            <Button onClick={async () => { setOpen(true); await loadVehicles() }}>تسجيل يدوي</Button>
          </div>
        )}
        mobileFab
      />
      <input
        ref={fileRef}
        type="file"
        accept=".csv,text/csv"
        hidden
        onChange={(e) => void onImportFile(e.target.files?.[0] ?? null)}
      />
      <Fab label="استعلام بوابة" onClick={() => void openAssist()} />

      {portal || runs.length > 0 ? (
        <div className="rpm-card rpm-card--soft">
          <p className="muted" style={{ marginBlockEnd: 8 }}>
            البوابة الرسمية محمية بملكية المركبة/OTP — النظام لا يسحب منها تلقائياً. افتحها، انسخ النتيجة، ثم احفظ أو استورد CSV.
          </p>
          {runs[0] ? (
            <p className="muted">
              آخر دفعة: #{runs[0].id} · {runs[0].channel} · أُنشئ {runs[0].rows_created} / تُخطّي {runs[0].rows_skipped}
            </p>
          ) : null}
        </div>
      ) : null}

      <DataTable
        label="المخالفات"
        rows={rows}
        columns={columns}
        rowKey={(r) => r.id}
        rowLabel={(r) => `مخالفة · ${r.vehicle?.plate ?? ''}`}
        loading={loading}
        defaultSort={{ id: 'date', dir: 'desc' }}
        rowActions={(r) =>
          r.status !== 'confirmed'
            ? [{ id: 'confirm', label: 'تأكيد السداد', icon: <IconCheck size={16} />, onSelect: () => void confirm(r.id) }]
            : []
        }
        emptyTitle="لا توجد مخالفات"
        emptyDescription="سجّل يدوياً، أو استعلم من البوابة ثم احفظ، أو استورد CSV."
      />

      <Sheet open={open} title="مخالفة جديدة" onClose={() => setOpen(false)} footer={<><Button onClick={() => void onSave()}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <SelectField label="السيارة" required error={fieldErrors.vehicle_id} options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))} value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })} />
          <TextField label="وقت المخالفة" required error={fieldErrors.occurred_at} type="datetime-local" value={form.occurred_at} onChange={(e) => setForm({ ...form, occurred_at: e.target.value })} />
          <MoneyField label="المبلغ (للمتابعة)" required error={fieldErrors.amount} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <TextField label="المكان" error={fieldErrors.location} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          <MediaUpload
            label="صورة المخالفة"
            accept="both"
            collection="violations"
            purpose="ticket"
            error={fieldErrors.photo_path}
            value={form.photo_path || null}
            onChange={(path) => setForm({ ...form, photo_path: path || '' })}
          />
        </div>
      </Sheet>

      <Sheet
        open={assistOpen}
        title="استعلام بوابة ثم حفظ"
        onClose={() => setAssistOpen(false)}
        footer={(
          <>
            <Button onClick={() => void onAssistSave()}>حفظ في RPM</Button>
            <Button variant="secondary" onClick={openPortal}>فتح بوابة المرور</Button>
            <Button variant="secondary" onClick={() => setAssistOpen(false)}>إغلاق</Button>
          </>
        )}
      >
        <div className="rpm-form-grid">
          <p className="muted" style={{ gridColumn: '1 / -1' }}>
            {portal?.message || 'افتح البوابة كمالك المركبة، انسخ رقم المخالفة والمبلغ والتاريخ، ثم احفظ هنا.'}
          </p>
          <SelectField
            label="السيارة"
            required
            error={fieldErrors.vehicle_id}
            options={vehicles.map((v) => ({ value: String(v.id), label: `${v.brand} ${v.model} · ${v.plate}` }))}
            value={assist.vehicle_id}
            onChange={(e) => setAssist({ ...assist, vehicle_id: e.target.value })}
          />
          <TextField label="اللوحة (للنسخ)" readOnly value={selectedPlate()} />
          <TextField label="رقم المخالفة (من البوابة)" error={fieldErrors.external_number} value={assist.external_number} onChange={(e) => setAssist({ ...assist, external_number: e.target.value })} />
          <TextField label="وقت المخالفة" required error={fieldErrors.occurred_at} type="datetime-local" value={assist.occurred_at} onChange={(e) => setAssist({ ...assist, occurred_at: e.target.value })} />
          <MoneyField label="المبلغ" required error={fieldErrors.amount} value={assist.amount} onChange={(e) => setAssist({ ...assist, amount: e.target.value })} />
          <TextField label="المكان" error={fieldErrors.location} value={assist.location} onChange={(e) => setAssist({ ...assist, location: e.target.value })} />
          <TextAreaField label="ملاحظات" value={assist.notes} onChange={(e) => setAssist({ ...assist, notes: e.target.value })} />
          <MediaUpload
            label="لقطة شاشة / صورة المخالفة"
            accept="both"
            collection="violations"
            purpose="ticket"
            value={assist.photo_path || null}
            onChange={(path) => setAssist({ ...assist, photo_path: path || '' })}
          />
        </div>
      </Sheet>
    </div>
  )
}
