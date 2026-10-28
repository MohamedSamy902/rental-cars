import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, SummaryStrip, FilterBar, Button, StatusPill, Sheet, Fab, DetailList,
  TextField, NumberField, MoneyField, SelectField, TextAreaField, SwitchField,
  useToast, EmptyState, SkeletonBlock, ErrorState, IconChevron,
} from '@/shared/ui'
import './pages.css'

type Accessory = { id: number; name: string; default_qty?: number }
type Document = { id: number; type: string; expires_on?: string | null; issued_on?: string | null; notes?: string | null }

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
  accessories?: Accessory[] | null
  documents?: Document[] | null
}

const statusLabel: Record<string, string> = {
  available: 'متاحة',
  reserved: 'محجوزة',
  rented: 'مؤجرة',
  maintenance: 'في صيانة',
  out_of_service: 'خارج الخدمة',
}

const chips = [
  { id: 'all', label: 'الكل' },
  { id: 'available', label: 'متاحة' },
  { id: 'rented', label: 'مؤجرة' },
  { id: 'reserved', label: 'محجوزة' },
  { id: 'maintenance', label: 'صيانة' },
]

const emptyForm = {
  code: '', plate: '', brand: '', model: '', year: '', category: 'اقتصادي', fuel_type: 'بنزين',
  odometer: '', daily_rate: '', default_deposit: '', rental_priority: '50',
  tracker_device_number: '', tracker_sim_number: '', notes: '', is_active: true,
}

