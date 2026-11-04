import { useState } from 'react'
import { api, getToken, type ApiError } from '@/shared/api/client'
import {
  PageHeader, Button, SelectField, TextField, useToast, EmptyState, SkeletonBlock,
} from '@/shared/ui'
import { escapeHtml } from '@/shared/lib/escapeHtml'
import { formatDateTime } from '@/shared/lib/format'
import { printHtmlDocument } from '@/utils/printHtml'
import './pages.css'

type CustomerRow = {
  id: number
  full_name: string
  phone: string
  national_id?: string | null
  national_id_image_path?: string | null
  national_id_back_path?: string | null
  license_number?: string | null
  license_expires_on?: string | null
  license_image_path?: string | null
  license_back_path?: string | null
  birth_date?: string | null
  status: string
}

async function resolveMediaSrc(path?: string | null): Promise<string | null> {
  if (!path) return null
  try {
    const resolved = path.startsWith('/api/')
      ? path
      : path.startsWith('/media/')
        ? `/api${path}`
        : path.startsWith('http') || path.startsWith('data:') || path.startsWith('blob:')
          ? path
          : `/api${path.startsWith('/') ? path : `/${path}`}`
    if (resolved.startsWith('http') || resolved.startsWith('data:') || resolved.startsWith('blob:')) {
      return resolved
    }
    const headers: HeadersInit = { Accept: '*/*' }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
    const res = await fetch(resolved, { headers })
    if (!res.ok) return null
    const blob = await res.blob()
    return URL.createObjectURL(blob)
  } catch {
    return null
  }
}

function imgBlock(label: string, src: string | null): string {
  if (!src) {
    return `<div style="border:1px dashed #94a3b8;border-radius:10px;padding:18px;text-align:center;color:#64748b;font-size:12px">${escapeHtml(label)} — غير متوفرة</div>`
  }
  return `<figure style="margin:0"><figcaption style="font-size:12px;color:#64748b;margin-bottom:4px">${escapeHtml(label)}</figcaption><img src="${src}" alt="${escapeHtml(label)}" style="width:100%;max-height:180px;object-fit:contain;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc" /></figure>`
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
  const [printing, setPrinting] = useState(false)
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

  async function printNotice() {
    setPrinting(true)
    try {
      const sections = await Promise.all(rows.map(async (c) => {
        const [idFront, idBack, licFront, licBack] = await Promise.all([
          resolveMediaSrc(c.national_id_image_path),
          resolveMediaSrc(c.national_id_back_path),
          resolveMediaSrc(c.license_image_path),
          resolveMediaSrc(c.license_back_path),
        ])
        return `
      <section style="break-inside:avoid;margin:0 0 18px;padding:14px;border:1px solid #cbd5e1;border-radius:12px">
        <h2 style="margin:0 0 8px;font-size:18px">${escapeHtml(c.full_name)}</h2>
        <p>الهاتف: ${escapeHtml(c.phone || '—')}</p>
        <p>الرقم القومي: ${escapeHtml(c.national_id || '—')}</p>
        <p>الرخصة: ${escapeHtml(c.license_number || '—')} · تنتهي ${escapeHtml(c.license_expires_on || '—')}</p>
        <p>تاريخ الميلاد: ${escapeHtml(c.birth_date || '—')}</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px">
          ${imgBlock('بطاقة — وش', idFront)}
          ${imgBlock('بطاقة — ضهر', idBack)}
          ${imgBlock('رخصة — وش', licFront)}
          ${imgBlock('رخصة — ضهر', licBack)}
        </div>
      </section>`
      }))
      const meta = `${escapeHtml(center || '')} · ${escapeHtml(generatedAt ? formatDateTime(generatedAt) : '')}`
      printHtmlDocument(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>إخطار القسم</title>
      <style>body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a} h1{margin:0 0 4px} .muted{color:#64748b}</style>
      </head><body>
      <h1>إخطار القسم</h1>
      <p class="muted">${meta}</p>
      ${sections.join('') || '<p>لا توجد بيانات</p>'}
      </body></html>`)
    } catch (e) {
      toast.error((e as Error).message || 'تعذّر طباعة إخطار القسم.')
    } finally {
      setPrinting(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="إخطار القسم"
        subtitle="مستند جاهز للطباعة مع صور البطاقة والرخصة · عميل واحد أو مجموعة حسب الفترة"
        action={<Button onClick={() => void printNotice()} loading={printing} disabled={!rows.length}>طباعة</Button>}
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
              <p className="muted">
                بطاقة: {c.national_id_image_path ? 'وش ✓' : 'وش —'}{c.national_id_back_path ? ' · ضهر ✓' : ' · ضهر —'}
                {' · '}
                رخصة: {c.license_image_path ? 'وش ✓' : 'وش —'}{c.license_back_path ? ' · ضهر ✓' : ' · ضهر —'}
              </p>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
