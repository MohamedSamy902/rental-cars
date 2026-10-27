import { useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  PageHeader, Button, SelectField, TextField, useToast, EmptyState, SkeletonBlock,
} from '@/shared/ui'
import { formatDateTime } from '@/shared/lib/format'
import './pages.css'

type CustomerRow = {
  id: number
  full_name: string
  phone: string
  national_id?: string | null
  license_number?: string | null
  license_expires_on?: string | null
  birth_date?: string | null
  status: string
}

export function DepartmentNoticePage() {
  const toast = useToast()
  const [filter, setFilter] = useState('today')
  const [customerId, setCustomerId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [center, setCenter] = useState('')
  const [generatedAt, setGeneratedAt] = useState('')
  const [rows, setRows] = useState<CustomerRow[]>([])
  const [loading, setLoading] = useState(false)
  const [customers, setCustomers] = useState<{ id: number; full_name: string }[]>([])

  async function ensureCustomers() {
    if (customers.length) return
    const res = await api<{ data: { id: number; full_name: string }[] }>('/customers?per_page=100')
    setCustomers(res.data)
  }

  async function load() {
    setLoading(true)
    try {
      const qs = new URLSearchParams({ filter })
      if (filter === 'one') qs.set('customer_id', customerId)
      if (filter === 'range') {
        qs.set('from', from)
        qs.set('to', to)
      }
      const res = await api<{ data: { center_name?: string; generated_at: string; customers: CustomerRow[] } }>(
        `/department-notice?${qs}`,
      )
      setCenter(res.data.center_name || '')
      setGeneratedAt(res.data.generated_at)
      setRows(res.data.customers || [])
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  function printNotice() {
    const w = window.open('', '_blank', 'noopener,noreferrer,width=900,height=700')
    if (!w) {
      toast.error('اسمح بالنوافذ المنبثقة للطباعة.')
      return
    }
    const body = rows.map((c) => `
      <section style="break-inside:avoid;margin:0 0 18px;padding:14px;border:1px solid #cbd5e1;border-radius:12px">
        <h2 style="margin:0 0 8px;font-size:18px">${c.full_name}</h2>
        <p>الهاتف: ${c.phone || '—'}</p>
        <p>الرقم القومي: ${c.national_id || '—'}</p>
        <p>الرخصة: ${c.license_number || '—'} · تنتهي ${c.license_expires_on || '—'}</p>
        <p>تاريخ الميلاد: ${c.birth_date || '—'}</p>
      </section>
    `).join('')
    w.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>إخطار القسم</title>
      <style>body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a} h1{margin:0 0 4px} .muted{color:#64748b}</style>
      </head><body>
      <h1>إخطار القسم</h1>
      <p class="muted">${center || ''} · ${generatedAt ? formatDateTime(generatedAt) : ''}</p>
      ${body || '<p>لا توجد بيانات</p>'}
      <script>window.onload=()=>window.print()<\/script>
      </body></html>`)
    w.document.close()
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="إخطار القسم"
        subtitle="مستند جاهز للطباعة · عميل واحد أو مجموعة حسب الفترة"
        action={<Button onClick={printNotice} disabled={!rows.length}>طباعة</Button>}
      />
      <section className="rpm-panel">
        <div className="rpm-form-grid">
          <SelectField
            label="نطاق الطباعة"
            value={filter}
            onChange={(e) => {
              const v = e.target.value
              setFilter(v)
              if (v === 'one') void ensureCustomers()
            }}
            options={[
              { value: 'one', label: 'عميل واحد' },
              { value: 'today', label: 'من أجّروا اليوم' },
              { value: 'week', label: 'هذا الأسبوع' },
              { value: 'all', label: 'كل العملاء' },
              { value: 'range', label: 'فترة مخصصة' },
            ]}
          />
          {filter === 'one' ? (
            <SelectField
              label="العميل"
              value={customerId}
              onChange={(e) => setCustomerId(e.target.value)}
              options={customers.map((c) => ({ value: String(c.id), label: c.full_name }))}
            />
          ) : null}
          {filter === 'range' ? (
            <>
              <TextField label="من تاريخ" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <TextField label="إلى تاريخ" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </>
          ) : null}
          <div>
            <Button loading={loading} onClick={() => void load()}>تحضير الإخطار</Button>
          </div>
        </div>
      </section>

      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="اختر الفلتر ثم حضّر الإخطار" /> : null}
      {!loading && rows.length > 0 ? (
        <div className="rpm-card-list">
          {rows.map((c) => (
            <article key={c.id} className="rpm-card">
              <strong>{c.full_name}</strong>
              <p>{c.phone}</p>
              <p className="muted">قومي {c.national_id || '—'} · رخصة {c.license_number || '—'}</p>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
