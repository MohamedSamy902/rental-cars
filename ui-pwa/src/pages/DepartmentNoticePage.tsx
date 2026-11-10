import { useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import {
  PageHeader, Button, SelectField, TextField, useToast, EmptyState, SkeletonBlock,
} from '@/shared/ui'
import { escapeHtml } from '@/shared/lib/escapeHtml'
import { formatDate, formatDateTime } from '@/shared/lib/format'
import { printHtmlDocument } from '@/utils/printHtml'
import './pages.css'

type StatementRow = {
  contract_id: number
  contract_number?: string | null
  renter_name?: string | null
  national_id?: string | null
  vehicle_type?: string | null
  plate?: string | null
  rental_duration_days?: number | null
  starts_on?: string | null
  ends_on?: string | null
  address?: string | null
  notes?: string | null
  status?: string | null
}

type NoticePayload = {
  center_name?: string
  signer_name?: string
  tagline?: string
  phones?: string[]
  generated_at: string
  statements: StatementRow[]
}

function cell(label: string, value: string): string {
  return `<tr>
    <th style="width:32%;background:#f1f5f9;border:1px solid #94a3b8;padding:8px 10px;text-align:right;font-weight:700">${escapeHtml(label)}</th>
    <td style="border:1px solid #94a3b8;padding:8px 10px;text-align:right">${escapeHtml(value || '—')}</td>
  </tr>`
}

function statementHtml(row: StatementRow, meta: NoticePayload): string {
  const phones = (meta.phones || []).map((p) => `<span dir="ltr">${escapeHtml(p)}</span>`).join(' &nbsp;|&nbsp; ')
  const duration = row.rental_duration_days != null ? `${row.rental_duration_days} يوم` : '—'
  return `
  <article class="sheet" style="break-inside:avoid;page-break-inside:avoid;margin:0 0 28px;padding:18px 16px;border:1px solid #cbd5e1;border-radius:8px">
    <header style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start;margin-bottom:14px">
      <div style="text-align:right">
        <div style="font-size:18px;font-weight:800">${escapeHtml(meta.signer_name || 'أ/ محمد عبدالقادر')}</div>
        <div style="font-size:13px;color:#334155;margin-top:2px">${escapeHtml(meta.tagline || 'لتجارة وإيجار أحدث أنواع السيارات')}</div>
        <div style="font-size:13px;margin-top:6px;color:#0f172a">${phones || '—'}</div>
      </div>
      <div style="text-align:left;min-width:110px">
        <div style="font-size:20px;font-weight:900;letter-spacing:0.04em">RPM</div>
        <div style="font-size:11px;color:#64748b">${escapeHtml(meta.center_name || '')}</div>
      </div>
    </header>
    <h2 style="margin:0 0 12px;text-align:center;font-size:20px;font-weight:800">بيان سيارة مؤجرة</h2>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      ${cell('اسم المستأجر', row.renter_name || '—')}
      ${cell('الرقم القومي', row.national_id || '—')}
      ${cell('نوع السيارة', row.vehicle_type || '—')}
      ${cell('رقم السيارة', row.plate || '—')}
      ${cell('مدة الايجار', duration)}
      ${cell('تاريخ الايجار', row.starts_on ? formatDate(row.starts_on) : '—')}
      ${cell('نهايه تاريخ الايجار', row.ends_on ? formatDate(row.ends_on) : '—')}
      ${cell('عنوان المستأجر', row.address || '—')}
      ${cell('الملاحظات', row.notes || '—')}
    </table>
    <p style="margin:14px 0 18px;text-align:center;font-size:13px;color:#334155">بيان إيجار السيارة بالعرض للمتابعه و العلم</p>
    <footer style="display:flex;justify-content:space-between;gap:16px;font-size:13px;font-weight:700;margin-top:8px">
      <span>إدارة المعرض</span>
      <span>رئيس المباحث</span>
    </footer>
  </article>`
}

export function DepartmentNoticePage() {
  const toast = useToast()
  const [filter, setFilter] = useState('today')
  const [customerId, setCustomerId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [payload, setPayload] = useState<NoticePayload | null>(null)
  const [loading, setLoading] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [customers, setCustomers] = useState<{ id: number; full_name: string }[]>([])

  const rows = payload?.statements || []

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
      const res = await api<{ data: NoticePayload }>(`/department-notice?${qs}`)
      setPayload(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  function printNotice() {
    if (!payload || !rows.length) return
    setPrinting(true)
    try {
      const body = rows.map((r) => statementHtml(r, payload)).join('')
      printHtmlDocument(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>بيان سيارة مؤجرة</title>
      <style>
        body{font-family:Tahoma,Arial,sans-serif;padding:18px;color:#0f172a;background:#fff}
        @media print { body{padding:0} .sheet{border:none!important;margin:0 0 18mm!important} }
      </style></head><body>
      <p style="text-align:center;color:#64748b;font-size:12px;margin:0 0 12px">
        ${escapeHtml(payload.center_name || '')} · ${escapeHtml(payload.generated_at ? formatDateTime(payload.generated_at) : '')}
      </p>
      ${body || '<p>لا توجد بيانات</p>'}
      </body></html>`)
    } catch (e) {
      toast.error((e as Error).message || 'تعذّر طباعة بيان السيارة المؤجرة.')
    } finally {
      setPrinting(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="إخطار القسم"
        subtitle="بيان سيارة مؤجرة للطباعة بنفس بيانات النموذج الورقي"
        action={<Button onClick={() => printNotice()} loading={printing} disabled={!rows.length}>طباعة</Button>}
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
              { value: 'all', label: 'كل العقود' },
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
            <Button loading={loading} onClick={() => void load()}>تحضير البيان</Button>
          </div>
        </div>
      </section>

      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="اختر الفلتر ثم حضّر البيان" /> : null}
      {!loading && rows.length > 0 ? (
        <div className="rpm-card-list">
          {rows.map((r) => (
            <article key={r.contract_id} className="rpm-card">
              <strong>{r.renter_name || '—'}</strong>
              <p>{r.vehicle_type || '—'} · {r.plate || '—'}</p>
              <p className="muted">
                من {r.starts_on ? formatDate(r.starts_on) : '—'} إلى {r.ends_on ? formatDate(r.ends_on) : '—'}
                {r.rental_duration_days != null ? ` · ${r.rental_duration_days} يوم` : ''}
              </p>
              <p className="muted">قومي {r.national_id || '—'} · {r.address || 'بدون عنوان'}</p>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
