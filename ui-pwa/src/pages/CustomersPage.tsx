import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { PageHeader, FilterBar, Button, StatusPill, Sheet, TextField, useToast, EmptyState, SkeletonBlock } from '@/shared/ui'
import './pages.css'

type Customer = {
  id: number
  full_name: string
  phone: string
  license_number: string
  license_expires_on: string
  status: string
  national_id?: string
}

export function CustomersPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Customer[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
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
      <PageHeader title="العملاء" subtitle="بلا رصيد دائن على النظام · قائمة سوداء بصلاحية" action={<Button onClick={() => setOpen(true)}>إضافة عميل</Button>} />
      <FilterBar search={search} onSearchChange={setSearch} chips={[{ id: 'all', label: 'الكل' }]} activeChip="all" onChipChange={() => undefined} placeholder="بحث بالاسم أو الهاتف…" />
      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا يوجد عملاء" /> : null}
      <div className="rpm-card-list">
        {rows.map((c) => (
          <article key={c.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{c.full_name}</strong>
              <StatusPill status={c.status === 'blacklist' ? 'cancelled' : 'active'} label={c.status === 'blacklist' ? 'قائمة سوداء' : 'نشط'} />
            </div>
            <p>{c.phone}</p>
            <p className="muted">رخصة {c.license_number} · تنتهي {c.license_expires_on}</p>
          </article>
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
    </div>
  )
}
