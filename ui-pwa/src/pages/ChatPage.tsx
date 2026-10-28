import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime } from '@/shared/lib/format'
import { PageHeader, Button, TextField, useToast, SkeletonBlock } from '@/shared/ui'
import './pages.css'

type Msg = {
  id: number
  body: string
  created_at?: string
  user?: { id: number; name: string }
}

export function ChatPage() {
  const toast = useToast()
  const [rows, setRows] = useState<Msg[]>([])
  const [body, setBody] = useState('')
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)

  async function load() {
    try {
      const res = await api<{ data: Msg[] }>('/chat')
      setRows(res.data)
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    const t = window.setInterval(() => void load(), 15000)
    return () => window.clearInterval(t)
  }, [])

  async function send() {
    if (!body.trim()) return
    setSending(true)
    try {
      await api('/chat', { method: 'POST', json: { body: body.trim() } })
      setBody('')
      await load()
    } catch (e) {
      toast.error((e as ApiError).message)
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="rpm-stack">
      <PageHeader title="محادثة التشغيل" subtitle="حد يومي 100 رسالة · بلا واتساب خارجي" />
      {loading ? <SkeletonBlock rows={5} /> : null}
      <div className="rpm-card-list">
        {rows.map((m) => (
          <article key={m.id} className="rpm-card">
            <div className="rpm-card__row"><strong>{m.user?.name || '—'}</strong><span className="muted">{m.created_at ? formatDateTime(m.created_at) : ''}</span></div>
            <p>{m.body}</p>
          </article>
        ))}
      </div>
      <div className="rpm-form-grid">
        <TextField label="رسالة" value={body} onChange={(e) => setBody(e.target.value)} />
        <Button loading={sending} onClick={() => void send()}>إرسال</Button>
      </div>
    </div>
  )
}
