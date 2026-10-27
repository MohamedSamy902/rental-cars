import { useEffect, useState } from 'react'
import { api } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, StatusPill, SkeletonBlock, EmptyState } from '@/shared/ui'
import './pages.css'

type Contract = {
  id: number
  number: string
  status: string
  starts_at: string
  planned_ends_at: string
  customer?: { full_name: string }
  vehicle?: { brand: string; model: string; plate: string }
}

const statusLabel: Record<string, string> = {
  pending: 'معلّق', approved: 'معتمد', active: 'نشط', completed: 'مكتمل',
}

export function CalendarPage() {
  const [rows, setRows] = useState<Contract[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api<{ data: Contract[] }>('/contracts?per_page=100')
      .then((res) => setRows(res.data.filter((c) => ['pending', 'approved', 'active'].includes(c.status))))
      .finally(() => setLoading(false))
  }, [])

  return (
    <div className="rpm-stack">
      <PageHeader title="التقويم" subtitle="خط زمني للحجوزات المعتمدة والنشطة (أسلوب Keynetic)" />
      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا حجوزات في الأفق" /> : null}
      <div className="rpm-timeline">
        {rows.map((b) => (
          <article key={b.id} className="rpm-timeline__item">
            <div className="rpm-timeline__dot" />
            <div className="rpm-timeline__card">
              <div className="rpm-card__row">
                <strong>{b.number}</strong>
                <StatusPill status={b.status} label={statusLabel[b.status] || b.status} />
              </div>
              <p>{b.customer?.full_name}</p>
              <p className="muted">{b.vehicle?.brand} {b.vehicle?.model} · {b.vehicle?.plate}</p>
              <p className="muted">{formatDateTime(b.starts_at)} → {formatDateTime(b.planned_ends_at)}</p>
            </div>
          </article>
        ))}
      </div>
    </div>
  )
}
