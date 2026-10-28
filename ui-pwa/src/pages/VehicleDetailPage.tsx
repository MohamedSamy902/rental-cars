import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, Button, StatusPill, Sheet, DetailList,
  TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField, MediaUpload,
  useToast, EmptyState, SkeletonBlock, ErrorState,
} from '@/shared/ui'
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

export function VehicleDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const vehicleId = Number(id)

  const [dossier, setDossier] = useState<Dossier | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
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
    } catch {
      setEstimatedKm(null)
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
      <div className="rpm-stack">
        <PageHeader title="ملف المركبة" subtitle="جارٍ التحميل…" />
        <SkeletonBlock rows={8} />
      </div>
    )
  }

  if (error || !dossier) {
    return (
      <div className="rpm-stack">
        <PageHeader
          title="ملف المركبة"
          action={<Button variant="secondary" onClick={() => navigate('/vehicles')}>رجوع للأسطول</Button>}
        />
        <ErrorState
          title="تعذّر تحميل ملف المركبة"
          description={error || 'لا توجد بيانات'}
          action={<Button onClick={() => void load()}>إعادة المحاولة</Button>}
        />
      </div>
    )
  }

  const v = dossier.vehicle

  return (
    <div className="rpm-stack rpm-vehicle-dossier">
      <div className="rpm-print-only rpm-print-brand">
        <strong>ملف مركبة</strong>
        <span>{v.brand} {v.model} · {v.plate}</span>
      </div>

      <PageHeader
        title={`${v.brand} ${v.model}`}
        subtitle={`${v.code} · ${v.plate} · ${v.year}`}
        action={
          <div className="rpm-actions-row rpm-no-print">
            <Button variant="secondary" onClick={() => navigate('/vehicles')}>رجوع</Button>
            <Button variant="secondary" onClick={() => void loadEstimated()}>عداد تقديري</Button>
            <Button variant="secondary" onClick={() => { setEditing(true); setEditOpen(true) }}>تعديل</Button>
            <Button onClick={() => window.print()}>طباعة</Button>
          </div>
        }
      />

      <section className="rpm-panel rpm-dossier-hero">
        <div className="rpm-dossier-hero__main">
          <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
          <DetailList
            rows={[
              { label: 'الكود', value: v.code },
              { label: 'اللوحة', value: v.plate },
              { label: 'الفئة / الوقود', value: `${v.category} · ${v.fuel_type}` },
              { label: 'العداد', value: `${formatNumber(v.odometer)} كم` },
              { label: 'الإيجار اليومي', value: formatMoney(v.daily_rate) },
              { label: 'التأمين النقدي', value: formatMoney(v.default_deposit) },
              { label: 'أولوية التأجير', value: v.rental_priority },
              { label: 'جهاز التتبع', value: v.tracker_device_number || '—' },
              { label: 'شريحة التتبع', value: v.tracker_sim_number || '—' },
              { label: 'نشطة', value: v.is_active === false ? 'لا' : 'نعم' },
              { label: 'ملاحظات', value: v.notes || '—' },
            ]}
          />
          {estimatedKm != null ? (
            <p className="muted">العداد التقديري أثناء التأجير: {formatNumber(estimatedKm)} كم</p>
          ) : null}
        </div>
        <aside className="rpm-dossier-qr">
          <h3>رمز QR</h3>
          {qrImage ? <img className="rpm-qr-img" src={qrImage} alt="رمز QR للمركبة" width={180} height={180} /> : null}
          <pre className="rpm-qr-box">{dossier.qr.label}{'\n'}{dossier.qr.payload}</pre>
        </aside>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المالك الأساسي</h3></div>
        {v.owner ? (
          <DetailList
            rows={[
              { label: 'الاسم', value: v.owner.full_name },
              { label: 'الهاتف', value: v.owner.phone || '—' },
              { label: 'المبلغ الشهري', value: formatMoney(v.owner_monthly_amount || 0) },
              { label: 'تاريخ الربط', value: v.owner_since || '—' },
              {
                label: 'صفحة المالك',
                value: <Link className="rpm-no-print" to="/owners">عرض الملاك</Link>,
              },
            ]}
          />
        ) : (
          <p className="muted">ملك المركز — لا يوجد مالك خارجي مرتبط.</p>
        )}
        {dossier.owner_dues.length > 0 || dossier.owner_payouts.length > 0 ? (
          <div className="rpm-dossier-split">
            <div>
              <h4>المستحقات الشهرية</h4>
              {dossier.owner_dues.length === 0 ? <p className="muted">لا مستحقات</p> : (
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
              <h4>المدفوعات للمالك</h4>
              {dossier.owner_payouts.length === 0 ? <p className="muted">لا مدفوعات</p> : (
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
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>سجل الحجز / العقود ({dossier.totals.contracts})</h3>
        </div>
        {dossier.contracts.length === 0 ? <EmptyState title="لا حجوزات لهذه المركبة" /> : (
          <div className="rpm-table-wrap">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>العقد</th><th>العميل</th><th>الحالة</th><th>من</th><th>إلى</th><th>الإجمالي</th>
                </tr>
              </thead>
              <tbody>
                {dossier.contracts.map((c) => (
                  <tr key={c.id}>
                    <td>{c.number}</td>
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
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>الصيانات / أوامر الشغل ({dossier.totals.work_orders})</h3>
        </div>
        {dossier.work_orders.length === 0 ? <EmptyState title="لا صيانات مسجّلة" /> : (
          <div className="rpm-table-wrap">
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
                      {w.lines.length > 0 ? (
                        <p className="muted">{w.lines.map((l) => l.description).join(' · ')}</p>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>المصروفات ({formatMoney(dossier.totals.expenses_amount)})</h3>
        </div>
        {dossier.expenses.length === 0 ? <EmptyState title="لا مصروفات مرتبطة" /> : (
          <div className="rpm-table-wrap">
            <table className="rpm-table">
              <thead>
                <tr><th>العنوان</th><th>التصنيف</th><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th></tr>
              </thead>
              <tbody>
                {dossier.expenses.map((e) => (
                  <tr key={e.id}>
                    <td>{e.title}</td>
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

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>المخالفات ({dossier.totals.violations})</h3>
        </div>
        {dossier.violations.length === 0 ? <p className="muted">لا مخالفات</p> : (
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

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المرفقات والوسائط ({dossier.totals.media})</h3></div>
        {dossier.media.length === 0 ? <p className="muted">لا مرفقات مرفوعة على المركبة</p> : (
          <div className="rpm-media-grid">
            {dossier.media.map((m) => (
              <a key={m.id} className="rpm-media-chip" href={m.url} target="_blank" rel="noreferrer">
                <strong>{m.original_name}</strong>
                <span className="muted">{m.kind} · {m.purpose || m.collection || '—'}</span>
              </a>
            ))}
          </div>
        )}
        <div className="rpm-no-print" style={{ marginTop: '0.75rem' }}>
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

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستندات الرسمية</h3></div>
        {(v.documents || []).length === 0 ? <p className="muted">لا مستندات بعد</p> : (
          <div className="rpm-dense-list">
            {(v.documents || []).map((d) => (
              <div key={d.id} className="rpm-dense-row">
                <div>
                  <strong>{d.type}</strong>
                  <p className="muted">ينتهي {d.expires_on || '—'} · صدر {d.issued_on || '—'}{d.file_path ? ' · مرفق' : ''}</p>
                </div>
              </div>
            ))}
          </div>
        )}
        <div className="rpm-form-grid rpm-no-print" style={{ marginTop: '0.75rem' }}>
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
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستلزمات</h3></div>
        <div className="rpm-dense-list">
          {(v.accessories || []).map((a) => (
            <div key={a.id} className="rpm-dense-row">
              <div><strong>{a.name}</strong><p className="muted">كمية افتراضية {a.default_qty ?? 1}</p></div>
            </div>
          ))}
        </div>
        <div className="rpm-form-grid rpm-no-print" style={{ marginTop: '0.75rem' }}>
          <TextField label="اسم المستلزم" value={accName} onChange={(e) => setAccName(e.target.value)} />
          <Button size="sm" loading={saving} onClick={() => addAccessory()}>إضافة مستلزم</Button>
        </div>
      </section>

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
