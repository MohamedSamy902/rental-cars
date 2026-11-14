import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/format'

type InvoiceCustomer = {
  full_name?: string
  phone?: string
  address?: string | null
  national_id?: string | null
  passport_number?: string | null
  license_number?: string
  license_expires_on?: string | null
  national_id_expires_on?: string | null
}

type InvoiceVehicle = {
  brand?: string
  model?: string
  plate?: string
  year?: number | string | null
  color?: string | null
}

type InvoiceContract = {
  number: string
  starts_at: string
  planned_ends_at: string
  actual_ends_at?: string | null
  frozen_daily_rate: number
  frozen_agreed_deposit?: number
  approved_days?: number | null
  period_rent?: number | null
  prepaid_excess_km?: number | null
  prepaid_excess_km_amount?: number | null
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
  customer?: InvoiceCustomer
  vehicle?: InvoiceVehicle
  deposit?: {
    original_amount?: number
    remaining?: number
  } | null
  inspections?: Array<{
    type: string
    odometer?: number | null
    inspected_at?: string | null
  }>
  payments?: Array<{
    amount: number
    direction: string
  }>
}

type InvoiceTenant = {
  center_name?: string
  print_footer?: string
  logo_src?: string | null
  print_phone?: string | null
  print_commercial_register?: string | null
  print_tax_info?: string | null
}

function esc(s: string) {
  return s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
}

function dash(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  return String(value)
}

function moneyOrBlank(value: number | null | undefined): string {
  if (value == null || Number.isNaN(Number(value))) return ''
  return formatMoney(Number(value))
}

function rentalDays(c: InvoiceContract): number {
  if (c.approved_days && c.approved_days > 0) return c.approved_days
  const start = new Date(c.starts_at)
  const end = new Date(c.actual_ends_at || c.planned_ends_at)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return 0
  const ms = end.getTime() - start.getTime()
  return Math.max(1, Math.ceil(ms / 86_400_000) || 1)
}

function nationalityOf(customer?: InvoiceCustomer): string {
  if (customer?.national_id) return 'مصري'
  if (customer?.passport_number) return ''
  return ''
}

function invoiceNumber(contractNumber: string): string {
  const raw = contractNumber.replace(/^C-?/i, '')
  return `INV-${raw || contractNumber}`
}

function whenLabel(iso?: string | null): string {
  if (!iso) return ''
  return formatDateTime(iso)
}

