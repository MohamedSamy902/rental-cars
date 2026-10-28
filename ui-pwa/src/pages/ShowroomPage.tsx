import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { PageHeader, EmptyState, SkeletonBlock, ErrorState, StatusPill, Button } from '@/shared/ui'
import './pages.css'

type ShowroomVehicle = {
  id: number
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  status: string
  available: boolean
}

export function ShowroomPage() {
  const [center, setCenter] = useState('')
  const [rows, setRows] = useState<ShowroomVehicle[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: { center_name?: string; vehicles: ShowroomVehicle[] } }>('/showroom')
      setCenter(res.data.center_name || 'المعرض')
      setRows(res.data.vehicles || [])
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    const t = window.setInterval(() => void load(), 30000)
    return () => window.clearInterval(t)
  }, [])

  return (
    <div className="rpm-stack rpm-showroom">
      <PageHeader
        title={center || 'وضع عرض المعرض'}
        subtitle="متاح للتأجير الآن — بلا أسعار أو بيانات حسّاسة"
        action={<Button variant="secondary" onClick={() => void load()}>تحديث</Button>}
      />
      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && error ? <ErrorState title="تعذّر تحميل المعرض" description={error} action={<Button onClick={() => void load()}>إعادة</Button>} /> : null}
      {!loading && !error && rows.length === 0 ? <EmptyState title="لا توجد سيارات متاحة حالياً" /> : null}
      {!loading && !error && rows.length > 0 ? (
        <div className="rpm-card-list rpm-showroom__grid">
          {rows.map((v) => (
            <article key={v.id} className="rpm-card rpm-showroom__card">
              <div className="rpm-card__row">
                <strong>{v.brand} {v.model}</strong>
                <StatusPill status="available" label="متاحة" />
              </div>
              <p className="muted">{v.year} · {v.category} · {v.fuel_type}</p>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
