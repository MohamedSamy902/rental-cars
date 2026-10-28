import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/format'
import {
  Button, StatusPill, MetricCardGrid, DetailList, SkeletonBlock, ErrorState, EmptyState, useToast,
  IconCash, IconWallet, IconClipboard, IconKey,
} from '@/shared/ui'
import {
  DepositBadge, inspectionTypeLabel, statusLabel, timelineLabel, type Contract,
} from '@/shared/contracts/contractModel'
import { ContractActionsBar } from '@/shared/contracts/ContractActionsBar'
import { ContractActionSheet } from '@/shared/contracts/ContractActionSheet'
import { useContractLifecycle } from '@/shared/contracts/useContractLifecycle'
import { printContract } from '@/shared/contracts/printContract'
import './pages.css'
import './ContractDetailPage.css'

const relationLabel: Record<string, string> = {
  extended_from: 'مُمدَّد منه',
  swapped_from: 'مُبدَّل منه',
  extension: 'عقد تمديد',
  swap: 'عقد تبديل',
}

export function ContractDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const contractId = Number(id)
  const [row, setRow] = useState<Contract | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    if (!Number.isFinite(contractId) || contractId <= 0) {
      setError('معرّف العقد غير صالح.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api<{ data: Contract }>(`/contracts/${contractId}`)
      setRow(res.data)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setLoading(false)
    }
  }, [contractId])

  useEffect(() => { void load() }, [load])

  const life = useContractLifecycle(() => void load())

  if (loading && !row) return <div className="cd"><SkeletonBlock rows={10} /></div>
  if (error || !row) {
    return (
      <div className="cd">
        <ErrorState
          title="تعذّر تحميل العقد"
          description={error || 'لا توجد بيانات'}
          action={
            <div className="rpm-actions-row">
              <Button variant="secondary" onClick={() => navigate('/bookings')}>رجوع للعقود</Button>
              <Button onClick={() => void load()}>إعادة المحاولة</Button>
            </div>
          }
        />
      </div>
    )
  }

  const vehicleLabel = row.vehicle ? `${row.vehicle.brand} ${row.vehicle.model} · ${row.vehicle.plate}` : '—'
  const ledger = row.ledger
  const inspections = row.inspections ?? []
  const payments = row.payments ?? []
  const documents = row.documents ?? []
  const related = row.related ?? []
  const timeline = row.timeline ?? []

  return (
    <div className="cd">
      <header className="cd-hero">
        <div className="cd-hero__top">
          <button type="button" className="cd-back" onClick={() => navigate('/bookings')}>رجوع للعقود</button>
          <div className="cd-hero__actions">
            <Button size="sm" variant="secondary" onClick={() => void printContract(row.id, toast.error)}>طباعة</Button>
          </div>
        </div>
        <div className="cd-identity">
          <div className="cd-identity__pills">
            <StatusPill status={row.status} label={statusLabel[row.status] || row.status} />
            <DepositBadge deposit={row.deposit} />
            <span className="cd-code">{row.number}</span>
          </div>
          <h1>{row.customer?.full_name || 'عقد'}</h1>
          <p>{vehicleLabel}</p>
        </div>
        <ContractActionsBar
          contract={row}
          onApprove={() => void life.approve(row.id)}
          onAction={(kind) => void life.openAction(kind, row)}
          onPrint={() => void printContract(row.id, toast.error)}
        />
      </header>

      <MetricCardGrid
        label="ملخص مالي"
        mobile="wrap"
        items={[
          { id: 'rate', label: 'السعر اليومي', value: row.frozen_daily_rate, format: 'money', icon: <IconCash size={16} /> },
          { id: 'deposit', label: 'تأمين محتجز', value: ledger?.deposit_held ?? 0, format: 'money', tone: 'info', icon: <IconWallet size={16} /> },
          { id: 'total', label: 'إجمالي العقد', value: ledger?.contract_total ?? row.final_total ?? 0, format: 'money', icon: <IconClipboard size={16} /> },
          {
            id: 'balance',
            label: 'المتبقي على العميل',
            value: ledger?.balance ?? 0,
            format: 'money',
            tone: (ledger?.balance ?? 0) > 0 ? 'warning' : 'success',
            icon: <IconKey size={16} />,
            hint: ledger ? `مدفوع ${formatMoney(ledger.paid)}` : undefined,
          },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>بيانات العقد</h3></div>
        <DetailList
          rows={[
            { label: 'العميل', value: row.customer?.full_name || '—', wide: true },
            { label: 'المركبة', value: vehicleLabel, wide: true },
            { label: 'البداية', value: formatDateTime(row.starts_at) },
            { label: 'النهاية المخططة', value: formatDateTime(row.planned_ends_at) },
            { label: 'النهاية الفعلية', value: row.actual_ends_at ? formatDateTime(row.actual_ends_at) : '—' },
            { label: 'التأمين المتفق', value: formatMoney(row.frozen_agreed_deposit || 0) },
            { label: 'عداد الخروج', value: row.odometer_out != null ? `${formatNumber(row.odometer_out)} كم` : '—' },
            { label: 'عداد العودة', value: row.odometer_in != null ? `${formatNumber(row.odometer_in)} كم` : '—' },
            { label: 'كيلومترات زيادة', value: row.excess_km_amount ? formatMoney(row.excess_km_amount) : '—' },
            { label: 'رسوم أضرار', value: row.damage_fee ? formatMoney(row.damage_fee) : '—' },
            { label: 'ملاحظات', value: row.notes || '—', wide: true },
          ]}
        />
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>فحوصات التسليم والاستلام</h3></div>
        {inspections.length === 0 ? <EmptyState title="لا فحوصات بعد" description="تظهر هنا بعد التسليم أو الاستلام." /> : (
          <div className="cd-stack">
            {inspections.map((ins) => (
              <article key={ins.id} className="cd-inspect">
                <header>
                  <strong>{inspectionTypeLabel[ins.type] || ins.type}</strong>
                  <span className="muted">{ins.inspected_at ? formatDateTime(ins.inspected_at) : '—'}</span>
                </header>
                <p className="muted">
                  عداد {ins.odometer != null ? `${formatNumber(ins.odometer)} كم` : '—'}
                  {ins.fuel_level != null ? ` · بنزين ${ins.fuel_level}%` : ''}
                </p>
                {ins.notes ? <p>{ins.notes}</p> : null}
                {(ins.damages ?? []).map((d, i) => (
                  <p key={d.id ?? i} className="cd-damage">{d.position}: {d.description}</p>
                ))}
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>دفتر الدفعات</h3></div>
        {payments.length === 0 ? <EmptyState title="لا حركات مالية بعد" /> : (
          <div className="rpm-dense-list">
            {payments.map((p) => (
              <div key={p.id} className="rpm-dense-row">
                <div>
                  <strong>{p.direction === 'in' ? 'مقبوض' : 'مدفوع للعميل'} · {formatMoney(p.amount)}</strong>
                  <p className="muted">
                    {p.link_type === 'deposit' ? 'تأمين' : 'عقد'}
                    {p.method ? ` · ${p.method}` : ''}
                    {p.reference ? ` · ${p.reference}` : ''}
                    {p.date ? ` · ${formatDateTime(p.date)}` : ''}
                  </p>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المستندات</h3></div>
        {documents.length === 0 ? <EmptyState title="لا مرفقات بعد" /> : (
          <div className="rpm-media-grid">
            {documents.map((d) => (
              <a key={d.id} className="rpm-media-chip" href={d.url} target="_blank" rel="noreferrer">
                <strong>{d.original_name}</strong>
                <span className="muted">{d.purpose || d.kind}{d.created_at ? ` · ${formatDateTime(d.created_at)}` : ''}</span>
              </a>
            ))}
          </div>
        )}
      </section>

      {related.length ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>عقود مرتبطة</h3></div>
          <div className="rpm-dense-list">
            {related.map((c) => (
              <Link key={c.id} to={`/contracts/${c.id}`} className="rpm-dense-row is-link">
                <div>
                  <strong>{c.number}</strong>
                  <p className="muted">{relationLabel[c.relation] || c.relation} · {statusLabel[c.status] || c.status}</p>
                </div>
              </Link>
            ))}
          </div>
        </section>
      ) : null}

      {timeline.length ? (
        <section className="rpm-panel">
          <div className="rpm-panel__head"><h3>سجل الدورة</h3></div>
          <ol className="cd-timeline">
            {timeline.map((t) => (
              <li key={t.id}>
                <strong>{timelineLabel[t.action] || t.action}</strong>
                <span className="muted">{t.user || 'النظام'} · {t.at ? formatDateTime(t.at) : '—'}</span>
              </li>
            ))}
          </ol>
        </section>
      ) : null}

      <ContractActionSheet
        action={life.action}
        selected={life.selected}
        vehicles={life.vehicles}
        saving={life.saving}
        form={life.actionForm}
        onChange={life.setActionForm}
        onConfirm={() => void life.runAction()}
        onClose={life.closeAction}
      />
    </div>
  )
}
