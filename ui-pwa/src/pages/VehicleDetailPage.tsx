import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  Button, StatusPill, Sheet,
  TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField, MediaUpload,
  useToast, SkeletonBlock, ErrorState,
} from '@/shared/ui'
import './VehicleDetailPage.css'
import './pages.css'

type OwnerOption = { id: number; full_name: string; phone?: string | null }
type Accessory = { id: number; name: string; default_qty?: number }
type Document = {
  id: number
  type: string
  file_path?: string | null
  expires_on?: string | null
  issued_on?: string | null
  notes?: string | null
}

type Vehicle = {
  id: number
  code: string
  plate: string
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  odometer: number
  daily_rate: number
  default_deposit: number
  status: string
  rental_priority: number
  tracker_device_number?: string | null
  tracker_sim_number?: string | null
  notes?: string | null
  is_active?: boolean
  owner_id?: number | null
  owner_monthly_amount?: number | null
  owner_since?: string | null
  owner?: OwnerOption | null
  accessories?: Accessory[] | null
  documents?: Document[] | null
}

type Dossier = {
  vehicle: Vehicle
  contracts: Array<{
    id: number
    number: string
    status: string
    starts_at?: string | null
    planned_ends_at?: string | null
    actual_ends_at?: string | null
    final_total?: number | null
    period_rent?: number | null
    odometer_out?: number | null
    odometer_in?: number | null
    customer?: { id: number; full_name: string; phone?: string | null } | null
  }>
  work_orders: Array<{
    id: number
    status: string
    workshop?: string | null
    opened_on?: string | null
    closed_on?: string | null
    odometer?: number | null
    parts_cost?: number | null
    labor_cost?: number | null
    notes?: string | null
    lines: Array<{ id: number; description: string; quantity?: number; unit_price?: number }>
  }>
  expenses: Array<{
    id: number
    title: string
    category?: string | null
    amount: number
    spent_on?: string | null
    payment_method?: string | null
    notes?: string | null
  }>
  violations: Array<{
    id: number
    status?: string | null
    amount?: number | null
    location?: string | null
    occurred_at?: string | null
    customer?: { id: number; full_name: string } | null
  }>
  media: Array<{
    id: number
    kind: string
    original_name: string
    collection?: string | null
    purpose?: string | null
    url: string
    created_at?: string | null
  }>
  owner_dues: Array<{ id: number; year_month: string; amount: number }>
  owner_payouts: Array<{
    id: number
    amount: number
    paid_on?: string | null
    payment_method?: string | null
    notes?: string | null
  }>
  qr: { payload: string; label: string }
  totals: {
    contracts: number
    work_orders: number
    expenses_amount: number
    violations: number
    media: number
  }
}

type TabId = 'overview' | 'bookings' | 'maintenance' | 'expenses' | 'docs' | 'owner'

const statusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
  pending: 'قيد الانتظار',
  approved: 'معتمدة',
  active: 'نشطة',
  completed: 'مكتملة',
  cancelled: 'ملغاة',
  open: 'مفتوحة',
  in_progress: 'قيد التنفيذ',
  closed: 'مغلقة',
}

const emptyForm = {
  code: '', plate: '', brand: '', model: '', year: '', category: 'اقتصادي', fuel_type: 'بنزين',
  odometer: '', daily_rate: '', default_deposit: '', rental_priority: '50',
  tracker_device_number: '', tracker_sim_number: '', notes: '', is_active: true,
  owner_id: '', owner_monthly_amount: '',
}

function buildQrDataUrl(text: string) {
  const canvas = document.createElement('canvas')
  const size = 180
  canvas.width = size
  canvas.height = size
  const ctx = canvas.getContext('2d')
  if (!ctx) return ''
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, size, size)
  ctx.fillStyle = '#0f172a'
  let h = 0
  for (let i = 0; i < text.length; i += 1) h = (h * 31 + text.charCodeAt(i)) >>> 0
  const cells = 21
  const cell = Math.floor(size / cells)
  for (let y = 0; y < cells; y += 1) {
    for (let x = 0; x < cells; x += 1) {
      const bit = ((h >> ((x + y) % 24)) ^ (x * 7 + y * 13 + text.length)) & 1
      if (bit || (x < 3 && y < 3) || (x > cells - 4 && y < 3) || (x < 3 && y > cells - 4)) {
        ctx.fillRect(x * cell, y * cell, cell - 1, cell - 1)
      }
    }
  }
  return canvas.toDataURL('image/png')
}

