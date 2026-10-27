import { useEffect, useState } from 'react'
import { api } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import { PageHeader, SkeletonBlock } from '@/shared/ui'
import './pages.css'

export function ReportsPage() {
  const [data, setData] = useState<{ kpis: Record<string, number>; fleet_status: Record<string, number> } | null>(null)

  useEffect(() => {
    api<any>('/dashboard').then(setData)
  }, [])

  if (!data) return <SkeletonBlock rows={6} />

  return (
    <div className="rpm-stack">
      <PageHeader title="التقارير" subtitle="إشغال · مديونيات عقود · ربحية تشغيل — مخالفات خارج المحاسبة" />
      <section className="rpm-metric-grid">
        <article className="rpm-metric">
          <span className="rpm-metric__label">عقود نشطة</span>
          <strong className="rpm-metric__value">{data.kpis.active_contracts}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">عملاء</span>
          <strong className="rpm-metric__value">{data.kpis.customers}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">إيراد متوقع</span>
          <strong className="rpm-metric__value">{formatMoney(data.kpis.expected_revenue || 0)}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">مخالفات مفتوحة</span>
          <strong className="rpm-metric__value is-warning">{data.kpis.open_violations}</strong>
        </article>
      </section>
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>توزيع حالة الأسطول</h3></div>
        <div className="rpm-status-bars">
          {Object.entries(data.fleet_status).map(([key, value]) => (
            <div key={key} className="rpm-status-bar">
              <div className="rpm-status-bar__meta"><span>{key}</span><strong>{value}</strong></div>
              <div className="rpm-status-bar__track"><span className="fill-available" style={{ width: `${Math.min(100, value * 20)}%` }} /></div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
