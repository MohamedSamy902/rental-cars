import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  PageHeader, FilterBar, Button, StatusPill, Sheet, Fab, DetailList, TextField,
  useToast, EmptyState, SkeletonBlock, IconChevron,
} from '@/shared/ui'
import './pages.css'

type Customer = {
  id: number
  full_name: string
  phone: string
  phone_alt?: string | null
  email?: string | null
  license_number: string
  license_expires_on: string
  birth_date?: string
  national_id?: string | null
  status: string
  notes?: string | null
  blacklist_reason?: string | null
}

export function CustomersPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Customer[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [detail, setDetail] = useState<Customer | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [form, setForm] = useState({
    full_name: '', phone: '', birth_date: '1990-01-01', license_number: '', license_expires_on: '2030-01-01', national_id: '',
  })

  async function load() {
    setLoading(true)
    try {
      const qs = search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''
      const res = await api<{ data: Customer[] }>(`/customers${qs}`)
      setRows(res.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(load, 250)
    return () => window.clearTimeout(t)
  }, [search])

  async function openDetail(id: number) {
    setDetailLoading(true)
    try {
      const res = await api<{ data: Customer }>(`/customers/${id}`)
      setDetail(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setDetailLoading(false)
    }
  }

  async function onSave() {
    setSaving(true)
    try {
      await api('/customers', { method: 'POST', json: form })
      toast.success('تم تسجيل العميل.')
      setOpen(false)
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="العملاء"
        subtitle="بلا رصيد دائن على النظام · قائمة سوداء بصلاحية"
        action={<Button onClick={() => setOpen(true)}>إضافة عميل</Button>}
        mobileFab
      />
      <Fab label="إضافة عميل" onClick={() => setOpen(true)} />
      <FilterBar search={search} onSearchChange={setSearch} chips={[{ id: 'all', label: 'الكل' }]} activeChip="all" onChipChange={() => undefined} placeholder="بحث بالاسم أو الهاتف…" />
      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا يوجد عملاء" /> : null}
      <div className="rpm-card-list">
        {rows.map((c) => (
          <button key={c.id} type="button" className="rpm-card is-clickable" onClick={() => void openDetail(c.id)}>
            <div className="rpm-card__row">
              <strong>{c.full_name}</strong>
              <StatusPill status={c.status === 'blacklist' ? 'cancelled' : 'active'} label={c.status === 'blacklist' ? 'قائمة سوداء' : 'نشط'} />
            </div>
            <p>{c.phone}</p>
            <div className="rpm-card__meta">
              <span className="muted">رخصة {c.license_number} · تنتهي {c.license_expires_on}</span>
              <IconChevron size={16} className="rpm-card__chev" />
            </div>
          </button>
        ))}
      </div>
      <Sheet open={open} title="إضافة عميل" onClose={() => setOpen(false)} footer={<><Button loading={saving} onClick={onSave}>حفظ</Button><Button variant="secondary" onClick={() => setOpen(false)}>إلغاء</Button></>}>
        <div className="rpm-form-grid">
          <TextField label="الاسم الكامل" required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          <TextField label="الهاتف" required value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <TextField label="الرقم القومي" value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value })} />
          <TextField label="تاريخ الميلاد" required type="date" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          <TextField label="رقم الرخصة" required value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value })} />
          <TextField label="انتهاء الرخصة" required type="date" value={form.license_expires_on} onChange={(e) => setForm({ ...form, license_expires_on: e.target.value })} />
        </div>
      </Sheet>

      <Sheet
        open={!!detail || detailLoading}
        title={detail?.full_name || 'تفاصيل العميل'}
        onClose={() => setDetail(null)}
        closeLabel="إغلاق"
      >
        {detailLoading && !detail ? <SkeletonBlock rows={5} /> : null}
        {detail ? (
          <DetailList
            rows={[
              { label: 'الحالة', value: <StatusPill status={detail.status === 'blacklist' ? 'cancelled' : 'active'} label={detail.status === 'blacklist' ? 'قائمة سوداء' : 'نشط'} /> },
              { label: 'الاسم', value: detail.full_name },
              { label: 'الهاتف', value: detail.phone },
              { label: 'هاتف بديل', value: detail.phone_alt || '—' },
              { label: 'البريد', value: detail.email || '—' },
              { label: 'الرقم القومي', value: detail.national_id || '—' },
              { label: 'تاريخ الميلاد', value: detail.birth_date || '—' },
              { label: 'رقم الرخصة', value: detail.license_number },
              { label: 'انتهاء الرخصة', value: detail.license_expires_on },
              { label: 'سبب القائمة السوداء', value: detail.blacklist_reason || '—' },
              { label: 'ملاحظات', value: detail.notes || '—' },
            ]}
          />
        ) : null}
      </Sheet>
    </div>
  )
}