function shortDate(value?: string | null) {
  if (!value) return '—'
  return value.slice(0, 10)
}

function Fact({ label, value, wide }: { label: string; value: ReactNode; wide?: boolean }) {
  return (
    <div className={`vd-fact${wide ? ' vd-fact--wide' : ''}`}>
      <span>{label}</span>
      <strong>{value ?? '—'}</strong>
    </div>
  )
}

function Empty({ children }: { children: ReactNode }) {
  return <div className="vd-empty">{children}</div>
}

export function VehicleDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const vehicleId = Number(id)

  const [dossier, setDossier] = useState<Dossier | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<TabId>('overview')
  const [editing, setEditing] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [owners, setOwners] = useState<OwnerOption[]>([])
  const [docForm, setDocForm] = useState({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '', file_path: '' })
  const [accName, setAccName] = useState('')
  const [estimatedKm, setEstimatedKm] = useState<number | null>(null)

  const qrImage = useMemo(
    () => (dossier?.qr.payload ? buildQrDataUrl(dossier.qr.payload) : ''),
    [dossier?.qr.payload],
  )

  async function load() {
    if (!Number.isFinite(vehicleId) || vehicleId <= 0) {
      setError('معرّف المركبة غير صالح.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Dossier }>(`/vehicles/${vehicleId}/dossier`)
      setDossier(res.data)
      const v = res.data.vehicle
      setForm({
        code: v.code,
        plate: v.plate,
        brand: v.brand,
        model: v.model,
        year: String(v.year),
        category: v.category,
        fuel_type: v.fuel_type,
        odometer: String(v.odometer),
        daily_rate: String(v.daily_rate),
        default_deposit: String(v.default_deposit),
        rental_priority: String(v.rental_priority ?? 50),
        tracker_device_number: v.tracker_device_number || '',
        tracker_sim_number: v.tracker_sim_number || '',
        notes: v.notes || '',
        is_active: v.is_active !== false,
        owner_id: v.owner_id ? String(v.owner_id) : '',
        owner_monthly_amount: v.owner_monthly_amount != null ? String(v.owner_monthly_amount) : '',
      })
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [vehicleId])

  useEffect(() => {
    void api<{ data: OwnerOption[] }>('/owners?active_only=1&per_page=100')
      .then((res) => setOwners(res.data))
      .catch(() => setOwners([]))
  }, [])

  async function onUpdate() {
    if (!dossier) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}`, {
        method: 'PUT',
        json: {
          ...form,
          year: Number(form.year),
          odometer: Number(form.odometer),
          daily_rate: Number(form.daily_rate),
          default_deposit: Number(form.default_deposit),
          rental_priority: Number(form.rental_priority || 50),
          owner_id: form.owner_id ? Number(form.owner_id) : null,
          owner_monthly_amount: form.owner_id && form.owner_monthly_amount !== ''
            ? Number(form.owner_monthly_amount)
            : null,
        },
      })
      toast.success('تم تحديث المركبة.')
      setEditOpen(false)
      setEditing(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function addDocument() {
    if (!dossier) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}/documents`, {
        method: 'POST',
        json: {
          type: docForm.type,
          file_path: docForm.file_path || undefined,
          expires_on: docForm.expires_on || undefined,
          issued_on: docForm.issued_on || undefined,
          notes: docForm.notes || undefined,
        },
      })
      toast.success('تم تسجيل المستند.')
      setDocForm({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '', file_path: '' })
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function addAccessory() {
    if (!dossier || !accName.trim()) return
    setSaving(true)
    try {
      await api(`/vehicles/${dossier.vehicle.id}/accessories`, {
        method: 'POST',
        json: { name: accName.trim(), default_qty: 1, required_each_rental: true },
      })
      toast.success('تم إضافة المستلزم.')
      setAccName('')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function loadEstimated() {
    if (!dossier) return
    try {
      const res = await api<{ data: { estimated: number } }>(`/vehicles/${dossier.vehicle.id}/estimated-odometer`)
      setEstimatedKm(res.data.estimated)
      toast.success('تم حساب العداد التقديري.')
    } catch {
      setEstimatedKm(null)
      toast.error('تعذّر حساب العداد التقديري.')
    }
  }

  const formFields = (
    <div className="rpm-form-grid">
      <TextField label="الكود الداخلي" required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
      <TextField label="رقم اللوحة" required value={form.plate} onChange={(e) => setForm({ ...form, plate: e.target.value })} />
      <TextField label="الماركة" required value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} />
      <TextField label="الموديل" required value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
      <NumberField label="سنة الصنع" required value={form.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
      <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'SUV', label: 'SUV' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
      <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
      <NumberField label="العداد الفعلي" required value={form.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
      <MoneyField label="الإيجار اليومي الافتراضي" required value={form.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
      <MoneyField label="التأمين النقدي الافتراضي" required value={form.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
      <NumberField label="درجة أولوية التأجير" value={form.rental_priority} onChange={(e) => setForm({ ...form, rental_priority: e.target.value })} />
      <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
      <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />
      <SelectField
        label="المالك (عربيات الغير)"
        value={form.owner_id}
        onChange={(e) => setForm({ ...form, owner_id: e.target.value })}
        options={[
          { value: '', label: '— ملك المركز —' },
          ...owners.map((o) => ({ value: String(o.id), label: o.full_name + (o.phone ? ` · ${o.phone}` : '') })),
        ]}
      />
      {form.owner_id ? (
        <MoneyField
          label="المبلغ الشهري للمالك"
          required
          value={form.owner_monthly_amount}
          onChange={(e) => setForm({ ...form, owner_monthly_amount: e.target.value })}
        />
      ) : null}
      <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
    </div>
  )

  if (loading) {
    return (
      <div className="vd">
        <SkeletonBlock rows={10} />
      </div>
    )
  }

  if (error || !dossier) {
    return (
      <div className="vd">
        <ErrorState
          title="تعذّر تحميل ملف المركبة"
          description={error || 'لا توجد بيانات'}
          action={
            <div className="rpm-actions-row">
              <Button variant="secondary" onClick={() => navigate('/vehicles')}>رجوع للأسطول</Button>
              <Button onClick={() => load()}>إعادة المحاولة</Button>
            </div>
          }
        />
      </div>
    )
  }

  const v = dossier.vehicle
  const ownerInitial = (v.owner?.full_name || 'م').trim().charAt(0)
  const tabs: Array<{ id: TabId; label: string; count?: number }> = [
    { id: 'overview', label: 'نظرة عامة' },
    { id: 'bookings', label: 'الحجوزات', count: dossier.totals.contracts },
    { id: 'maintenance', label: 'الصيانة', count: dossier.totals.work_orders },
    { id: 'expenses', label: 'المصروفات', count: dossier.expenses.length },
    { id: 'docs', label: 'المستندات', count: (v.documents?.length || 0) + dossier.totals.media },
    { id: 'owner', label: 'المالك' },
  ]

  const overviewPanel = (
    <section className="vd-panel" key="overview">
      <div className="vd-panel__head">
        <h2>بيانات التشغيل</h2>
        <p>المواصفات · التتبع · التأمين</p>
      </div>
      <div className="vd-facts">
        <Fact label="الكود الداخلي" value={v.code} />
        <Fact label="سنة الصنع" value={v.year} />
        <Fact label="الفئة" value={v.category} />
        <Fact label="الوقود" value={v.fuel_type} />
        <Fact label="العداد الفعلي" value={`${formatNumber(v.odometer)} كم`} />
        <Fact label="أولوية التأجير" value={v.rental_priority} />
        <Fact label="التأمين النقدي" value={formatMoney(v.default_deposit)} />
        <Fact label="نشطة" value={v.is_active === false ? 'لا' : 'نعم'} />
        <Fact label="جهاز التتبع" value={v.tracker_device_number || '—'} />
        <Fact label="شريحة التتبع" value={v.tracker_sim_number || '—'} />
        <Fact label="ملاحظات" value={v.notes || '—'} wide />
      </div>
      {estimatedKm != null ? (
        <p className="vd-note">العداد التقديري أثناء التأجير: {formatNumber(estimatedKm)} كم</p>
      ) : null}

      <div className="vd-panel__head" style={{ marginTop: '0.5rem' }}>
        <h2>المستلزمات</h2>
        <p>{(v.accessories || []).length} عنصر</p>
      </div>
      {(v.accessories || []).length === 0 ? <Empty>لا مستلزمات مسجّلة</Empty> : (
        <div className="vd-chips">
          {(v.accessories || []).map((a) => (
            <span key={a.id} className="vd-chip">{a.name}<span className="muted">×{a.default_qty ?? 1}</span></span>
          ))}
        </div>
      )}
      <div className="vd-form-block vd-no-print">
        <div className="rpm-form-grid">
          <TextField label="اسم المستلزم" value={accName} onChange={(e) => setAccName(e.target.value)} />
          <Button size="sm" loading={saving} onClick={() => addAccessory()}>إضافة مستلزم</Button>
        </div>
      </div>
    </section>
  )

  const bookingsPanel = (
    <section className="vd-panel" key="bookings">
      <div className="vd-panel__head">
        <h2>سجل الحجز والعقود</h2>
        <p>{dossier.totals.contracts} عقد</p>
      </div>
      {dossier.contracts.length === 0 ? <Empty>لا حجوزات لهذه المركبة بعد</Empty> : (
        <>
          <div className="vd-timeline rpm-mobile-only">
            {dossier.contracts.map((c) => (
              <div key={c.id} className="vd-tl">
                <span className="vd-tl__dot" aria-hidden />
                <div className="vd-tl__body">
                  <div className="vd-tl__row">
                    <strong>{c.number}</strong>
                    <StatusPill status={c.status} label={statusLabel[c.status] || c.status} />
                  </div>
                  <div className="vd-tl__meta">
                    {c.customer?.full_name || '—'} · {shortDate(c.starts_at)} → {shortDate(c.actual_ends_at || c.planned_ends_at)}
                  </div>
                  <div className="vd-tl__meta">
                    {c.final_total != null ? formatMoney(c.final_total) : (c.period_rent != null ? formatMoney(c.period_rent) : '—')}
                  </div>
                </div>
              </div>
            ))}
          </div>
          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>العقد</th><th>العميل</th><th>الحالة</th><th>من</th><th>إلى</th><th>الإجمالي</th>
                </tr>
              </thead>
              <tbody>
                {dossier.contracts.map((c) => (
                  <tr key={c.id}>
                    <td><span className="rpm-table__code">{c.number}</span></td>
                    <td>{c.customer?.full_name || '—'}</td>
                    <td><StatusPill status={c.status} label={statusLabel[c.status] || c.status} /></td>
                    <td>{shortDate(c.starts_at)}</td>
                    <td>{shortDate(c.actual_ends_at || c.planned_ends_at)}</td>
                    <td>{c.final_total != null ? formatMoney(c.final_total) : (c.period_rent != null ? formatMoney(c.period_rent) : '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )

  const maintenancePanel = (
    <section className="vd-panel" key="maintenance">
      <div className="vd-panel__head">
        <h2>الصيانات وأوامر الشغل</h2>
        <p>{dossier.totals.work_orders} أمر</p>
      </div>
      {dossier.work_orders.length === 0 ? <Empty>لا صيانات مسجّلة</Empty> : (
        <>
          <div className="vd-timeline rpm-mobile-only">
            {dossier.work_orders.map((w) => (
              <div key={w.id} className="vd-tl">
                <span className="vd-tl__dot" aria-hidden />
                <div className="vd-tl__body">
                  <div className="vd-tl__row">
                    <strong>#{w.id} · {w.workshop || 'ورشة'}</strong>
                    <StatusPill status={w.status} label={statusLabel[w.status] || w.status} />
                  </div>
                  <div className="vd-tl__meta">
                    {w.opened_on || '—'} → {w.closed_on || 'مفتوح'} · عداد {w.odometer != null ? formatNumber(w.odometer) : '—'}
                  </div>
                  <div className="vd-tl__meta">
                    قطع {formatMoney(w.parts_cost || 0)} · عمالة {formatMoney(w.labor_cost || 0)}
                    {w.lines.length > 0 ? ` · ${w.lines.map((l) => l.description).join('، ')}` : ''}
                  </div>
                </div>
              </div>
            ))}
          </div>
          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>#</th><th>الحالة</th><th>الورشة</th><th>الفتح</th><th>الإغلاق</th><th>العداد</th><th>قطع / عمالة</th>
                </tr>
              </thead>
              <tbody>
                {dossier.work_orders.map((w) => (
                  <tr key={w.id}>
                    <td>{w.id}</td>
                    <td><StatusPill status={w.status} label={statusLabel[w.status] || w.status} /></td>
                    <td>{w.workshop || '—'}</td>
                    <td>{w.opened_on || '—'}</td>
                    <td>{w.closed_on || '—'}</td>
                    <td>{w.odometer != null ? formatNumber(w.odometer) : '—'}</td>
                    <td>
                      {formatMoney(w.parts_cost || 0)} / {formatMoney(w.labor_cost || 0)}
                      {w.lines.length > 0 ? <p className="muted">{w.lines.map((l) => l.description).join(' · ')}</p> : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <div className="vd-panel__head" style={{ marginTop: '0.35rem' }}>
        <h2>المخالفات</h2>
        <p>{dossier.totals.violations}</p>
      </div>
      {dossier.violations.length === 0 ? <Empty>لا مخالفات</Empty> : (
        <div className="rpm-table-wrap">
          <table className="rpm-table rpm-table--compact">
            <thead><tr><th>التاريخ</th><th>الموقع</th><th>المبلغ</th><th>العميل</th><th>الحالة</th></tr></thead>
            <tbody>
              {dossier.violations.map((x) => (
                <tr key={x.id}>
                  <td>{shortDate(x.occurred_at)}</td>
                  <td>{x.location || '—'}</td>
                  <td>{x.amount != null ? formatMoney(x.amount) : '—'}</td>
                  <td>{x.customer?.full_name || '—'}</td>
                  <td>{x.status || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const expensesPanel = (
    <section className="vd-panel" key="expenses">
      <div className="vd-panel__head">
        <h2>المصروفات</h2>
        <p>الإجمالي {formatMoney(dossier.totals.expenses_amount)}</p>
      </div>
      {dossier.expenses.length === 0 ? <Empty>لا مصروفات مرتبطة بالمركبة</Empty> : (
        <div className="rpm-table-wrap">
          <table className="rpm-table">
            <thead>
              <tr><th>العنوان</th><th>التصنيف</th><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th></tr>
            </thead>
            <tbody>
              {dossier.expenses.map((e) => (
                <tr key={e.id}>
                  <td><strong className="rpm-table__primary">{e.title}</strong></td>
                  <td>{e.category || '—'}</td>
                  <td>{e.spent_on || '—'}</td>
                  <td>{formatMoney(e.amount)}</td>
                  <td>{e.payment_method || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )

  const docsPanel = (
    <section className="vd-panel" key="docs">
      <div className="vd-panel__head">
        <h2>المستندات الرسمية</h2>
        <p>{(v.documents || []).length} مستند</p>
      </div>
      {(v.documents || []).length === 0 ? <Empty>لا مستندات بعد</Empty> : (
        <div className="vd-docs">
          {(v.documents || []).map((d) => (
            <div key={d.id} className="vd-doc">
              <div>
                <strong>{d.type}</strong>
                <div><span>صدر {d.issued_on || '—'} · ينتهي {d.expires_on || '—'}{d.file_path ? ' · مرفق' : ''}</span></div>
              </div>
            </div>
          ))}
        </div>
      )}
      <div className="vd-form-block vd-no-print">
        <div className="rpm-form-grid">
          <SelectField
            label="نوع المستند"
            value={docForm.type}
            onChange={(e) => setDocForm({ ...docForm, type: e.target.value })}
            options={[
              { value: 'رخصة تسيير', label: 'رخصة تسيير' },
              { value: 'تأمين', label: 'تأمين' },
              { value: 'فحص فني', label: 'فحص فني' },
              { value: 'أخرى', label: 'أخرى' },
            ]}
          />
          <TextField label="تاريخ الإصدار" type="date" value={docForm.issued_on} onChange={(e) => setDocForm({ ...docForm, issued_on: e.target.value })} />
          <TextField label="تاريخ الانتهاء" type="date" value={docForm.expires_on} onChange={(e) => setDocForm({ ...docForm, expires_on: e.target.value })} />
          <MediaUpload
            label="ملف المستند"
            value={docForm.file_path || null}
            collection="vehicle_docs"
            purpose="document"
            accept="both"
            attachableType="vehicle"
            attachableId={v.id}
            onChange={(path) => setDocForm({ ...docForm, file_path: path || '' })}
          />
          <Button size="sm" loading={saving} onClick={() => addDocument()}>إضافة مستند</Button>
        </div>
      </div>

      <div className="vd-panel__head" style={{ marginTop: '0.5rem' }}>
        <h2>المرفقات والوسائط</h2>
        <p>{dossier.totals.media} ملف</p>
      </div>
      {dossier.media.length === 0 ? <Empty>لا مرفقات مرفوعة</Empty> : (
        <div className="vd-media">
          {dossier.media.map((m) => (
            <a key={m.id} href={m.url} target="_blank" rel="noreferrer">
              <strong>{m.original_name}</strong>
              <span>{m.kind} · {m.purpose || m.collection || '—'}</span>
            </a>
          ))}
        </div>
      )}
      <div className="vd-form-block vd-no-print">
        <MediaUpload
          label="رفع مرفق للمركبة"
          value={null}
          collection="vehicle_docs"
          purpose="document"
          accept="both"
          attachableType="vehicle"
          attachableId={v.id}
          onChange={() => { void load() }}
        />
      </div>
    </section>
  )

  const ownerPanel = (
    <section className="vd-panel" key="owner">
      <div className="vd-panel__head">
        <h2>المالك الأساسي</h2>
        <p>{v.owner ? 'عربيات الغير' : 'ملك المركز'}</p>
      </div>
      {v.owner ? (
        <div className="vd-owner">
          <div className="vd-owner__row">
            <div className="vd-owner__avatar" aria-hidden>{ownerInitial}</div>
            <div className="vd-owner__meta">
              <strong>{v.owner.full_name}</strong>
              <span>{v.owner.phone || 'بدون هاتف'} · شهري {formatMoney(v.owner_monthly_amount || 0)}</span>
              <span>ربط منذ {v.owner_since || '—'}</span>
            </div>
          </div>
          <Link className="vd-no-print rpm-link-btn" to="/owners">صفحة الملاك</Link>
        </div>
      ) : (
        <Empty>هذه المركبة ملك المركز — لا يوجد مالك خارجي مرتبط.</Empty>
      )}

      {(dossier.owner_dues.length > 0 || dossier.owner_payouts.length > 0) ? (
        <div className="vd-split">
          <div>
            <h3>المستحقات الشهرية</h3>
            {dossier.owner_dues.length === 0 ? <Empty>لا مستحقات</Empty> : (
              <div className="rpm-table-wrap">
                <table className="rpm-table rpm-table--compact">
                  <thead><tr><th>الشهر</th><th>المبلغ</th></tr></thead>
                  <tbody>
                    {dossier.owner_dues.map((d) => (
                      <tr key={d.id}><td>{d.year_month}</td><td>{formatMoney(d.amount)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
          <div>
            <h3>المدفوعات</h3>
            {dossier.owner_payouts.length === 0 ? <Empty>لا مدفوعات</Empty> : (
              <div className="rpm-table-wrap">
                <table className="rpm-table rpm-table--compact">
                  <thead><tr><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th></tr></thead>
                  <tbody>
                    {dossier.owner_payouts.map((p) => (
                      <tr key={p.id}>
                        <td>{p.paid_on || '—'}</td>
                        <td>{formatMoney(p.amount)}</td>
                        <td>{p.payment_method || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      ) : null}

      <div className="vd-panel__head" style={{ marginTop: '0.35rem' }}>
        <h2>رمز QR للطباعة</h2>
        <p>جاهز للعرض على المركبة</p>
      </div>
      <div className="vd-qr" style={{ maxWidth: 220, justifySelf: 'start' }}>
        {qrImage ? <img src={qrImage} alt="رمز QR للمركبة" width={160} height={160} /> : null}
        <p className="vd-qr__label">{dossier.qr.label}{'\n'}{dossier.qr.payload}</p>
      </div>
    </section>
  )

  const panelByTab: Record<TabId, ReactNode> = {
    overview: overviewPanel,
    bookings: bookingsPanel,
    maintenance: maintenancePanel,
    expenses: expensesPanel,
    docs: docsPanel,
    owner: ownerPanel,
  }

  return (
    <div className="vd">
      <div className="vd-print-head">
        <strong>ملف مركبة — RPM</strong>
        <span>{v.brand} {v.model} · {v.plate}</span>
      </div>

      <header className="vd-hero">
        <div className="vd-hero__top">
          <button type="button" className="vd-back vd-no-print" onClick={() => navigate('/vehicles')}>
            ← الأسطول
          </button>
          <div className="vd-hero__actions vd-no-print">
            <Button size="sm" variant="secondary" onClick={() => loadEstimated()}>عداد تقديري</Button>
            <Button size="sm" variant="secondary" onClick={() => { setEditing(true); setEditOpen(true) }}>تعديل</Button>
            <Button size="sm" onClick={() => window.print()}>طباعة</Button>
          </div>
        </div>

        <div className="vd-hero__body">
          <div className="vd-identity">
            <div className="vd-identity__status">
              <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
              <span className="vd-code">{v.code}</span>
            </div>
            <div className="vd-plate" aria-label={`لوحة ${v.plate}`}>
              <div className="vd-plate__band">EGYPT · RPM</div>
              <p className="vd-plate__num">{v.plate}</p>
            </div>
            <h1 className="vd-title">{v.brand} {v.model}</h1>
            <p className="vd-sub">{v.year} · ملف تشغيلي كامل جاهز للطباعة</p>
            <div className="vd-tags">
              <span className="vd-tag">{v.category}</span>
              <span className="vd-tag">{v.fuel_type}</span>
              <span className="vd-tag">{formatNumber(v.odometer)} كم</span>
              {v.owner ? <span className="vd-tag">مالك: {v.owner.full_name}</span> : <span className="vd-tag">ملك المركز</span>}
            </div>
          </div>

          <aside className="vd-hero__side">
            <div className="vd-rate">
              <span>الإيجار اليومي</span>
              <strong>{formatMoney(v.daily_rate)}</strong>
            </div>
            <div className="vd-qr">
              {qrImage ? <img src={qrImage} alt="QR" width={132} height={132} /> : null}
              <p className="vd-qr__label">امسح للوصول السريع</p>
            </div>
          </aside>
        </div>
      </header>

      <div className="vd-kpis">
        <div className="vd-kpi">
          <span>الحجوزات</span>
          <strong>{dossier.totals.contracts}</strong>
        </div>
        <div className="vd-kpi">
          <span>أوامر الصيانة</span>
          <strong>{dossier.totals.work_orders}</strong>
        </div>
        <div className="vd-kpi">
          <span>المصروفات</span>
          <strong>{formatMoney(dossier.totals.expenses_amount)}</strong>
        </div>
        <div className="vd-kpi">
          <span>المخالفات</span>
          <strong>{dossier.totals.violations}</strong>
          <em>{dossier.totals.media} مرفق</em>
        </div>
      </div>

      <nav className="vd-tabs vd-no-print" aria-label="أقسام ملف المركبة">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            className={`vd-tab${tab === t.id ? ' is-active' : ''}`}
            onClick={() => setTab(t.id)}
          >
            {t.label}
            {t.count != null ? <span className="vd-tab__count">{t.count}</span> : null}
          </button>
        ))}
      </nav>

      <div className="vd-no-print">
        {panelByTab[tab]}
      </div>

      <div className="vd-print-all">
        {overviewPanel}
        {bookingsPanel}
        {maintenancePanel}
        {expensesPanel}
        {docsPanel}
        {ownerPanel}
      </div>

      <Sheet
        open={editOpen}
        title="تعديل المركبة"
        onClose={() => { setEditOpen(false); setEditing(false) }}
        footer={
          <>
            <Button loading={saving} onClick={() => onUpdate()}>حفظ التعديل</Button>
            <Button variant="secondary" onClick={() => { setEditOpen(false); setEditing(false) }}>إلغاء</Button>
          </>
        }
      >
        {editing ? formFields : null}
      </Sheet>
    </div>
  )
}
