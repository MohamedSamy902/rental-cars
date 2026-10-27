import { Link } from 'react-router-dom'
import { PageHeader, SummaryStrip, Button, StatusPill } from '@/shared/ui'
import { formatDateTime, formatMoney } from '@/shared/lib/format'
import { bookings, bookingStatusLabel, vehicles } from '@/shared/lib/mock-data'
import './pages.css'

export function DashboardPage() {
  const available = vehicles.filter((v) => v.status === 'available').length
  const rented = vehicles.filter((v) => v.status === 'rented').length
  const returning = bookings.filter((b) => b.status === 'active').length

  return (
    <div className="rpm-stack">
      <PageHeader
        title="لوحة اليوم"
        subtitle="إشغال الأسطول ومهام التشغيل الحالية"
        action={
          <Link to="/bookings">
            <Button>حجز جديد</Button>
          </Link>
        }
      />

      <section className="rpm-hero-occupancy" aria-label="إشغال اليوم">
        <div>
          <p className="rpm-kicker">إشغال الأسطول</p>
          <h2>{Math.round((rented / vehicles.length) * 100)}%</h2>
          <p>من أصل {vehicles.length} سيارات نشطة</p>
        </div>
        <div className="rpm-hero-occupancy__bars" aria-hidden>
          <span style={{ flex: rented || 1 }} className="is-rented" />
          <span style={{ flex: available || 1 }} className="is-available" />
          <span style={{ flex: Math.max(vehicles.length - rented - available, 1) }} className="is-other" />
        </div>
      </section>

      <SummaryStrip
        items={[
          { label: 'متاحة الآن', value: String(available), tone: 'success' },
          { label: 'مؤجرة', value: String(rented) },
          { label: 'ترجع اليوم', value: String(returning), tone: 'warning' },
          { label: 'إيراد متوقع', value: formatMoney(18500) },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>مهام اليوم</h3>
        </div>
        <ul className="rpm-task-list">
          <li>
            <div>
              <strong>تسليم CNT-24090</strong>
              <span>منى حسين · كيا سبورتاج · ٩ ص</span>
            </div>
            <StatusPill status="approved" label="معتمد" />
          </li>
          <li>
            <div>
              <strong>استلام CNT-24081</strong>
              <span>أحمد محمود · تويوتا كورولا · ١٠ ص</span>
            </div>
            <StatusPill status="active" label="نشط" />
          </li>
          <li>
            <div>
              <strong>اعتماد حجز معلّق</strong>
              <span>يوسف علي · إلنترا</span>
            </div>
            <StatusPill status="pending" label="معلّق" />
          </li>
        </ul>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head">
          <h3>أحدث الحجوزات</h3>
          <Link to="/bookings" className="rpm-text-link">
            عرض الكل
          </Link>
        </div>
        <div className="rpm-card-list">
          {bookings.slice(0, 3).map((b) => (
            <article key={b.id} className="rpm-card">
              <div className="rpm-card__row">
                <strong>{b.number}</strong>
                <StatusPill status={b.status} label={bookingStatusLabel[b.status]} />
              </div>
              <p>{b.customer}</p>
              <p className="muted">{b.vehicle}</p>
              <p className="muted">
                {formatDateTime(b.startAt)} → {formatDateTime(b.endAt)}
              </p>
              <p>{formatMoney(b.dailyRate)} / يوم</p>
            </article>
          ))}
        </div>
      </section>
    </div>
  )
}
