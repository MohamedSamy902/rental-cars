import { PageHeader, StatusPill } from '@/shared/ui'
import { bookings, bookingStatusLabel } from '@/shared/lib/mock-data'
import { formatDateTime } from '@/shared/lib/format'
import './pages.css'

export function CalendarPage() {
  return (
    <div className="rpm-stack">
      <PageHeader title="التقويم" subtitle="خط زمني للحجوزات المعتمدة والنشطة" />
      <div className="rpm-timeline">
        {bookings.map((b) => (
          <article key={b.id} className="rpm-timeline__item">
            <div className="rpm-timeline__dot" />
            <div className="rpm-timeline__card">
              <div className="rpm-card__row">
                <strong>{b.number}</strong>
                <StatusPill status={b.status} label={bookingStatusLabel[b.status]} />
              </div>
              <p>{b.customer}</p>
              <p className="muted">{b.vehicle}</p>
              <p className="muted">
                {formatDateTime(b.startAt)} → {formatDateTime(b.endAt)}
              </p>
            </div>
          </article>
        ))}
      </div>
    </div>
  )
}
