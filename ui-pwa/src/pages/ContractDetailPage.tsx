import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatDateTime, formatDepositAmount, formatMoney, formatNumber } from '@/shared/lib/format'
import { useAuth } from '@/shared/auth/AuthContext'
import {
  Button, BackNavButton, StatusPill, MetricCardGrid, DetailList, SkeletonBlock, ErrorState, EmptyState,
  RentalProgressBar, IconCash, IconWallet, IconClipboard, IconKey, IconAlert, IconTrash, PhoneActions,
  Sheet, SwitchField, TextAreaField, useToast,
} from '@/shared/ui'
import {
  DepositBadge, inspectionTypeLabel, statusLabel, timelineLabel, type Contract,
} from '@/shared/contracts/contractModel'
import { ContractActionsBar } from '@/shared/contracts/ContractActionsBar'
import { ContractActionSheet } from '@/shared/contracts/ContractActionSheet'
import { useContractLifecycle } from '@/shared/contracts/useContractLifecycle'
import { useContractPrint } from '@/shared/contracts/ContractPrintSheet'
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
  const { isIntegrator, can } = useAuth()
  const toast = useToast()
  const contractId = Number(id)
  const [row, setRow] = useState<Contract | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [vehicleValueOpen, setVehicleValueOpen] = useState(false)
  const [includeVehicleValue, setIncludeVehicleValue] = useState(false)
  const [vehicleValueText, setVehicleValueText] = useState('')
  const [vehicleValueError, setVehicleValueError] = useState('')
  const [savingVehicleValue, setSavingVehicleValue] = useState(false)

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
  const { openPrint, printSheet } = useContractPrint()

  if (loading && !row) return <div className="cd"><SkeletonBlock rows={10} /></div>
  if (error || !row) {
    return (
      <div className="cd">
        <ErrorState
          title="تعذّر تحميل العقد"
          description={error || 'لا توجد بيانات'}
          action={
            <div className="rpm-actions-row">
              <BackNavButton to="/bookings" label="رجوع للعقود" />
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
  const canEditVehicleValue = can('contracts.update')
    && (row.status === 'pending' || row.status === 'approved')

  async function saveVehicleValue() {
    if (!row) return
    if (includeVehicleValue && !vehicleValueText.trim()) {
      setVehicleValueError('اكتب نص ثمن العربية عند تفعيل الالتزام')
      return
    }
    setSavingVehicleValue(true)
    setVehicleValueError('')
    try {
      const res = await api<{ data: Contract }>(`/contracts/${row.id}/vehicle-value`, {
        method: 'POST',
        json: {
          include_vehicle_value_obligation: includeVehicleValue,
          vehicle_value_text: includeVehicleValue ? vehicleValueText.trim() : null,
        },
      })
      setRow(res.data)
      setVehicleValueOpen(false)
      toast.success('تم تحديث التزام ثمن العربية')
      void load()
    } catch (e) {
      setVehicleValueError((e as ApiError).message)
      toast.error((e as ApiError).message)
    } finally {
      setSavingVehicleValue(false)
    }
  }

  const balanceDue = Number(ledger?.balance ?? 0)

  return (
    <div className="cd">
      <header className="cd-hero">
        <div className="cd-hero__top">
          <BackNavButton to="/bookings" label="رجوع للعقود" />
          {isIntegrator ? (
            <button
              type="button"
              className="rpm-icon-btn cd-hero__delete"
              aria-label="حذف"
              title="حذف"
              onClick={() => {
                void life.softDelete(row.id).then((ok) => {
                  if (ok) navigate('/bookings', { replace: true })
                })
              }}
            >
              <IconTrash size={16} />
            </button>
          ) : null}
        </div>
        <div className="cd-identity">
          <div className="cd-identity__pills">
            <StatusPill status={row.status} label={statusLabel[row.status] || row.status} />
            <DepositBadge
              deposit={row.deposit}
              agreedAmount={row.frozen_agreed_deposit}
              currency={row.frozen_deposit_currency}
            />
            <span className="cd-code">{row.number}</span>
          </div>
          <div className="cd-identity__who">
            <div className="cd-identity__who-row">
              <h1>{row.customer?.full_name || 'عقد'}</h1>
              {row.customer?.phone ? (
                <PhoneActions phone={row.customer.phone} name={row.customer.full_name} compact />
              ) : null}
            </div>
            <p>{vehicleLabel}</p>
          </div>
          <div className={`cd-identity__progress${balanceDue > 0 ? ' has-deferred' : ''}`}>
            <RentalProgressBar progress={row.progress} />
            {balanceDue > 0 ? (
              <div className="cd-deferred">
                <span className="cd-deferred__label">متأجل</span>
                <strong className="cd-deferred__amount tabular-nums">{formatMoney(balanceDue)}</strong>
                {row.balance_due_on ? (
                  <span className="cd-deferred__due muted">{formatDate(row.balance_due_on)}</span>
                ) : (
                  <span className="cd-deferred__due muted">بدون تاريخ</span>
                )}
              </div>
            ) : null}
          </div>
        </div>
        <ContractActionsBar
          contract={row}
          onApprove={() => void life.approve(row.id)}
          onAction={(kind) => void life.openAction(kind, row)}
          onPrint={(kind = 'contract') => openPrint(row.id, kind)}
        />
      </header>
      {printSheet}

      <MetricCardGrid
        className="cd-metrics"
        label="ملخص مالي"
        mobile="wrap"
        items={[
          {
            id: 'rate',
            label: row.rent_basis === 'half_monthly' || row.rent_basis === 'weekly'
              ? 'سعر نص الشهر (١٥ يوماً)'
              : row.rent_basis === 'monthly'
                ? 'السعر الشهري (٣٠ يوماً)'
                : 'السعر اليومي',
            value: row.rent_basis === 'half_monthly' || row.rent_basis === 'weekly'
              ? (row.frozen_half_monthly_rate ?? row.frozen_daily_rate)
              : row.rent_basis === 'monthly'
                ? (row.frozen_monthly_rate ?? row.frozen_daily_rate)
                : row.frozen_daily_rate,
            format: 'money',
            icon: <IconCash size={16} />,
            hint: row.rent_basis === 'half_monthly' || row.rent_basis === 'weekly'
              ? 'أيام الزيادة = نص الشهر ÷ 15'
              : row.rent_basis === 'monthly'
                ? 'أيام الزيادة = الشهري ÷ 30'
                : 'الشريحة حسب المدة: ١–١٠ يومي · ١١–٢٠ نص شهري · ٢١+ شهري',
          },
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
          {
            id: 'violations',
            label: 'مخالفات مفتوحة (مطلوب)',
            value: row.open_violations_total ?? ledger?.violations_due ?? 0,
            format: 'money',
            tone: (row.open_violations_total ?? ledger?.violations_due ?? 0) > 0 ? 'warning' : 'success',
            icon: <IconAlert size={16} />,
            hint: 'متابعة تشغيلية',
          },
        ]}
      />

      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>بيانات العقد</h3></div>
        <DetailList
          rows={[
            {
              label: 'العميل',
              value: (
                <span className="rpm-inline-stack">
                  {row.customer?.full_name || '—'}
                  {row.customer?.phone ? <PhoneActions phone={row.customer.phone} name={row.customer.full_name} compact /> : null}
                </span>
              ),
              wide: true,
            },
            { label: 'العربية', value: vehicleLabel, wide: true },
            { label: 'البداية', value: formatDate(row.starts_at) },
            { label: 'النهاية المخططة', value: formatDate(row.planned_ends_at) },
            { label: 'النهاية الفعلية', value: row.actual_ends_at ? formatDate(row.actual_ends_at) : '—' },
            { label: 'التأمين المتفق', value: formatDepositAmount(row.frozen_agreed_deposit || 0, row.frozen_deposit_currency) },
            {
              label: 'بدل الكيلومتر اليومي',
              value: row.frozen_daily_km_allowance != null
                ? `${formatNumber(row.frozen_daily_km_allowance)} كم/يوم`
                : '—',
            },
            {
              label: 'سعر الكيلومتر الزائد',
              value: row.frozen_excess_km_rate != null
                ? formatMoney(row.frozen_excess_km_rate)
                : '—',
            },
            {
              label: 'كم زيادة مدفوعة مقدماً',
              value: (row.prepaid_excess_km ?? 0) > 0
                ? `${formatNumber(row.prepaid_excess_km || 0)} كم · ${formatMoney(row.prepaid_excess_km_amount || 0)}`
                : '—',
            },
            {
              label: 'تاريخ تحصيل المتبقي',
              value: row.balance_due_on
                ? formatDate(row.balance_due_on)
                : ((ledger?.balance ?? 0) > 0 ? 'غير محدّد' : '—'),
            },
            { label: 'أنشأه', value: row.created_by || row.sales_user?.name || '—' },
            { label: 'عداد الخروج', value: row.odometer_out != null ? `${formatNumber(row.odometer_out)} كم` : '—' },
            { label: 'عداد العودة', value: row.odometer_in != null ? `${formatNumber(row.odometer_in)} كم` : '—' },
            {
              label: 'كم زيادة فعلية',
              value: row.excess_km != null ? `${formatNumber(row.excess_km)} كم` : '—',
            },
            {
              label: 'رسوم زيادة إضافية',
              value: row.excess_km_amount ? formatMoney(row.excess_km_amount) : '—',
            },
            { label: 'رسوم أضرار', value: row.damage_fee ? formatMoney(row.damage_fee) : '—' },
            {
              label: 'خدمة تسليم واستلام',
              value: row.has_delivery_service || (row.delivery_service_fee ?? 0) > 0
                ? `${formatMoney(row.delivery_service_fee || 0)}${row.delivery_service_notes ? ` · ${row.delivery_service_notes}` : ''}`
                : '—',
              wide: true,
            },
            {
              label: 'ثمن العربية (عقد/إقرار)',
              value: row.include_vehicle_value_obligation
                ? (row.frozen_vehicle_value_text || 'مفعّل بلا نص')
                : 'غير ملزم — البند مخفي',
              wide: true,
            },
            { label: 'ملاحظات', value: row.notes || '—', wide: true },
          ]}
        />
        {canEditVehicleValue ? (
          <div className="rpm-actions-row" style={{ marginBlockStart: '0.75rem' }}>
            <Button
              variant="secondary"
              onClick={() => {
                setIncludeVehicleValue(Boolean(row.include_vehicle_value_obligation))
                setVehicleValueText(row.frozen_vehicle_value_text || '')
                setVehicleValueError('')
                setVehicleValueOpen(true)
              }}
            >
              تعديل التزام ثمن العربية
            </Button>
          </div>
        ) : null}
      </section>

      <Sheet
        open={vehicleValueOpen}
        onClose={() => !savingVehicleValue && setVehicleValueOpen(false)}
        title="التزام ثمن العربية"
      >
        <div className="rpm-form-grid">
          <SwitchField
            label="ملزم بثمن العربية في العقد والإقرار"
            checked={includeVehicleValue}
            onChange={setIncludeVehicleValue}
          />
          {includeVehicleValue ? (
            <TextAreaField
              label="نص ثمن العربية"
              required
              value={vehicleValueText}
              error={vehicleValueError}
              onChange={(e) => setVehicleValueText(e.target.value)}
              hint="نص حر يُجمَّد على هذا العقد فقط — لا يغيّر ثمن العربية في بطاقة الأسطول"
            />
          ) : (
            <p className="muted">عند الإيقاف يختفي البند السادس من العقد وفقرة الثمن من الإقرار.</p>
          )}
          <div className="rpm-actions-row">
            <Button variant="secondary" disabled={savingVehicleValue} onClick={() => setVehicleValueOpen(false)}>إلغاء</Button>
            <Button disabled={savingVehicleValue} onClick={() => void saveVehicleValue()}>
              {savingVehicleValue ? 'جارٍ الحفظ…' : 'حفظ'}
            </Button>
          </div>
        </div>
      </Sheet>

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
                <strong>{t.summary || timelineLabel[t.action] || t.action}</strong>
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