/** Build the paper-form bilingual invoice body (no document shell). */
export function buildInvoiceBody(tenant: InvoiceTenant, c: InvoiceContract): string {
  const days = rentalDays(c)
  const daily = Number(c.frozen_daily_rate || 0)
  const rent = c.period_rent != null ? Number(c.period_rent) : days * daily
  const deposit = Number(c.deposit?.original_amount ?? c.frozen_agreed_deposit ?? 0)
  const prepaidQty = Number(c.prepaid_excess_km ?? 0)
  const prepaidAmt = Number(c.prepaid_excess_km_amount ?? 0)
  const excessQty = Number(c.excess_km ?? 0)
  const excessAmt = Number(c.excess_km_amount ?? 0)
  const other = Number(c.other_fees ?? 0)
  const damage = Number(c.damage_fee ?? 0)
  const delivery = Number(c.delivery_service_fee ?? 0)
  const discount = Number(c.discount ?? 0)
  const total = c.final_total != null
    ? Number(c.final_total)
    : Math.max(0, rent + prepaidAmt + excessAmt + other + damage + delivery - discount)
  const paidIn = (c.payments || []).filter((p) => p.direction === 'in').reduce((s, p) => s + Number(p.amount || 0), 0)
  const paidOut = (c.payments || []).filter((p) => p.direction === 'out').reduce((s, p) => s + Number(p.amount || 0), 0)
  const paidNet = paidIn - paidOut
  const remaining = Math.max(0, total - paidNet)

  const handover = (c.inspections || []).find((i) => i.type === 'handover')
  const ret = (c.inspections || []).find((i) => i.type === 'return')
  const outAt = handover?.inspected_at || c.starts_at
  const inAt = ret?.inspected_at || c.actual_ends_at || c.planned_ends_at
  const odoOut = handover?.odometer ?? c.odometer_out
  const odoIn = ret?.odometer ?? c.odometer_in

  const customer = c.customer
  const vehicle = c.vehicle
  const vehicleName = vehicle ? `${vehicle.brand || ''} ${vehicle.model || ''}`.trim() : ''
  const logo = tenant.logo_src || '/brand/rpm-logo.webp'
  const brand = tenant.center_name || 'RPM Rent A Car'
  const otherLabel = (c.other_fees_description || '').trim() || (other > 0 ? 'رسوم أخرى' : '')
  const deliveryNotes = (c.delivery_service_notes || '').trim()
  const invNo = invoiceNumber(c.number)
  const invDate = formatDate(c.starts_at)

  const deliveryRow = (delivery > 0 || c.has_delivery_service)
    ? `<tr>
        <td class="desc">خدمة تسليم واستلام السيارة${deliveryNotes ? ` · ${esc(deliveryNotes)}` : ''}</td>
        <td class="num">1</td>
        <td class="num">—</td>
        <td class="num">${moneyOrBlank(delivery)}</td>
      </tr>`
    : `<tr>
        <td class="desc">خدمة تسليم واستلام السيارة</td>
        <td class="num"></td>
        <td class="num"></td>
        <td class="num"></td>
      </tr>`

  const otherRow = (other > 0 || damage > 0)
    ? `<tr>
        <td class="desc">رسوم أخرى / خدمات إضافية${damage > 0 ? ' · أضرار' : ''}${otherLabel ? ` · ${esc(otherLabel)}` : ''}</td>
        <td class="num">${other > 0 || damage > 0 ? '1' : ''}</td>
        <td class="num">—</td>
        <td class="num">${moneyOrBlank(other + damage)}</td>
      </tr>`
    : `<tr>
        <td class="desc">رسوم أخرى / خدمات إضافية</td>
        <td class="num"></td>
        <td class="num"></td>
        <td class="num"></td>
      </tr>`

  return `
<article class="rpm-invoice-v1">
  <header class="inv-head">
    <div class="inv-brand">
      <img src="${esc(logo)}" alt="${esc(brand)}" />
      <div class="inv-brand-text">
        <strong>${esc(brand)}</strong>
        <span>RENT A CAR</span>
      </div>
    </div>
    <div class="inv-title-block">
      <h1>INVOICE / فاتورة</h1>
      <div class="inv-meta">
        <div><span class="lbl">Invoice # / رقم الفاتورة:</span> <span class="val" dir="ltr">${esc(invNo)}</span></div>
        <div><span class="lbl">Date / التاريخ:</span> <span class="val" dir="ltr">${esc(invDate)}</span></div>
        <div><span class="lbl">Agreement # / رقم العقد:</span> <span class="val" dir="ltr">${esc(c.number)}</span></div>
      </div>
    </div>
  </header>

  <section class="inv-section">
    <div class="inv-section-title">بيانات المستأجر / Tenant Details</div>
    <table class="inv-grid">
      <tr>
        <th>اسم المستأجر:</th><td>${esc(customer?.full_name || '')}</td>
        <th>سجل تجاري:</th><td dir="ltr">${esc(tenant.print_commercial_register || '')}</td>
      </tr>
      <tr>
        <th>رقم الهاتف:</th><td dir="ltr">${esc(customer?.phone || '')}</td>
        <th>الجنسية:</th><td>${esc(nationalityOf(customer))}</td>
      </tr>
      <tr>
        <th>بطاقة ضريبية / قومي:</th><td dir="ltr">${esc(customer?.national_id || customer?.passport_number || tenant.print_tax_info || '')}</td>
        <th>تاريخ انتهائها:</th><td dir="ltr">${esc(dash(customer?.national_id_expires_on || customer?.license_expires_on || ''))}</td>
      </tr>
      <tr>
        <th>العنوان:</th><td colspan="3">${esc(customer?.address || '')}</td>
      </tr>
    </table>
  </section>

  <section class="inv-section">
    <div class="inv-section-title">بيانات السيارة / Vehicle Details</div>
    <table class="inv-grid">
      <tr>
        <th>نوع السيارة / الموديل:</th><td>${esc(vehicleName)}</td>
        <th>سنة الصنع:</th><td dir="ltr">${esc(dash(vehicle?.year ?? ''))}</td>
      </tr>
      <tr>
        <th>رقم اللوحة:</th><td>${esc(vehicle?.plate || '')}</td>
        <th>اللون:</th><td>${esc(vehicle?.color || '')}</td>
      </tr>
      <tr>
        <th>تاريخ الخروج:</th><td dir="ltr">${esc(whenLabel(outAt))}</td>
        <th>عداد الخروج (كم):</th><td dir="ltr">${odoOut != null ? esc(formatNumber(Number(odoOut))) : ''}</td>
      </tr>
      <tr>
        <th>تاريخ الدخول:</th><td dir="ltr">${esc(whenLabel(inAt))}</td>
        <th>عداد الدخول (كم):</th><td dir="ltr">${odoIn != null ? esc(formatNumber(Number(odoIn))) : ''}</td>
      </tr>
    </table>
  </section>

  <section class="inv-section inv-finance">
    <div class="inv-section-title">تفاصيل الحساب المالية / Financial Summary</div>
    <div class="inv-watermark" aria-hidden="true">${esc(brand)}</div>
    <table class="inv-finance-table">
      <thead>
        <tr>
          <th>البيان / Description</th>
          <th>الكمية / الأيام</th>
          <th>السعر اليومي</th>
          <th>الإجمالي</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="desc">إيجار سيارة (Rental Days)</td>
          <td class="num">${days || ''}</td>
          <td class="num">${daily ? moneyOrBlank(daily) : ''}</td>
          <td class="num">${moneyOrBlank(rent)}</td>
        </tr>
        <tr>
          <td class="desc">تأمين مؤقت (Insurance Deposit)</td>
          <td class="num">${deposit ? '1' : ''}</td>
          <td class="num">—</td>
          <td class="num">${deposit ? moneyOrBlank(deposit) : ''}</td>
        </tr>
        <tr>
          <td class="desc">كم زيادة مدفوعة مقدماً (Prepaid Extra KM)</td>
          <td class="num">${prepaidQty || ''}</td>
          <td class="num">—</td>
          <td class="num">${prepaidAmt ? moneyOrBlank(prepaidAmt) : ''}</td>
        </tr>
        <tr>
          <td class="desc">زيادة إضافية عند الاستلام (Extra KM beyond prepaid)</td>
          <td class="num">${excessQty || ''}</td>
          <td class="num">—</td>
          <td class="num">${excessAmt ? moneyOrBlank(excessAmt) : ''}</td>
        </tr>
        ${deliveryRow}
        ${otherRow}
        <tr class="totals">
          <td colspan="3">المبلغ الإجمالي / Total Amount</td>
          <td class="num"><strong>${moneyOrBlank(total)}</strong></td>
        </tr>
        <tr class="totals">
          <td colspan="3">المبلغ المدفوع / Amount Paid</td>
          <td class="num">${paidNet ? moneyOrBlank(paidNet) : ''}</td>
        </tr>
        <tr class="totals">
          <td colspan="3">المتبقي / Remaining Balance</td>
          <td class="num"><strong>${moneyOrBlank(remaining)}</strong></td>
        </tr>
      </tbody>
    </table>
    <p class="inv-note">ملاحظة: التأمين المؤقت خارج إجمالي الإيجار ويُسوَّى عند الاستلام.</p>
  </section>

  <section class="inv-terms">
    <h3>الشروط والأحكام العامة:</h3>
    <ol>
      <li>يتعهد المستأجر بإعادة السيارة بنفس الحالة التي استلمها عليها وفي الوقت المحدد بالعقد.</li>
      <li>المستأجر مسؤول عن كافة المخالفات المرورية التي تسجل على السيارة خلال فترة الإيجار.</li>
      <li>يمنع استخدام السيارة في غير الأغراض المخصصة لها أو قيادتها من غير الأشخاص المصرح لهم.</li>
    </ol>
  </section>

  <footer class="inv-sign">
    <div>
      <strong>توقيع المستأجر / Tenant Signature</strong>
      <div class="sign-line"></div>
    </div>
    <div>
      <strong>توقيع المستلم / المعرض / Authorized Signature</strong>
      <div class="sign-line"></div>
    </div>
  </footer>

  <div class="inv-foot">
    <span>Page 1 of 1</span>
    <span>${esc(brand)} - Official Receipt &amp; Agreement Summary</span>
  </div>
  ${tenant.print_footer ? `<p class="inv-print-footer">${esc(tenant.print_footer)}</p>` : ''}
</article>`
}