export function VehiclesPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Vehicle[]>([])
  const [summary, setSummary] = useState({ total: 0, available: 0, rented: 0, reserved: 0, maintenance: 0 })
  const [search, setSearch] = useState('')
  const [chip, setChip] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [detail, setDetail] = useState<Vehicle | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [editing, setEditing] = useState(false)
  const [docForm, setDocForm] = useState({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '' })
  const [accName, setAccName] = useState('')
  const [qrLabel, setQrLabel] = useState('')
  const [qrImage, setQrImage] = useState('')
  const [estimatedKm, setEstimatedKm] = useState<number | null>(null)

  async function load(nextSearch = search, nextChip = chip) {
    setLoading(true)
    setError('')
    try {
      const qs = new URLSearchParams()
      if (nextSearch.trim()) qs.set('search', nextSearch.trim())
      if (nextChip !== 'all') qs.set('status', nextChip)
      const res = await api<{ data: Vehicle[]; summary: typeof summary }>(`/vehicles?${qs}`)
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
  }, [search, chip])

  async function openDetail(id: number) {
    setDetailLoading(true)
    setEditing(false)
    setQrLabel('')
    setQrImage('')
    setEstimatedKm(null)
    try {
      const res = await api<{ data: Vehicle }>(`/vehicles/${id}`)
      setDetail(res.data)
      setForm({
        code: res.data.code,
        plate: res.data.plate,
        brand: res.data.brand,
        model: res.data.model,
        year: String(res.data.year),
        category: res.data.category,
        fuel_type: res.data.fuel_type,
        odometer: String(res.data.odometer),
        daily_rate: String(res.data.daily_rate),
        default_deposit: String(res.data.default_deposit),
        rental_priority: String(res.data.rental_priority ?? 50),
        tracker_device_number: res.data.tracker_device_number || '',
        tracker_sim_number: res.data.tracker_sim_number || '',
        notes: res.data.notes || '',
        is_active: res.data.is_active !== false,
      })
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setDetailLoading(false)
    }
  }

  async function onSave() {
    setSaving(true)
    setErrors({})
    try {
      await api('/vehicles', {
        method: 'POST',
        json: {
          ...form,
          year: Number(form.year),
          odometer: Number(form.odometer),
          daily_rate: Number(form.daily_rate),
          default_deposit: Number(form.default_deposit),
          rental_priority: Number(form.rental_priority || 50),
        },
      })
      toast.success('تم إضافة السيارة.')
      setOpen(false)
      setForm(emptyForm)
      await load()
    } catch (e) {
      const err = e as ApiError
      const next: Record<string, string> = {}
      if (err.errors) for (const [k, v] of Object.entries(err.errors)) next[k] = v[0]
      setErrors(next)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  async function onUpdate() {
    if (!detail) return
    setSaving(true)
    try {
      const res = await api<{ data: Vehicle; message: string }>(`/vehicles/${detail.id}`, {
        method: 'PUT',
        json: {
          ...form,
          year: Number(form.year),
          odometer: Number(form.odometer),
          daily_rate: Number(form.daily_rate),
          default_deposit: Number(form.default_deposit),
          rental_priority: Number(form.rental_priority || 50),
        },
      })
      toast.success(res.message || 'تم التحديث.')
      setDetail(res.data)
      setEditing(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function addDocument() {
    if (!detail) return
    setSaving(true)
    try {
      await api(`/vehicles/${detail.id}/documents`, {
        method: 'POST',
        json: {
          type: docForm.type,
          expires_on: docForm.expires_on || undefined,
          issued_on: docForm.issued_on || undefined,
          notes: docForm.notes || undefined,
        },
      })
      toast.success('تم تسجيل المستند.')
      setDocForm({ type: 'رخصة تسيير', expires_on: '', issued_on: '', notes: '' })
      await openDetail(detail.id)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  async function addAccessory() {
    if (!detail || !accName.trim()) return
    setSaving(true)
    try {
      await api(`/vehicles/${detail.id}/accessories`, {
        method: 'POST',
        json: { name: accName.trim(), default_qty: 1, required_each_rental: true },
      })
      toast.success('تم إضافة المستلزم.')
      setAccName('')
      await openDetail(detail.id)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
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
    // نمط شبكة بسيط مشتق من النص (رمز مرئي داخلي — ليس معيار QR خارجي)
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

  async function showQr() {
    if (!detail) return
    try {
      const res = await api<{ data: { qr: string; label: string } }>(`/qr?type=vehicle&id=${detail.id}`)
      setQrLabel(`${res.data.label}\n${res.data.qr}`)
      setQrImage(buildQrDataUrl(res.data.qr))
      toast.success('تم تجهيز رمز QR.')
    } catch (e) {
      toast.error((e as ApiError).message)
    }
  }

  async function loadEstimated() {
    if (!detail) return
    try {
      const res = await api<{ data: { estimated: number } }>(`/vehicles/${detail.id}/estimated-odometer`)
      setEstimatedKm(res.data.estimated)
    } catch {
      setEstimatedKm(null)
    }
  }

  function VehicleCard({ v }: { v: Vehicle }) {
    return (
      <button type="button" className="rpm-card is-clickable" onClick={() => void openDetail(v.id)}>
        <div className="rpm-card__row">
          <strong>{v.brand} {v.model}</strong>
          <StatusPill status={v.status} label={statusLabel[v.status] || v.status} />
        </div>
        <p>{v.code} · {v.plate}</p>
        <p className="muted">{v.year} · {v.category} · {v.fuel_type}</p>
        <div className="rpm-card__meta">
          <span>{formatMoney(v.daily_rate)} / يوم</span>
          <span>{formatNumber(v.odometer)} كم</span>
          <IconChevron size={16} className="rpm-card__chev" />
        </div>
      </button>
    )
  }

  const formFields = (
    <div className="rpm-form-grid">
      <TextField label="الكود الداخلي" required value={form.code} error={errors.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
      <TextField label="رقم اللوحة" required value={form.plate} error={errors.plate} onChange={(e) => setForm({ ...form, plate: e.target.value })} />
      <TextField label="الماركة" required value={form.brand} error={errors.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} />
      <TextField label="الموديل" required value={form.model} error={errors.model} onChange={(e) => setForm({ ...form, model: e.target.value })} />
      <NumberField label="سنة الصنع" required value={form.year} error={errors.year} onChange={(e) => setForm({ ...form, year: e.target.value })} />
      <SelectField label="الفئة" required options={[{ value: 'اقتصادي', label: 'اقتصادي' }, { value: 'SUV', label: 'SUV' }, { value: 'فاخر', label: 'فاخر' }]} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
      <SelectField label="نوع الوقود" required options={[{ value: 'بنزين', label: 'بنزين' }, { value: 'ديزل', label: 'ديزل' }, { value: 'هجين', label: 'هجين' }]} value={form.fuel_type} onChange={(e) => setForm({ ...form, fuel_type: e.target.value })} />
      <NumberField label="العداد الفعلي" required value={form.odometer} error={errors.odometer} onChange={(e) => setForm({ ...form, odometer: e.target.value })} />
      <MoneyField label="الإيجار اليومي الافتراضي" required value={form.daily_rate} error={errors.daily_rate} onChange={(e) => setForm({ ...form, daily_rate: e.target.value })} />
      <MoneyField label="التأمين النقدي الافتراضي" required value={form.default_deposit} error={errors.default_deposit} onChange={(e) => setForm({ ...form, default_deposit: e.target.value })} />
      <NumberField label="درجة أولوية التأجير" value={form.rental_priority} onChange={(e) => setForm({ ...form, rental_priority: e.target.value })} hint="من ٠ إلى ١٠٠" />
      <TextField label="رقم جهاز التتبع" value={form.tracker_device_number} onChange={(e) => setForm({ ...form, tracker_device_number: e.target.value })} />
      <TextField label="رقم شريحة التتبع" value={form.tracker_sim_number} onChange={(e) => setForm({ ...form, tracker_sim_number: e.target.value })} />
      <TextAreaField label="ملاحظات" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      <SwitchField label="نشطة" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
    </div>
  )

  return (
    <div className="rpm-stack">
      <PageHeader
        title="المركبات"
        subtitle="أسطول التشغيل — مستندات · مستلزمات · QR"
        action={<Button onClick={() => setOpen(true)}>إضافة مركبة</Button>}
        mobileFab
      />
      <Fab label="إضافة مركبة" onClick={() => setOpen(true)} />

      <SummaryStrip
        items={[
          { label: 'الإجمالي', value: String(summary.total) },
          { label: 'متاحة', value: String(summary.available), tone: 'success' },
          { label: 'مؤجرة', value: String(summary.rented) },
          { label: 'صيانة', value: String(summary.maintenance), tone: 'danger' },
        ]}
      />

      <FilterBar
        search={search}
        onSearchChange={setSearch}
        chips={chips}
        activeChip={chip}
        onChipChange={setChip}
        placeholder="بحث بالكود أو اللوحة أو الموديل…"
      />

      {loading ? <SkeletonBlock rows={6} /> : null}
      {!loading && error ? <ErrorState title="تعذّر تحميل المركبات" description={error} action={<Button onClick={() => load()}>إعادة المحاولة</Button>} /> : null}
      {!loading && !error && rows.length === 0 ? <EmptyState title="لا توجد مركبات مطابقة" /> : null}

      {!loading && !error && rows.length > 0 ? (
        <>
          <div className="rpm-card-list rpm-mobile-only">
            {rows.map((v) => <VehicleCard key={v.id} v={v} />)}
          </div>
          <div className="rpm-table-wrap rpm-desktop-only">
            <table className="rpm-table">
              <thead>
                <tr>
                  <th>الكود</th><th>اللوحة</th><th>المركبة</th><th>الفئة</th><th>الحالة</th><th>العداد</th><th>اليومي</th><th></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id}>
                    <td>{v.code}</td>
                    <td>{v.plate}</td>
                    <td>{v.brand} {v.model} {v.year}</td>
                    <td>{v.category}</td>
                    <td><StatusPill status={v.status} label={statusLabel[v.status] || v.status} /></td>
                    <td>{formatNumber(v.odometer)}</td>
                    <td>{formatMoney(v.daily_rate)}</td>
                    <td><Button size="sm" variant="ghost" onClick={() => void openDetail(v.id)}>عرض</Button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}

      <Sheet
        open={open}
        title="إضافة مركبة"
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button loading={saving} onClick={onSave}>حفظ</Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button>
          </>
        }
      >
        {formFields}
      </Sheet>

      <Sheet
        open={!!detail || detailLoading}
        title={detail ? `${detail.brand} ${detail.model}` : 'تفاصيل المركبة'}
        onClose={() => setDetail(null)}
        closeLabel="إغلاق"
        footer={
          detail ? (
            <>
              {editing ? (
                <Button loading={saving} onClick={() => void onUpdate()}>حفظ التعديل</Button>
              ) : (
                <Button variant="secondary" onClick={() => setEditing(true)}>تعديل</Button>
              )}
              <Button variant="secondary" onClick={() => void showQr()}>QR</Button>
              <Button variant="secondary" onClick={() => void loadEstimated()}>عداد تقديري</Button>
            </>
          ) : null
        }
      >
        {detailLoading && !detail ? <SkeletonBlock rows={6} /> : null}
        {detail && editing ? formFields : null}
        {detail && !editing ? (
          <>
            <DetailList
              rows={[
                { label: 'الحالة', value: <StatusPill status={detail.status} label={statusLabel[detail.status] || detail.status} /> },
                { label: 'الكود', value: detail.code },
                { label: 'اللوحة', value: detail.plate },
                { label: 'الماركة / الموديل', value: `${detail.brand} ${detail.model}` },
                { label: 'سنة الصنع', value: detail.year },
                { label: 'الفئة', value: detail.category },
                { label: 'الوقود', value: detail.fuel_type },
                { label: 'العداد', value: `${formatNumber(detail.odometer)} كم` },
                { label: 'الإيجار اليومي', value: formatMoney(detail.daily_rate) },
                { label: 'التأمين النقدي', value: formatMoney(detail.default_deposit) },
                { label: 'أولوية التأجير', value: detail.rental_priority },
                { label: 'جهاز التتبع', value: detail.tracker_device_number || '—' },
                { label: 'شريحة التتبع', value: detail.tracker_sim_number || '—' },
                { label: 'نشطة', value: detail.is_active === false ? 'لا' : 'نعم' },
                { label: 'ملاحظات', value: detail.notes || '—' },
              ]}
            />
            {estimatedKm != null ? <p className="muted">العداد التقديري أثناء التأجير: {formatNumber(estimatedKm)} كم</p> : null}
            {qrLabel ? <pre className="rpm-qr-box">{qrLabel}</pre> : null}
            {qrImage ? <img className="rpm-qr-img" src={qrImage} alt="رمز QR" width={180} height={180} /> : null}

            <section className="rpm-panel" style={{ marginTop: '0.75rem' }}>
              <div className="rpm-panel__head"><h3>المستندات</h3></div>
              {(detail.documents || []).length === 0 ? <p className="muted">لا مستندات بعد</p> : null}
              <div className="rpm-dense-list">
                {(detail.documents || []).map((d) => (
                  <div key={d.id} className="rpm-dense-row">
                    <div>
                      <strong>{d.type}</strong>
                      <p className="muted">ينتهي {d.expires_on || '—'} · صدر {d.issued_on || '—'}</p>
                    </div>
                  </div>
                ))}
              </div>
              <div className="rpm-form-grid" style={{ marginTop: '0.75rem' }}>
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
                <Button size="sm" loading={saving} onClick={() => void addDocument()}>إضافة مستند</Button>
              </div>
            </section>

            <section className="rpm-panel" style={{ marginTop: '0.75rem' }}>
              <div className="rpm-panel__head"><h3>المستلزمات</h3></div>
              <div className="rpm-dense-list">
                {(detail.accessories || []).map((a) => (
                  <div key={a.id} className="rpm-dense-row"><div><strong>{a.name}</strong><p className="muted">كمية افتراضية {a.default_qty ?? 1}</p></div></div>
                ))}
              </div>
              <div className="rpm-form-grid" style={{ marginTop: '0.75rem' }}>
                <TextField label="اسم المستلزم" value={accName} onChange={(e) => setAccName(e.target.value)} />
                <Button size="sm" loading={saving} onClick={() => void addAccessory()}>إضافة مستلزم</Button>
              </div>
            </section>
          </>
        ) : null}
      </Sheet>
    </div>
  )
}
