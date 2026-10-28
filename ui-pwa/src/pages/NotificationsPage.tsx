import { useEffect, useState } from 'react'
import { api } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, SkeletonBlock, EmptyState, Button } from '@/shared/ui'
import './pages.css'

type Note = { id: number; title: string; body: string; created_at: string; read_at?: string | null }

export function NotificationsPage() {
  const [rows, setRows] = useState<Note[]>([])
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(true)

  async function load() {
    setLoading(true)
    try {
      const res = await api<{ data: Note[]; unread?: number }>('/notifications')
      setRows(res.data)
      setUnread(res.unread ?? res.data.filter((n) => !n.read_at).length)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function markRead(id: number) {
    await api(`/notifications/${id}/read`, { method: 'POST' })
    await load()
  }

  async function markAll() {
    const unreadRows = rows.filter((n) => !n.read_at)
    await Promise.all(unreadRows.map((n) => api(`/notifications/${n.id}/read`, { method: 'POST' })))
    await load()
  }

  return (
    <div className="rpm-stack">
      <PageHeader
        title="الإشعارات"
        subtitle={unread > 0 ? `${unread} غير مقروء` : 'داخل النظام فقط — تكرار كل ٧٢ ساعة حسب القاعدة'}
        action={unread > 0 ? <Button variant="secondary" onClick={() => void markAll()}>تعليم الكل كمقروء</Button> : undefined}
      />
      {loading ? <SkeletonBlock rows={4} /> : null}
      {!loading && rows.length === 0 ? <EmptyState title="لا توجد إشعارات" /> : null}
      <div className="rpm-card-list">
        {rows.map((n) => (
          <article key={n.id} className={`rpm-card ${n.read_at ? '' : 'is-unread'}`.trim()}>
            <div className="rpm-card__row">
              <strong>{n.title}</strong>
              {!n.read_at ? (
                <Button size="sm" variant="secondary" onClick={() => void markRead(n.id)}>تعليم كمقروء</Button>
              ) : (
                <span className="muted">مقروء</span>
              )}
            </div>
            <p>{n.body}</p>
            <p className="muted">{formatDateTime(n.created_at)}</p>
          </article>
        ))}
      </div>
    </div>
  )
}
