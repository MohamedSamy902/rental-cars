import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, EmptyState, SkeletonBlock, ErrorState, Button } from '@/shared/ui'
import './pages.css'

type Log = {
  id: number
  action: string
  record_type?: string
  record_id?: number
  user_id?: number
  created_at?: string
}

export function AuditLogsPage() {
  const [rows, setRows] = useState<Log[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Log[] }>('/audit-logs?limit=80')
      setRows(res.data)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  return (
    <div className="rpm-stack">
      <PageHeader title="سجل التعديلات" subtitle="آخر الحركات على العقود والسجلات" action={<Button variant="secondary" onClick={() => void load()}>تحديث</Button>} />
      {loading ? <SkeletonBlock rows={6} /> : null}
      {!loading && error ? <ErrorState title="تعذّر التحميل" description={error} /> : null}
      {!loading && !error && rows.length === 0 ? <EmptyState title="لا توجد سجلات بعد" /> : null}
      <div className="rpm-card-list">
        {rows.map((r) => (
          <article key={r.id} className="rpm-card">
            <div className="rpm-card__row">
              <strong>{r.action}</strong>
              <span className="muted">{r.created_at ? formatDateTime(r.created_at) : ''}</span>
            </div>
            <p className="muted">{r.record_type || '—'} #{r.record_id || '—'} · مستخدم #{r.user_id || '—'}</p>
          </article>
        ))}
      </div>
    </div>
  )
}
