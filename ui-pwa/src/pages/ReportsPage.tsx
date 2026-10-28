import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney } from '@/shared/lib/format'
import { PageHeader, SkeletonBlock, ErrorState, SummaryStrip } from '@/shared/ui'
import './pages.css'

type Report = {
  receivables: number
  payments_in: number
  payments_out: number
  active_contracts: number
  completed_contracts: number
  customers: number
  blacklisted_customers: number
  open_violations: number
  expected_revenue_active: number
  fleet: { total: number; available: number; rented: number; reserved: number; maintenance: number }
  rent_by_month: { ym: string; total: number }[]
}

export function ReportsPage() {
  const [data, setData] = useState<Report | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api<{ data: Report }>('/reports/summary')
      .then((r) => setData(r.data))
      .catch((e) => setError((e as ApiError).message))
  }, [])

  if (error) return <ErrorState title="تعذّر تحميل التقارير" description={error} />
  if (!data) return <SkeletonBlock rows={8} />

  return (
    <div className="rpm-stack">
      <PageHeader title="التقارير" subtitle="إشغال · مديونيات عقود · ربحية تشغيل — مخالفات خارج المحاسبة" />

      <SummaryStrip
        items={[
          { label: 'ذمم عقود', value: formatMoney(data.receivables), tone: 'warning' },
          { label: 'مقبوضات', value: formatMoney(data.payments_in), tone: 'success' },
          { label: 'مدفوع للعملاء', value: formatMoney(data.payments_out) },
          { label: 'إيراد متوقع (نشط)', value: formatMoney(data.expected_revenue_active) },
        ]}
      />

      <section className="rpm-metric-grid">
        <article className="rpm-metric">
          <span className="rpm-metric__label">عقود نشطة</span>
          <strong className="rpm-metric__value">{data.active_contracts}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">عقود مكتملة</span>
          <strong className="rpm-metric__value">{data.completed_contracts}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">عملاء</span>
          <strong className="rpm-metric__value">{data.customers}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">قائمة سوداء</span>
          <strong className="rpm-metric__value is-warning">{data.blacklisted_customers}</strong>
        </article>
        <article className="rpm-metric">
          <span className="rpm-metric__label">مخالفات مفتوحة</span>
          <strong className="rpm-metric__value is-warning">{data.open_violations}</strong>
          <span className="muted">للمتابعة فقط — بلا ترحيل</span>
        </article>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>توزيع حالة الأسطول</h3></div>
        <div className="rpm-status-bars">
          {Object.entries(data.fleet).map(([key, value]) => (
            <div key={key} className="rpm-status-bar">
              <div className="rpm-status-bar__meta"><span>{key}</span><strong>{value}</strong></div>
              <div className="rpm-status-bar__track">
                <span className="fill-available" style={{ width: `${Math.min(100, (value / Math.max(1, data.fleet.total)) * 100)}%` }} />
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>إيراد الإيجار حسب الشهر</h3></div>
        <div className="rpm-dense-list">
          {data.rent_by_month.length === 0 ? (
            <p className="muted">يظهر بعد استلام عقود مكتملة وترحيلها محاسبياً.</p>
          ) : data.rent_by_month.map((row) => (
            <div key={row.ym} className="rpm-dense-row">
              <div>
                <strong>{row.ym}</strong>
                <p className="muted">{formatMoney(row.total)}</p>
              </div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