export function shellInvoiceHtml(body: string, title = 'فاتورة'): string {
  const trimmed = body.trim()
  if (/^<!doctype/i.test(trimmed) || /<html[\s>]/i.test(trimmed)) return trimmed
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(title)}</title>
<style>
  @page { size: A4; margin: 10mm 9mm; }
  * { box-sizing: border-box; }
  html{ margin:0; }
  body{
    margin:0; padding:0; background:#fff; color:#111;
    font-family: Tahoma,'Segoe UI',Arial,sans-serif;
    font-size: 11px; line-height: 1.45;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  @media screen{
    html, body{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
    body{ --rpm-preview-scale:1; }
    .rpm-preview-root{
      transform: scale(var(--rpm-preview-scale));
      transform-origin: top center;
      width: 100%;
    }
  }
  .rpm-invoice-v1{ --inv-navy:#0b1f3a; --inv-red:#c40000; width:100%; max-width:192mm; margin:0 auto; }
  @media print{
    .rpm-preview-root{ transform:none !important; width:100% !important; }
    .rpm-invoice-v1{ max-width:100%; width:100%; margin:0; }
  }
  .inv-head{
    display:flex; justify-content:space-between; align-items:flex-start; gap:12px;
    margin-bottom:10px; border-bottom:2px solid var(--inv-navy); padding-bottom:8px;
    direction: ltr; /* logo left · title right — matches paper invoice */
  }
  .inv-brand{ display:flex; align-items:center; gap:10px; }
  .inv-title-block, .inv-meta, .inv-meta .lbl { direction: rtl; text-align: right; }
  .inv-brand img{ width:64px; height:auto; object-fit:contain; }
  .inv-brand-text{ display:flex; flex-direction:column; line-height:1.15; }
  .inv-brand-text strong{ font-size:15px; color:var(--inv-navy); }
  .inv-brand-text span{ font-size:11px; color:var(--inv-red); font-weight:800; letter-spacing:.06em; border-bottom:2px solid var(--inv-red); padding-bottom:1px; width:max-content; }
  .inv-title-block{ text-align:start; min-width:42%; }
  .inv-title-block h1{ margin:0 0 6px; font-size:22px; color:#111; letter-spacing:.02em; }
  .inv-meta{ font-size:11px; }
  .inv-meta > div{ margin:2px 0; display:flex; gap:6px; flex-wrap:wrap; }
  .inv-meta .lbl{ color:#333; font-weight:700; }
  .inv-meta .val{ border-bottom:1px dotted #333; min-width:7em; padding:0 4px; font-weight:800; }
  .inv-section{ margin:10px 0; }
  .inv-section-title{
    background: var(--inv-navy); color:#fff; font-weight:800; font-size:12px;
    padding:5px 10px; text-align:center;
  }
  .inv-grid{ width:100%; border-collapse:collapse; table-layout:fixed; }
  .inv-grid th, .inv-grid td{
    border:1px solid #222; padding:5px 7px; vertical-align:top; font-size:11px;
  }
  .inv-grid th{ width:18%; background:#f3f5f8; font-weight:800; white-space:nowrap; }
  .inv-finance{ position:relative; }
  .inv-watermark{
    position:absolute; inset:28% 10% 18%;
    display:flex; align-items:center; justify-content:center;
    font-size:42px; font-weight:900; color:rgba(11,31,58,.06);
    pointer-events:none; transform:rotate(-18deg); letter-spacing:.04em;
  }
  .inv-finance-table{ width:100%; border-collapse:collapse; position:relative; z-index:1; }
  .inv-finance-table th, .inv-finance-table td{
    border:1px solid #222; padding:6px 7px; font-size:11px;
  }
  .inv-finance-table thead th{ background:var(--inv-navy); color:#fff; font-weight:800; }
  .inv-finance-table .desc{ text-align:start; }
  .inv-finance-table .num{ text-align:center; font-variant-numeric:tabular-nums; width:18%; }
  .inv-finance-table tr.totals td{ background:#f7f8fa; font-weight:700; }
  .inv-note{ margin:6px 0 0; font-size:10px; color:#555; }
  .inv-terms{
    margin-top:12px; background:#f2f2f2; border:1px solid #cfcfcf; border-radius:4px; padding:8px 12px;
  }
  .inv-terms h3{ margin:0 0 6px; font-size:12px; }
  .inv-terms ol{ margin:0; padding-inline-start:1.3rem; }
  .inv-terms li{ margin:3px 0; }
  .inv-sign{
    display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:22px;
  }
  .inv-sign strong{ display:block; margin-bottom:28px; font-size:11px; }
  .sign-line{ border-bottom:1px solid #333; height:1px; }
  .inv-foot{
    display:flex; justify-content:space-between; gap:12px; margin-top:16px;
    font-size:10px; color:#444; border-top:1px solid #ddd; padding-top:6px;
  }
  .inv-print-footer{ margin-top:6px; font-size:10px; color:#666; text-align:center; }
  @media print{ body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style></head><body><div class="rpm-preview-root">${body}</div></body></html>`
}

export function isInvoiceBody(html: string): boolean {
  return html.includes('rpm-invoice-v1') || html.includes('INVOICE / فاتورة')
}
