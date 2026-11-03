import { api, getToken, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { printHtmlDocument } from '@/utils/printHtml'
import { statusLabel } from './contractModel'

export type PrintDocKind = 'contract' | 'invoice' | 'receipt'

type PrintContract = Record<string, unknown> & {
  number: string
  status: string
  customer?: { full_name?: string; phone?: string }
  vehicle?: { brand: string; model: string; plate: string }
  starts_at: string
  planned_ends_at: string
  actual_ends_at?: string | null
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  period_rent?: number | null
  excess_km_amount?: number | null
  damage_fee?: number | null
  final_total?: number | null
  odometer_out?: number | null
  odometer_in?: number | null
  deposit?: {
    original_amount?: number
    status?: string
    remaining?: number
    deductions_total?: number
  } | null
  inspections?: Array<{
    type: string
    odometer?: number | null
    fuel_level?: number | null
    inspected_at?: string | null
  }>
  payments?: Array<{
    amount: number
    direction: string
    method: string
    reference?: string | null
    paid_at?: string | null
    date?: string | null
  }>
}

type PrintTenant = {
  center_name?: string
  print_footer?: string
  logo_path?: string | null
  print_phone?: string | null
  print_address?: string | null
  print_commercial_register?: string | null
  print_tax_info?: string | null
  contract_terms?: string | null
  logo_src?: string | null
}

type PrintPayload = {
  tenant: PrintTenant
  contract: PrintContract
}

const titles: Record<PrintDocKind, string> = {
  contract: 'عقد تأجير',
  invoice: 'فاتورة / كشف حساب',
  receipt: 'سند قبض / محضر تسليم واستلام',
}

const snapshotType: Record<PrintDocKind, string> = {
  contract: 'contract',
  invoice: 'invoice',
  receipt: 'receipt',
}

function esc(s: string) {
  return s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
}

function brandFallbackSrc() {
  const base = (typeof import.meta !== 'undefined' && import.meta.env?.BASE_URL) || '/'
  return `${base}brand/rpm-logo.webp`
}

async function resolveLogoSrc(logoPath?: string | null): Promise<string> {
  const fallback = brandFallbackSrc()
  if (!logoPath) return fallback
  try {
    const path = logoPath.startsWith('/api/')
      ? logoPath
      : logoPath.startsWith('/media/')
        ? `/api${logoPath}`
        : logoPath.startsWith('http')
          ? logoPath
          : `/api${logoPath.startsWith('/') ? logoPath : `/${logoPath}`}`

    if (path.startsWith('http') || path.startsWith('data:') || path.startsWith('blob:')) {
      return path
    }

    const headers: HeadersInit = { Accept: '*/*' }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
    const res = await fetch(path, { headers })
    if (!res.ok) return fallback
    const blob = await res.blob()
    return URL.createObjectURL(blob)
  } catch {
    return fallback
  }
}

function headerBlock(tenant: PrintTenant) {
  const logo = tenant.logo_src || brandFallbackSrc()
  const meta = [
    tenant.print_phone ? `هاتف: <span dir="ltr">${esc(tenant.print_phone)}</span>` : '',
    tenant.print_address ? `العنوان: ${esc(tenant.print_address)}` : '',
    tenant.print_commercial_register ? `س.ت: ${esc(tenant.print_commercial_register)}` : '',
    tenant.print_tax_info ? `ضريبي: ${esc(tenant.print_tax_info)}` : '',
  ].filter(Boolean).join(' · ')

  return `
    <header class="print-head">
      <img class="print-logo" src="${esc(logo)}" alt="شعار الشركة" />
      <div>
        <h1>${esc(tenant.center_name || 'مركز التأجير')}</h1>
        ${meta ? `<p class="muted">${meta}</p>` : ''}
      </div>
    </header>`
}

function shell(title: string, body: string) {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${esc(title)}</title>
    <style>
      body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a}
      h1{margin:0 0 4px;font-size:22px}
      h2{margin:20px 0 8px;font-size:16px}
      .muted{color:#64748b}
      table{width:100%;border-collapse:collapse;margin-top:12px}
      td,th{border:1px solid #cbd5e1;padding:8px;text-align:start}
      th{background:#f8fafc;width:32%}
      .foot{margin-top:24px;color:#64748b;font-size:12px}
      .terms{margin-top:20px;white-space:pre-wrap;font-size:13px;line-height:1.7}
      .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px}
      .print-head{display:flex;align-items:center;gap:16px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:12px}
      .print-logo{width:96px;height:auto;object-fit:contain}
      @media print{.print-logo{max-width:110px}}
    </style>
  </head><body>${body}</body></html>`
}

function contractBody(tenant: PrintTenant, c: PrintContract) {
  const terms = (tenant.contract_terms || '').trim()
  return `
    ${headerBlock(tenant)}
    <h2>عقد تأجير · رقم ${esc(c.number)}</h2>
    <p class="muted"><span class="badge">${esc(statusLabel[c.status] || c.status)}</span></p>
    <table>
      <tr><th>العميل</th><td>${esc(c.customer?.full_name || '—')}${c.customer?.phone ? ` · <span dir="ltr">${esc(c.customer.phone)}</span>` : ''}</td></tr>
      <tr><th>العربية</th><td>${c.vehicle ? esc(`${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`) : '—'}</td></tr>
      <tr><th>البداية</th><td>${formatDate(c.starts_at)}</td></tr>
      <tr><th>النهاية المخططة</th><td>${formatDate(c.planned_ends_at)}</td></tr>
      <tr><th>السعر اليومي</th><td>${formatMoney(c.frozen_daily_rate)}</td></tr>
      <tr><th>التأمين النقدي</th><td>${formatMoney(c.frozen_agreed_deposit || 0)}</td></tr>
      <tr><th>الإجمالي النهائي</th><td>${c.final_total != null ? formatMoney(c.final_total) : '—'}</td></tr>
    </table>
    ${terms ? `<h2>بنود وشروط العقد</h2><div class="terms">${esc(terms)}</div>` : ''}
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

function invoiceBody(tenant: PrintTenant, c: PrintContract) {
  const payments = c.payments || []
  const rent = c.period_rent ?? null
  const excess = c.excess_km_amount ?? 0
  const damage = c.damage_fee ?? 0
  const total = c.final_total ?? ((rent || 0) + excess + damage)
  const paidIn = payments.filter((p) => p.direction === 'in').reduce((s, p) => s + Number(p.amount || 0), 0)
  const paidOut = payments.filter((p) => p.direction === 'out').reduce((s, p) => s + Number(p.amount || 0), 0)
  const balance = Math.max(0, total - (paidIn - paidOut))
  const rows = payments.map((p) => `
    <tr>
      <td>${esc(p.date || p.paid_at || '—')}</td>
      <td>${esc(p.direction === 'in' ? 'قبض' : 'صرف')}</td>
      <td>${esc(p.method || '—')}</td>
      <td>${esc(p.reference || '—')}</td>
      <td>${formatMoney(p.amount)}</td>
    </tr>`).join('')

  return `
    ${headerBlock(tenant)}
    <h2>فاتورة / كشف حساب · عقد ${esc(c.number)}</h2>
    <p class="muted">${esc(c.customer?.full_name || '—')}</p>
    <table>
      <tr><th>إيجار الفترة</th><td>${rent != null ? formatMoney(rent) : '—'}</td></tr>
      <tr><th>كيلومترات زيادة</th><td>${formatMoney(excess)}</td></tr>
      <tr><th>رسوم أضرار</th><td>${formatMoney(damage)}</td></tr>
      <tr><th>الإجمالي</th><td><strong>${formatMoney(total)}</strong></td></tr>
      <tr><th>المدفوع (صافي)</th><td>${formatMoney(paidIn - paidOut)}</td></tr>
      <tr><th>المتبقي على العميل</th><td><strong>${formatMoney(balance)}</strong></td></tr>
      <tr><th>التأمين المحتجز</th><td>${formatMoney(c.deposit?.remaining ?? c.deposit?.original_amount ?? c.frozen_agreed_deposit ?? 0)}</td></tr>
    </table>
    <h2>حركة الدفعات</h2>
    <table>
      <tr><th>التاريخ</th><th>الاتجاه</th><th>الطريقة</th><th>المرجع</th><th>المبلغ</th></tr>
      ${rows || '<tr><td colspan="5">لا دفعات مسجّلة بعد.</td></tr>'}
    </table>
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

function receiptBody(tenant: PrintTenant, c: PrintContract) {
  const handover = (c.inspections || []).find((i) => i.type === 'handover')
  const ret = (c.inspections || []).find((i) => i.type === 'return')
  const deposit = c.deposit
  return `
    ${headerBlock(tenant)}
    <h2>سند قبض التأمين ومحضر التسليم/الاستلام</h2>
    <p class="muted">عقد ${esc(c.number)}</p>
    <h2>بيانات العميل والعربية</h2>
    <table>
      <tr><th>العميل</th><td>${esc(c.customer?.full_name || '—')}${c.customer?.phone ? ` · <span dir="ltr">${esc(c.customer.phone)}</span>` : ''}</td></tr>
      <tr><th>العربية</th><td>${c.vehicle ? esc(`${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`) : '—'}</td></tr>
    </table>
    <h2>سند قبض التأمين</h2>
    <table>
      <tr><th>مبلغ التأمين</th><td>${formatMoney(deposit?.original_amount ?? c.frozen_agreed_deposit ?? 0)}</td></tr>
      <tr><th>حالة التأمين</th><td>${esc(deposit?.status || 'لم يُقبض بعد')}</td></tr>
      <tr><th>الخصومات</th><td>${formatMoney(deposit?.deductions_total ?? 0)}</td></tr>
      <tr><th>المتبقي</th><td>${formatMoney(deposit?.remaining ?? deposit?.original_amount ?? 0)}</td></tr>
    </table>
    <h2>محضر التسليم</h2>
    <table>
      <tr><th>عداد الخروج</th><td>${handover?.odometer ?? c.odometer_out ?? '—'}</td></tr>
      <tr><th>مستوى البنزين</th><td>${handover?.fuel_level != null ? `${handover.fuel_level}%` : '—'}</td></tr>
      <tr><th>وقت التسليم</th><td>${handover?.inspected_at ? formatDate(handover.inspected_at) : '—'}</td></tr>
    </table>
    <h2>محضر الاستلام</h2>
    <table>
      <tr><th>عداد العودة</th><td>${ret?.odometer ?? c.odometer_in ?? '—'}</td></tr>
      <tr><th>مستوى البنزين</th><td>${ret?.fuel_level != null ? `${ret.fuel_level}%` : '—'}</td></tr>
      <tr><th>وقت الاستلام</th><td>${ret?.inspected_at ? formatDate(ret.inspected_at) : (c.actual_ends_at ? formatDate(c.actual_ends_at) : '—')}</td></tr>
    </table>
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

async function loadPrintPayload(id: number): Promise<PrintPayload> {
  const res = await api<{ data: PrintPayload }>(`/contracts/${id}/print`)
  const { tenant, contract } = res.data
  const number = String(contract?.number || (contract as any)?.code || '').trim()
  if (!number) {
    throw Object.assign(new Error('تعذّر تحميل بيانات العقد للطباعة.'), { status: 422 })
  }
  const logo_src = await resolveLogoSrc(tenant?.logo_path)
  return {
    tenant: { ...tenant, logo_src },
    contract: { ...contract, number },
  }
}

function renderDoc(kind: PrintDocKind, payload: PrintPayload): string {
  const { tenant, contract } = payload
  const title = `${titles[kind]} ${contract.number}`
  if (kind === 'invoice') return shell(title, invoiceBody(tenant, contract))
  if (kind === 'receipt') return shell(title, receiptBody(tenant, contract))
  return shell(title, contractBody(tenant, contract))
}

/** Print one of the three core contract documents via hidden iframe. */
export async function printContractDocument(
  id: number,
  kind: PrintDocKind,
  onError: (message: string) => void,
) {
  try {
    const payload = await loadPrintPayload(id)
    await api('/print-snapshots', {
      method: 'POST',
      json: {
        type: snapshotType[kind],
        record_type: 'contract',
        record_id: id,
        payload,
      },
    }).catch(() => undefined)

    printHtmlDocument(renderDoc(kind, payload))
  } catch (e) {
    onError((e as ApiError).message || `تعذّر طباعة ${titles[kind]}.`)
  }
}

/** @deprecated Prefer printContractDocument(id, 'contract', …) */
export async function printContract(id: number, onError: (message: string) => void) {
  return printContractDocument(id, 'contract', onError)
}
