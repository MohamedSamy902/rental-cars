import { useEffect, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { apiUrl, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { BrandLogo, SkeletonBlock, telHref, whatsappHref } from '@/shared/ui'
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
    tagline?: string | null
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

function mapsHref(address: string): string {
  return `https://maps.google.com/?q=${encodeURIComponent(address)}`
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

  const vehicleLabel = card
    ? [
        [card.vehicle.brand, card.vehicle.model].filter(Boolean).join(' '),
        card.vehicle.plate,
      ].filter(Boolean).join(' · ')
    : ''

  return (
    <div className="vqr">
      <div className="vqr__atmosphere" aria-hidden>
        <span className="vqr__beam vqr__beam--a" />
        <span className="vqr__beam vqr__beam--b" />
        <span className="vqr__road" />
      </div>

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
            <header className="vqr__hero">
              <div className="vqr__logo-stage">
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
              <p className="vqr__eyebrow">تأجير سيارات</p>
              <h1 className="vqr__center-name">{card.center.name}</h1>
              {card.center.tagline ? (
                <p className="vqr__tagline">{card.center.tagline}</p>
              ) : null}
              {vehicleLabel ? (
                <p className="vqr__vehicle-line">{vehicleLabel}</p>
              ) : null}
            </header>

            {(card.center.phone || card.center.address) ? (
              <section className="vqr__promo" aria-label="تواصل مع المركز">
                {card.center.phone ? (
                  <div className="vqr__cta-row">
                    <a className="vqr__cta vqr__cta--call" href={telHref(card.center.phone)}>
                      <span className="vqr__cta-kicker">اتصل الآن</span>
                      <strong dir="ltr">{card.center.phone}</strong>
                    </a>
                    <a
                      className="vqr__cta vqr__cta--wa"
                      href={whatsappHref(card.center.phone)}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <span className="vqr__cta-kicker">واتساب</span>
                      <strong>راسل المركز</strong>
                    </a>
                  </div>
                ) : null}

                {card.center.address ? (
                  <a
                    className="vqr__address"
                    href={mapsHref(card.center.address)}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <span className="vqr__address-label">العنوان</span>
                    <span className="vqr__address-value">{card.center.address}</span>
                    <span className="vqr__address-hint">افتح على الخريطة</span>
                  </a>
                ) : null}
              </section>
            ) : null}

            {Object.keys(card.center.social).length > 0 ? (
              <nav className="vqr__social" aria-label="حسابات المركز">
                {Object.entries(card.center.social).map(([key, href]) => (
                  <a key={key} href={href} target="_blank" rel="noopener noreferrer">
                    {SOCIAL_LABELS[key] || key}
                  </a>
                ))}
              </nav>
            ) : null}

            {card.is_rented && card.current_renter ? (
              <section className="vqr__renter" aria-label="المستأجر الحالي">
                <p className="vqr__renter-label">المستأجر الحالي</p>
                <p className="vqr__renter-name">{card.current_renter.full_name}</p>
                {card.current_renter.phone ? (
                  <div className="vqr__cta-row vqr__cta-row--compact">
                    <a className="vqr__cta vqr__cta--call" href={telHref(card.current_renter.phone)}>
                      <span className="vqr__cta-kicker">اتصال</span>
                      <strong dir="ltr">{card.current_renter.phone}</strong>
                    </a>
                    <a
                      className="vqr__cta vqr__cta--wa"
                      href={whatsappHref(card.current_renter.phone)}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <span className="vqr__cta-kicker">واتساب</span>
                      <strong>مراسلة</strong>
                    </a>
                  </div>
                ) : null}
              </section>
            ) : null}

            <footer className="vqr__credit">
              <span>تم بواسطة</span>
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
