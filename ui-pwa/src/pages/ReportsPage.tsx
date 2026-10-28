import { useEffect, useMemo, useState } from 'react'
import { motion } from 'framer-motion'
import { api, type ApiError } from '@/shared/api/client'
import { formatMoney, formatNumber } from '@/shared/lib/format'
import {
  PageHeader, SkeletonBlock, ErrorState, MetricCardGrid,
  IconCash, IconReturn, IconTrend, IconWallet, IconClipboard, IconCheck, IconUsers, IconBan, IconAlert,
} from '@/shared/ui'
import './pages.css'
import './ReportsPage.css'

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

const fleetMeta: Record<string, { label: string; tone: string }> = {
  available: { label: 'متاحة', tone: 'success' },
  rented: { label: 'مؤجرة', tone: 'info' },
  reserved: { label: 'محجوزة', tone: 'warning' },
  maintenance: { label: 'في الصيانة', tone: 'danger' },
}

const monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']

function ymLabel(ym: string) {
  const [y, m] = ym.split('-')
  return `${monthNames[Number(m) - 1] ?? m} ${y}`
}

export function ReportsPage() {
  const [data, setData] = useState<Report | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api<{ data: Report }>('/reports/summary')
      .then((r) => setData(r.data))
      .catch((e) => setError((e as ApiError).message))
  }, [])

  const peak = useMemo(() => Math.max(1, ...(data?.rent_by_month.map((r) => r.total) ?? [0])), [data])

  if (error) return <ErrorState title="تعذّر تحميل التقارير" description={error} />
  if (!data) return <SkeletonBlock rows={8} />

  const occupancy = data.fleet.total ? Math.round((data.fleet.rented / data.fleet.total) * 100) : 0

  return (
    <div className="rpm-stack">
      <PageHeader title="التقارير" subtitle="إشغال · مديونيات عقود · ربحية تشغيل — مخالفات خارج المحاسبة" />

      <MetricCardGrid
        label="المؤشرات المالية"
        items={[
          { id: 'receivables', label: 'ذمم عقود', value: data.receivables, format: 'money', tone: 'warning', icon: <IconWallet size={16} /> },
          { id: 'in', label: 'مقبوضات', value: data.payments_in, format: 'money', tone: 'success', icon: <IconCash size={16} /> },
          { id: 'out', label: 'مدفوع للعملاء', value: data.payments_out, format: 'money', icon: <IconReturn size={16} /> },
          { id: 'expected', label: 'إيراد متوقع (نشط)', value: data.expected_revenue_active, format: 'money', tone: 'info', icon: <IconTrend size={16} /> },
        ]}
      />

      <MetricCardGrid
        label="المؤشرات التشغيلية"
        mobile="wrap"
        items={[
          { id: 'active', label: 'عقود نشطة', value: data.active_contracts, tone: 'info', icon: <IconClipboard size={16} /> },
          { id: 'completed', label: 'عقود مكتملة', value: data.completed_contracts, tone: 'success', icon: <IconCheck size={16} /> },
          { id: 'customers', label: 'عملاء', value: data.customers, icon: <IconUsers size={16} /> },
          { id: 'blacklist', label: 'قائمة سوداء', value: data.blacklisted_customers, tone: 'danger', icon: <IconBan size={16} /> },
          { id: 'violations', label: 'مخالفات مفتوحة', value: data.open_violations, tone: 'warning', icon: <IconAlert size={16} />, hint: 'للمتابعة فقط — بلا ترحيل' },
        ]}
      />

      <div className="rp-bento">
        <section className="rpm-panel rp-fleet">
          <div className="rpm-panel__head">
            <h3>توزيع حالة الأسطول</h3>
            <span className="rp-chip tabular-nums">إشغال {occupancy}%</span>
          </div>
          <div className="rp-fleet__stack" aria-hidden>
            {Object.entries(fleetMeta).map(([key, meta]) => {
              const value = data.fleet[key as keyof Report['fleet']] ?? 0
              const pct = data.fleet.total ? (value / data.fleet.total) * 100 : 0
              return pct > 0 ? (
                <motion.span
                  key={key}
                  className={`rp-fleet__seg is-${meta.tone}`}
                  initial={{ flexGrow: 0 }}
                  animate={{ flexGrow: pct }}
                  transition={{ type: 'spring', stiffness: 160, damping: 26 }}
                />
              ) : null
            })}
          </div>
          <ul className="rp-fleet__legend">
            {Object.entries(fleetMeta).map(([key, meta]) => {
              const value = data.fleet[key as keyof Report['fleet']] ?? 0
              return (
                <li key={key}>
                  <i className={`rp-dot is-${meta.tone}`} />
                  <span>{meta.label}</span>
                  <strong className="tabular-nums">{formatNumber(value)}</strong>
                </li>
              )
            })}
            <li className="is-total">
              <span>الإجمالي</span>
              <strong className="tabular-nums">{formatNumber(data.fleet.total)}</strong>
            </li>
          </ul>
        </section>

        <section className="rpm-panel rp-rent">
          <div className="rpm-panel__head"><h3>إيراد الإيجار حسب الشهر</h3></div>
          {data.rent_by_month.length === 0 ? (
            <p className="muted">يظهر بعد استلام عقود مكتملة وترحيلها محاسبياً.</p>
          ) : (
            <ol className="rp-rent__list">
              {data.rent_by_month.map((row, i) => (
                <li key={row.ym}>
                  <div className="rp-rent__meta">
                    <span>{ymLabel(row.ym)}</span>
                    <strong className="tabular-nums">{formatMoney(row.total)}</strong>
                  </div>
                  <div className="rp-rent__track">
                    <motion.span
                      initial={{ width: '0%' }}
                      animate={{ width: `${(row.total / peak) * 100}%` }}
                      transition={{ type: 'spring', stiffness: 140, damping: 24, delay: i * 0.04 }}
                    />
                  </div>
                </li>
              ))}
            </ol>
          )}
        </section>
      </div>
    </div>
  )
}
