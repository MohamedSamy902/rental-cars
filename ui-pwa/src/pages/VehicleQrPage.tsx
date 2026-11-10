import { useEffect, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { apiUrl, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { BrandLogo, SkeletonBlock, PhoneActions } from '@/shared/ui'
import './VehicleQrPage.css'

type QrCard = {
  vehicle: {
    id: number
    plate?: string | null
    brand?: string | null
    model?: string | null
    year?: number | null
  }
  center: {
    name: string
    phone?: string | null
    address?: string | null
    logo_url?: string | null
    social: Record<string, string>
  }
  current_renter: null | { full_name: string; phone?: string | null }
  is_rented: boolean
  credit: { name: string; whatsapp_url: string }
}

const SOCIAL_LABELS: Record<string, string> = {
  facebook: 'فيسبوك',
  instagram: 'إنستغرام',
  tiktok: 'تيك توك',
  twitter: 'إكس',
  youtube: 'يوتيوب',
  snapchat: 'سناب شات',
}

export function VehicleQrPage() {
  const { id } = useParams()
  const { user, loading: authLoading } = useAuth()
  const [card, setCard] = useState<QrCard | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (authLoading || user || !id) return
    let cancelled = false
    setLoading(true)
    setError(null)
    fetch(apiUrl(`/public/vehicles/${id}/qr-card`), { headers: { Accept: 'application/json' } })
      .then(async (res) => {
        const body = await res.json().catch(() => ({}))
        if (!res.ok) {
          throw Object.assign(new Error(body?.message || 'تعذّر تحميل بطاقة العربية.'), {
            status: res.status,
          } satisfies Partial<ApiError>)
        }
        return body as { data: QrCard }
      })
      .then((body) => {
        if (!cancelled) setCard(body.data)
      })
      .catch((e: ApiError) => {
        if (!cancelled) setError(e.message || 'تعذّر تحميل بطاقة العربية.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [authLoading, user, id])

  if (authLoading) {
    return (
      <div className="vqr">
        <div className="vqr__shell">
          <SkeletonBlock rows={5} />
        </div>
      </div>
    )
  }

  if (user && id) {
    return <Navigate to={`/vehicles/${id}`} replace />
  }

  if (!id) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="vqr">
      <div className="vqr__glow" aria-hidden />
      <div className="vqr__shell">
        {loading ? <SkeletonBlock rows={6} /> : null}

        {!loading && error ? (
          <div className="vqr__state" role="alert">
            <p>{error}</p>
            <a className="vqr__link" href="/login">تسجيل الدخول للنظام</a>
          </div>
        ) : null}

        {!loading && card ? (
          <>
            <header className="vqr__brand">
              <div className="vqr__logo-band">
                {card.center.logo_url ? (
                  <img
                    className="vqr__tenant-logo"
                    src={card.center.logo_url}
                    alt={card.center.name}
                    decoding="async"
                  />
                ) : (
                  <BrandLogo variant="full" bare />
                )}
              </div>
              <h1 className="vqr__center-name">{card.center.name}</h1>
              {(card.vehicle.brand || card.vehicle.model || card.vehicle.plate) ? (
                <p className="vqr__vehicle-line">
                  {[card.vehicle.brand, card.vehicle.model].filter(Boolean).join(' ')}
                  {card.vehicle.plate ? ` · ${card.vehicle.plate}` : ''}
                </p>
              ) : null}
            </header>

            <section className="vqr__section" aria-label="بيانات المركز">
              <h2>بيانات المركز</h2>
              {card.center.phone ? (
                <div className="vqr__row">
                  <span className="vqr__label">الهاتف</span>
                  <PhoneActions phone={card.center.phone} name={card.center.name} />
                </div>
              ) : null}
              {card.center.address ? (
                <div className="vqr__row">
                  <span className="vqr__label">العنوان</span>
                  <p className="vqr__value">{card.center.address}</p>
                </div>
              ) : null}
              {Object.keys(card.center.social).length > 0 ? (
                <ul className="vqr__social">
                  {Object.entries(card.center.social).map(([key, href]) => (
                    <li key={key}>
                      <a href={href} target="_blank" rel="noopener noreferrer">
                        {SOCIAL_LABELS[key] || key}
                      </a>
                    </li>
                  ))}
                </ul>
              ) : null}
              {!card.center.phone && !card.center.address && Object.keys(card.center.social).length === 0 ? (
                <p className="vqr__muted">تواصل مع المركز عبر قنواته المعتادة.</p>
              ) : null}
            </section>

            {card.is_rented && card.current_renter ? (
              <section className="vqr__section vqr__section--renter" aria-label="المستأجر الحالي">
                <h2>المستأجر الحالي</h2>
                <div className="vqr__row">
                  <span className="vqr__label">الاسم</span>
                  <p className="vqr__value">{card.current_renter.full_name}</p>
                </div>
                {card.current_renter.phone ? (
                  <div className="vqr__row">
                    <span className="vqr__label">الهاتف</span>
                    <PhoneActions phone={card.current_renter.phone} name={card.current_renter.full_name} />
                  </div>
                ) : null}
              </section>
            ) : (
              <section className="vqr__section vqr__section--idle" aria-label="حالة التأجير">
                <p className="vqr__idle">العربية غير مؤجَّرة حالياً.</p>
              </section>
            )}

            <footer className="vqr__credit">
              <span>تم بواسطة</span>{' '}
              <a
                href={card.credit.whatsapp_url}
                target="_blank"
                rel="noopener noreferrer"
              >
                {card.credit.name}
              </a>
            </footer>
          </>
        ) : null}
      </div>
    </div>
  )
}
