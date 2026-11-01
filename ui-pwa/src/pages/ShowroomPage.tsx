import { useEffect, useMemo, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useNewContract } from '@/shared/contracts/newContractContext'
import { formatMoney, formatDate } from '@/shared/lib/format'
import { PageHeader, EmptyState, SkeletonBlock, ErrorState, StatusPill, Button } from '@/shared/ui'
import './pages.css'
import './ShowroomPage.css'

type ShowroomVehicle = {
  id: number
  brand: string
  model: string
  year: number
  category: string
  fuel_type: string
  transmission?: string | null
  color?: string | null
  seats?: number | null
  plate?: string
  daily_rate: number
  weekly_rate: number
  monthly_rate: number
  default_deposit: number
  status: string
  available: boolean
  returning_on?: string | null
}

export function ShowroomPage() {
  const { openNewContract } = useNewContract()
  const [center, setCenter] = useState('')
  const [rows, setRows] = useState<ShowroomVehicle[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [chip, setChip] = useState<'all' | 'available' | 'busy'>('all')

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

  const filtered = useMemo(() => {
    if (chip === 'available') return rows.filter((v) => v.available)
    if (chip === 'busy') return rows.filter((v) => !v.available)
    return rows
  }, [rows, chip])

  function catalogText(v: ShowroomVehicle) {
    const status = v.available
      ? 'متاحة الآن'
      : v.returning_on
        ? `تعود ${formatDate(v.returning_on)}`
        : 'غير متاحة حالياً'
    return [
      `${center || 'RPM'} — كتالوج التأجير`,
      `${v.brand} ${v.model} ${v.year}`,
      `الفئة: ${v.category} · ${v.fuel_type}${v.transmission ? ` · ${v.transmission}` : ''}`,
      `يومي ${formatMoney(v.daily_rate)} · أسبوعي ${formatMoney(v.weekly_rate)} · شهري ${formatMoney(v.monthly_rate)}`,
      `التأمين النقدي: ${formatMoney(v.default_deposit)}`,
      `الحالة: ${status}`,
    ].join('\n')
  }

  function shareWhatsApp(v: ShowroomVehicle) {
    const url = `https://wa.me/?text=${encodeURIComponent(catalogText(v))}`
    window.open(url, '_blank', 'noopener,noreferrer')
  }

  return (
    <div className="rpm-stack rpm-showroom">
      <PageHeader
        title={center || 'المعرض'}
        subtitle="كتالوج تجاري · أسعار · توفر · حجز فوري للوكلاء"
        action={<Button variant="secondary" onClick={() => void load()}>تحديث</Button>}
      />

      <div className="rpm-showroom__chips" role="tablist" aria-label="تصفية التوفر">
        {([
          { id: 'all', label: 'الكل' },
          { id: 'available', label: 'متاحة الآن' },
          { id: 'busy', label: 'غير متاحة' },
        ] as const).map((c) => (
          <button
            key={c.id}
            type="button"
            className={`rpm-chip ${chip === c.id ? 'is-active' : ''}`}
            onClick={() => setChip(c.id)}
          >
            {c.label}
          </button>
        ))}
      </div>

      {loading ? <SkeletonBlock rows={5} /> : null}
      {!loading && error ? <ErrorState title="تعذّر تحميل المعرض" description={error} action={<Button onClick={() => void load()}>إعادة</Button>} /> : null}
      {!loading && !error && filtered.length === 0 ? <EmptyState title="لا توجد عربيات مطابقة" description="غيّر التصفية أو حدّث القائمة." /> : null}

      {!loading && !error && filtered.length > 0 ? (
        <div className="rpm-showroom__grid">
          {filtered.map((v) => (
            <article key={v.id} className="rpm-showroom__card">
              <div className="rpm-showroom__card-top">
                <div className="rpm-showroom__identity">
                  <span className="rpm-showroom__category">{v.category}</span>
                  <h3>{v.brand} {v.model}</h3>
                  <div className="rpm-showroom__meta">
                    <span className="rpm-showroom__year">{v.year}</span>
                    {v.plate ? <span className="rpm-showroom__plate">{v.plate}</span> : null}
                  </div>
                </div>
                <StatusPill
                  status={v.available ? 'available' : 'rented'}
                  label={v.available ? 'متاحة الآن' : v.returning_on ? `تعود ${formatDate(v.returning_on)}` : 'غير متاحة'}
                />
              </div>

              <ul className="rpm-showroom__specs">
                <li>{v.fuel_type}</li>
                {v.transmission ? <li>{v.transmission}</li> : null}
                {v.color ? <li>{v.color}</li> : null}
                {v.seats ? <li>{v.seats} مقاعد</li> : null}
              </ul>

              <div className="rpm-showroom__rates">
                <div className="rpm-showroom__rate"><span>يومي</span><strong>{formatMoney(v.daily_rate)}</strong></div>
                <div className="rpm-showroom__rate"><span>أسبوعي</span><strong>{formatMoney(v.weekly_rate)}</strong></div>
                <div className="rpm-showroom__rate"><span>شهري</span><strong>{formatMoney(v.monthly_rate)}</strong></div>
              </div>

              <p className="rpm-showroom__deposit">
                <span>التأمين النقدي المطلوب</span>
                <strong>{formatMoney(v.default_deposit)}</strong>
              </p>

              <div className="rpm-showroom__actions">
                <Button
                  disabled={!v.available}
                  onClick={() => openNewContract({ vehicleId: v.id })}
                >
                  حجز فوري
                </Button>
                <Button variant="secondary" onClick={() => shareWhatsApp(v)}>مشاركة واتساب</Button>
              </div>
            </article>
          ))}
        </div>
      ) : null}
    </div>
  )
}
