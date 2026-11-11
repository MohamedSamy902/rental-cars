import { api, getToken, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { printHtmlDocument } from '@/utils/printHtml'
import { statusLabel } from './contractModel'
import { isRentalContractBody, shellRentalContractHtml } from './rentalContractPrint'
import { buildInvoiceBody, isInvoiceBody, shellInvoiceHtml } from './invoicePrint'

export type PrintDocKind = 'contract' | 'invoice' | 'receipt'

type PrintContract = Record<string, unknown> & {
  number: string
  status: string
  customer?: {
    full_name?: string
    phone?: string
    address?: string | null
    national_id?: string | null
    passport_number?: string | null
    license_number?: string
    license_expires_on?: string | null
    national_id_expires_on?: string | null
    national_id_image_path?: string | null
    national_id_back_path?: string | null
  }
  vehicle?: {
    brand: string
    model: string
    plate: string
    year?: number | string | null
    color?: string | null
  }
  starts_at: string
  planned_ends_at: string
  actual_ends_at?: string | null
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  approved_days?: number | null
  period_rent?: number | null
  excess_km?: number | null
  excess_km_amount?: number | null
  damage_fee?: number | null
  other_fees?: number | null
  other_fees_description?: string | null
  has_delivery_service?: boolean | null
  delivery_service_fee?: number | null
  delivery_service_notes?: string | null
  discount?: number | null
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
  _id_front_src?: string | null
  _id_back_src?: string | null
}

type PrintTenant = {
  center_name?: string
  print_footer?: string
  logo_path?: string | null
  print_phone?: string | null
  print_address?: string | null
  print_commercial_register?: string | null
  print_tax_info?: string | null
  print_signer_name?: string | null
  company_tagline?: string | null
  contract_terms?: string | null
  logo_src?: string | null
}

export type PrintTemplate = {
  id: number
  type: string
  title: string
  content: string
  format: string
  version: number
  is_active: boolean
  rendered_html?: string
  variables?: Record<string, string>
}

export type PrintPayload = {
  tenant: PrintTenant
  contract: PrintContract
  templates?: {
    contract?: PrintTemplate | null
    handover_receipt?: PrintTemplate | null
  }
}

const titles: Record<PrintDocKind, string> = {
  contract: 'عقد تأجير',
  invoice: 'فاتورة',
  receipt: 'إيصال الاستلام والتأمين',
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

    if (path.startsWith('http') || path.startsWith('data:') || path.startsWith('blob:') || path.includes('/brand/')) {
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
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(title)}</title>
    <style>
      *{box-sizing:border-box}
      body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a;margin:0}
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
      .id-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
      .id-grid figure{margin:0;border:1px solid #cbd5e1;padding:8px;text-align:center}
      .id-grid img{max-width:100%;max-height:220px;object-fit:contain}
      .id-grid figcaption{margin-top:6px;font-size:12px;color:#64748b}
      @media screen{
        html,body{overflow-x:auto;-webkit-overflow-scrolling:touch}
        body{--rpm-preview-scale:1}
        .rpm-preview-root{transform:scale(var(--rpm-preview-scale));transform-origin:top center;width:100%}
      }
      @media print{
        .rpm-preview-root{transform:none!important;width:100%!important}
        .print-logo{max-width:110px}
      }
    </style>
  </head><body><div class="rpm-preview-root">${body}</div></body></html>`
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
      <tr><th>خدمة تسليم واستلام السيارة</th><td>${
        (c.has_delivery_service || (c.delivery_service_fee ?? 0) > 0)
          ? `${formatMoney(c.delivery_service_fee || 0)}${c.delivery_service_notes ? ` · ${esc(c.delivery_service_notes)}` : ''}`
          : '—'
      }</td></tr>
      <tr><th>الإجمالي النهائي</th><td>${c.final_total != null ? formatMoney(c.final_total) : '—'}</td></tr>
    </table>
    ${terms ? `<h2>بنود وشروط العقد</h2><div class="terms">${esc(terms)}</div>` : ''}
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

function invoiceBody(tenant: PrintTenant, c: PrintContract) {
  return buildInvoiceBody(tenant, c)
}

function idCardsBlock(c: PrintContract) {
  const front = c._id_front_src
  const back = c._id_back_src
  if (!front && !back) return ''
  return `
    <h2>صورة البطاقة الشخصية</h2>
    <div class="id-grid">
      <figure>
        ${front ? `<img src="${esc(front)}" alt="وش البطاقة" />` : '<p class="muted">—</p>'}
        <figcaption>وش البطاقة</figcaption>
      </figure>
      <figure>
        ${back ? `<img src="${esc(back)}" alt="ضهر البطاقة" />` : '<p class="muted">—</p>'}
        <figcaption>ضهر البطاقة</figcaption>
      </figure>
    </div>`
}

function receiptBody(tenant: PrintTenant, c: PrintContract) {
  const handover = (c.inspections || []).find((i) => i.type === 'handover')
  const ret = (c.inspections || []).find((i) => i.type === 'return')
  const deposit = c.deposit
  return `
    ${headerBlock(tenant)}
    <h2>إيصال الاستلام والتأمين</h2>
    <p class="muted">عقد ${esc(c.number)}</p>
    <h2>بيانات العميل والعربية</h2>
    <table>
      <tr><th>العميل</th><td>${esc(c.customer?.full_name || '—')}${c.customer?.phone ? ` · <span dir="ltr">${esc(c.customer.phone)}</span>` : ''}</td></tr>
      <tr><th>العربية</th><td>${c.vehicle ? esc(`${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`) : '—'}</td></tr>
    </table>
    ${idCardsBlock(c)}
    <h2>بيانات التأمين</h2>
    <table>
      <tr><th>مبلغ التأمين</th><td>${formatMoney(deposit?.original_amount ?? c.frozen_agreed_deposit ?? 0)}</td></tr>
      <tr><th>حالة التأمين</th><td>${esc(deposit?.status || 'لم يُقبض بعد')}</td></tr>
      <tr><th>الخصومات</th><td>${formatMoney(deposit?.deductions_total ?? 0)}</td></tr>
      <tr><th>المتبقي</th><td>${formatMoney(deposit?.remaining ?? deposit?.original_amount ?? 0)}</td></tr>
    </table>
    <h2>عند التسليم</h2>
    <table>
      <tr><th>عداد الخروج</th><td>${handover?.odometer ?? c.odometer_out ?? '—'}</td></tr>
      <tr><th>وقت التسليم</th><td>${handover?.inspected_at ? formatDate(handover.inspected_at) : '—'}</td></tr>
    </table>
    <h2>عند الاستلام</h2>
    <table>
      <tr><th>عداد العودة</th><td>${ret?.odometer ?? c.odometer_in ?? '—'}</td></tr>
      <tr><th>وقت الاستلام</th><td>${ret?.inspected_at ? formatDate(ret.inspected_at) : (c.actual_ends_at ? formatDate(c.actual_ends_at) : '—')}</td></tr>
    </table>
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

async function resolveMediaSrc(path?: string | null): Promise<string | null> {
  if (!path) return null
  try {
    const resolved = path.startsWith('/api/')
      ? path
      : path.startsWith('/media/')
        ? `/api${path}`
        : path.startsWith('http') || path.startsWith('data:') || path.startsWith('blob:')
          ? path
          : `/api${path.startsWith('/') ? path : `/${path}`}`
    if (resolved.startsWith('http') || resolved.startsWith('data:') || resolved.startsWith('blob:')) {
      return resolved
    }
    const headers: HeadersInit = { Accept: '*/*' }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
    const res = await fetch(resolved, { headers })
    if (!res.ok) return null
    const blob = await res.blob()
    return URL.createObjectURL(blob)
  } catch {
    return null
  }
}

async function loadPrintPayload(id: number): Promise<PrintPayload> {
  const res = await api<{ data: PrintPayload }>(`/contracts/${id}/print`)
  const { tenant, contract, templates } = res.data
  const number = String(contract?.number || (contract as any)?.code || '').trim()
  if (!number) {
    throw Object.assign(new Error('تعذّر تحميل بيانات العقد للطباعة.'), { status: 422 })
  }
  const logo_src = await resolveLogoSrc(tenant?.logo_path)
  const [_id_front_src, _id_back_src] = await Promise.all([
    resolveMediaSrc(contract.customer?.national_id_image_path),
    resolveMediaSrc(contract.customer?.national_id_back_path),
  ])
  return {
    tenant: { ...tenant, logo_src },
    contract: { ...contract, number, _id_front_src, _id_back_src },
    templates,
  }
}

/** Rewrite logo placeholders inside server-rendered HTML to the resolved blob/URL. */
function applyRuntimeAssets(html: string, tenant: PrintTenant): string {
  const logo = tenant.logo_src || brandFallbackSrc()
  return html
    .replaceAll('{{logo_src}}', esc(logo))
    .replace(/src="([^"]*logo[^"]*)"/gi, (full, src: string) => {
      if (src.startsWith('blob:') || src.startsWith('data:') || src.includes('/brand/')) return full
      if (tenant.logo_path && src.includes(tenant.logo_path)) {
        return `src="${esc(logo)}"`
      }
      if (src.startsWith('/api/') || src.startsWith('/media/')) {
        return `src="${esc(logo)}"`
      }
      return full
    })
}

function wrapTemplateHtml(title: string, html: string, kind: PrintDocKind = 'contract'): string {
  const trimmed = html.trim()
  if (/^<!doctype/i.test(trimmed) || /<html[\s>]/i.test(trimmed)) {
    return trimmed
  }
  if (kind === 'contract' && isRentalContractBody(trimmed)) {
    return shellRentalContractHtml(trimmed, title)
  }
  if (kind === 'invoice' && isInvoiceBody(trimmed)) {
    return shellInvoiceHtml(trimmed, title)
  }
  return shell(title, trimmed)
}

/** Build a complete printable HTML document for a contract print kind. */
export function renderDoc(kind: PrintDocKind, payload: PrintPayload): string {
  const { tenant, contract, templates } = payload
  const title = `${titles[kind]} ${contract.number}`

  if (kind === 'invoice') {
    return shellInvoiceHtml(invoiceBody(tenant, contract), title)
  }

  if (kind === 'contract' && templates?.contract?.rendered_html) {
    const body = applyRuntimeAssets(templates.contract.rendered_html, tenant)
    return wrapTemplateHtml(title, body, 'contract')
  }

  if (kind === 'receipt' && templates?.handover_receipt?.rendered_html) {
    const body = applyRuntimeAssets(templates.handover_receipt.rendered_html, tenant)
    return wrapTemplateHtml(title, body, 'receipt')
  }

  if (kind === 'receipt') return shell(title, receiptBody(tenant, contract))
  if (kind === 'contract') {
    // Fallback stub still uses the simple shell.
    return shell(title, contractBody(tenant, contract))
  }
  return shell(title, contractBody(tenant, contract))
}

/** Extract the editable body from a full HTML document (or return as-is). */
export function extractPrintableBody(html: string): string {
  const trimmed = html.trim()
  const match = trimmed.match(/<body[^>]*>([\s\S]*)<\/body>/i)
  return match ? match[1].trim() : trimmed
}

/** Wrap an edited body back into a full print document for the given kind. */
export function wrapEditedPrintHtml(kind: PrintDocKind, title: string, bodyOrFull: string): string {
  return wrapTemplateHtml(title, bodyOrFull, kind)
}

export async function loadContractPrintPayload(id: number): Promise<PrintPayload> {
  return loadPrintPayload(id)
}

/** Persist a print snapshot then open the browser print dialog. */
export async function commitPrintDocument(
  id: number,
  kind: PrintDocKind,
  payload: PrintPayload,
  html: string,
  opts?: { editedBeforePrint?: boolean },
) {
  const templateMeta =
    kind === 'contract'
      ? payload.templates?.contract
      : kind === 'receipt'
        ? payload.templates?.handover_receipt
        : null

  await api('/print-snapshots', {
    method: 'POST',
    json: {
      type: snapshotType[kind],
      record_type: 'contract',
      record_id: id,
      payload: {
        ...payload,
        template_version: templateMeta?.version ?? null,
        template_id: templateMeta?.id ?? null,
        edited_before_print: Boolean(opts?.editedBeforePrint),
      },
    },
  }).catch(() => undefined)

  printHtmlDocument(html)
}

/** Print one of the three core contract documents via hidden iframe. */
export async function printContractDocument(
  id: number,
  kind: PrintDocKind,
  onError: (message: string) => void,
) {
  try {
    const payload = await loadPrintPayload(id)
    const templateMeta =
      kind === 'contract'
        ? payload.templates?.contract
        : kind === 'receipt'
          ? payload.templates?.handover_receipt
          : null

    await api('/print-snapshots', {
      method: 'POST',
      json: {
        type: snapshotType[kind],
        record_type: 'contract',
        record_id: id,
        payload: {
          ...payload,
          template_version: templateMeta?.version ?? null,
          template_id: templateMeta?.id ?? null,
        },
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
