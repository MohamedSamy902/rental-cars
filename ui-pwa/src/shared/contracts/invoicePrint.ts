import { formatDate, formatDateTime, formatDepositAmount, formatMoney, formatNumber } from '@/shared/lib/format'

type InvoiceCustomer = {
  full_name?: string
  phone?: string
  address?: string | null
  nationality?: string | null
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
  frozen_deposit_currency?: string | null
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
  /** مخالفات العقد — تُحدَّث مع كل طباعة/مشاركة */
  violations?: Array<{
    id?: number
    amount: number
    occurred_at?: string | null
    status?: string | null
    location?: string | null
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
  if (customer?.nationality && String(customer.nationality).trim()) {
    return String(customer.nationality).trim()
  }
  // Legacy rows before nationality column: Egyptian national ID implied مصري.
  if (customer?.national_id) return 'مصري'
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
  const violations = c.violations || []
  const isViolationBillable = (status?: string | null) => {
    const s = String(status || '').toLowerCase()
    return s !== 'confirmed' && s !== 'paid'
  }
  const violationsBillable = violations
    .filter((v) => isViolationBillable(v.status))
    .reduce((s, v) => s + Number(v.amount || 0), 0)
  const contractCharges = c.final_total != null
    ? Number(c.final_total)
    : Math.max(0, rent + prepaidAmt + excessAmt + other + damage + delivery - discount)
  const total = contractCharges + violationsBillable
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

  const violationRows = violations.map((v, idx) => {
    const billable = isViolationBillable(v.status)
    const dateLabel = v.occurred_at ? formatDate(v.occurred_at) : '—'
    const loc = (v.location || '').trim()
    const desc = `مخالفة مرورية #${idx + 1} · ${dateLabel}${billable ? '' : ' · مسدد'}${loc ? ` · ${loc}` : ''}`
    const amountCell = billable
      ? moneyOrBlank(Number(v.amount || 0))
      : `— (${moneyOrBlank(Number(v.amount || 0))} مسدد)`
    return `<tr>
        <td class="desc">${esc(desc)}</td>
        <td class="num">1</td>
        <td class="num">—</td>
        <td class="num">${amountCell}</td>
      </tr>`
  }).join('')

  const PLATFORM_AUTHOR = 'محمد سامي'
  const PLATFORM_WA = 'https://wa.me/201150100104'

  return `
<article class="rpm-invoice-v3">
  <header class="inv-head">
    <div class="inv-brand">
      <img src="${esc(logo)}" alt="${esc(brand)}" />
      <div class="inv-brand-text">
        <strong>${esc(brand)}</strong>
        <span>تأجير سيارات</span>
      </div>
    </div>
    <div class="inv-title-block">
      <p class="inv-kicker">ملخص اتفاق الإيجار</p>
      <h1>فاتورة</h1>
      <div class="inv-meta">
        <div><span class="lbl">رقم الفاتورة</span><span class="val" dir="ltr">${esc(invNo)}</span></div>
        <div><span class="lbl">التاريخ</span><span class="val" dir="ltr">${esc(invDate)}</span></div>
        <div><span class="lbl">رقم العقد</span><span class="val" dir="ltr">${esc(c.number)}</span></div>
      </div>
    </div>
  </header>

  <section class="inv-section">
    <h2 class="inv-section-title">بيانات المستأجر</h2>
    <div class="inv-cards">
      <div class="inv-card"><span class="k">الاسم</span><span class="v">${esc(customer?.full_name || '—')}</span></div>
      <div class="inv-card"><span class="k">الهاتف</span><span class="v" dir="ltr">${esc(customer?.phone || '—')}</span></div>
      <div class="inv-card"><span class="k">قومي / جواز</span><span class="v" dir="ltr">${esc(customer?.national_id || customer?.passport_number || tenant.print_tax_info || '—')}</span></div>
      <div class="inv-card"><span class="k">الجنسية</span><span class="v">${esc(nationalityOf(customer) || '—')}</span></div>
      <div class="inv-card"><span class="k">انتهاء الهوية / الرخصة</span><span class="v" dir="ltr">${esc(dash(customer?.national_id_expires_on || customer?.license_expires_on || '') || '—')}</span></div>
      <div class="inv-card"><span class="k">سجل تجاري</span><span class="v" dir="ltr">${esc(tenant.print_commercial_register || '—')}</span></div>
      <div class="inv-card wide"><span class="k">العنوان</span><span class="v">${esc(customer?.address || '—')}</span></div>
    </div>
  </section>

  <section class="inv-section">
    <h2 class="inv-section-title">بيانات السيارة</h2>
    <div class="inv-cards">
      <div class="inv-card"><span class="k">النوع / الموديل</span><span class="v">${esc(vehicleName || '—')}</span></div>
      <div class="inv-card"><span class="k">سنة الصنع</span><span class="v" dir="ltr">${esc(dash(vehicle?.year ?? '') || '—')}</span></div>
      <div class="inv-card"><span class="k">اللوحة</span><span class="v">${esc(vehicle?.plate || '—')}</span></div>
      <div class="inv-card"><span class="k">اللون</span><span class="v">${esc(vehicle?.color || '—')}</span></div>
      <div class="inv-card"><span class="k">تاريخ الخروج</span><span class="v" dir="ltr">${esc(whenLabel(outAt) || '—')}</span></div>
      <div class="inv-card"><span class="k">عداد الخروج</span><span class="v" dir="ltr">${odoOut != null ? esc(formatNumber(Number(odoOut))) + ' كم' : '—'}</span></div>
      <div class="inv-card"><span class="k">تاريخ الدخول</span><span class="v" dir="ltr">${esc(whenLabel(inAt) || '—')}</span></div>
      <div class="inv-card"><span class="k">عداد الدخول</span><span class="v" dir="ltr">${odoIn != null ? esc(formatNumber(Number(odoIn))) + ' كم' : '—'}</span></div>
    </div>
  </section>

  <section class="inv-section inv-finance">
    <h2 class="inv-section-title">تفاصيل الحساب</h2>
    <div class="inv-finance-wrap">
      <table class="inv-finance-table">
        <thead>
          <tr>
            <th>البيان</th>
            <th>الكمية / الأيام</th>
            <th>السعر</th>
            <th>الإجمالي</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="desc">إيجار السيارة</td>
            <td class="num">${days || '—'}</td>
            <td class="num">${daily ? moneyOrBlank(daily) : '—'}</td>
            <td class="num">${moneyOrBlank(rent)}</td>
          </tr>
          <tr>
            <td class="desc">تأمين مؤقت</td>
            <td class="num">${deposit ? '1' : '—'}</td>
            <td class="num">—</td>
            <td class="num">${deposit ? formatDepositAmount(deposit, c.frozen_deposit_currency) : '—'}</td>
          </tr>
          <tr>
            <td class="desc">كم زيادة مدفوعة مقدماً</td>
            <td class="num">${prepaidQty || '—'}</td>
            <td class="num">—</td>
            <td class="num">${prepaidAmt ? moneyOrBlank(prepaidAmt) : '—'}</td>
          </tr>
          <tr>
            <td class="desc">زيادة كم عند الاستلام</td>
            <td class="num">${excessQty || '—'}</td>
            <td class="num">—</td>
            <td class="num">${excessAmt ? moneyOrBlank(excessAmt) : '—'}</td>
          </tr>
          ${deliveryRow}
          ${otherRow}
          ${violationRows}
          <tr class="totals">
            <td colspan="3">المبلغ الإجمالي</td>
            <td class="num"><strong>${moneyOrBlank(total)}</strong></td>
          </tr>
          <tr class="totals">
            <td colspan="3">المبلغ المدفوع</td>
            <td class="num">${paidNet ? moneyOrBlank(paidNet) : '—'}</td>
          </tr>
          <tr class="totals balance">
            <td colspan="3">المتبقي</td>
            <td class="num"><strong>${moneyOrBlank(remaining)}</strong></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="inv-note">التأمين المؤقت خارج إجمالي الإيجار ويُسوَّى عند الاستلام.</p>
    ${violations.length > 0
      ? '<p class="inv-note">المخالفات الظاهرة تُحدَّث تلقائياً عند تسجيل مخالفة جديدة على العقد. المسدَّد منها لا يُضاف للمتبقي.</p>'
      : ''}
  </section>

  <section class="inv-terms">
    <h3>الشروط والأحكام</h3>
    <ol>
      <li>يتعهد المستأجر بإعادة السيارة بنفس الحالة التي استلمها عليها وفي الوقت المحدد بالعقد.</li>
      <li>المستأجر مسؤول عن كافة المخالفات المرورية التي تُسجَّل على السيارة خلال فترة الإيجار.</li>
      <li>يمنع استخدام السيارة في غير الأغراض المخصصة لها أو قيادتها من غير الأشخاص المصرّح لهم.</li>
    </ol>
  </section>

  <footer class="inv-sign">
    <div class="box">
      <strong>توقيع المستأجر</strong>
      <div class="sign-line"></div>
    </div>
    <div class="box">
      <strong>توقيع المعرض / المفوض</strong>
      <div class="sign-line"></div>
    </div>
  </footer>

  <div class="inv-foot">
    <span>${esc(brand)}</span>
    <span>صفحة ١ من ١</span>
  </div>
  ${tenant.print_footer ? `<p class="inv-print-footer">${esc(tenant.print_footer)}</p>` : ''}
  <aside class="inv-credit">
    تم الإنشاء بواسطة
    <a href="${PLATFORM_WA}" target="_blank" rel="noopener noreferrer">${PLATFORM_AUTHOR}</a>
  </aside>
</article>`
}

export function shellInvoiceHtml(body: string, title = 'فاتورة'): string {
  const trimmed = body.trim()
  if (/^<!doctype/i.test(trimmed) || /<html[\s>]/i.test(trimmed)) return trimmed
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(title)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet" />
<style>
  @page { size: A4; margin: 11mm 10mm; }
  * { box-sizing: border-box; }
  html{ margin:0; }
  body{
    margin:0; padding:0; background:#fff; color:#0f172a;
    font-family:"IBM Plex Sans Arabic",Tahoma,"Segoe UI",Arial,sans-serif;
    font-size:12px; line-height:1.5;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  @media screen{
    html, body{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
    body{ --rpm-preview-scale:1; background:#eef2f7; padding:16px 10px; }
    .rpm-preview-root{
      transform: scale(var(--rpm-preview-scale));
      transform-origin: top center;
      width: 100%;
      max-width: 210mm;
      margin: 0 auto;
      background:#fff;
      border-radius:18px;
      box-shadow:0 18px 48px rgba(15,23,42,.08);
      padding:18px 16px 14px;
    }
  }
  .rpm-invoice-v3{
    --inv-ink:#0f172a; --inv-muted:#64748b; --inv-line:#e2e8f0;
    --inv-navy:#0b1f3a; --inv-accent:#b91c1c; --inv-soft:#f8fafc;
    width:100%; max-width:192mm; margin:0 auto;
  }
  @media print{
    body{ background:#fff; padding:0; }
    .rpm-preview-root{ transform:none !important; width:100% !important; max-width:none; margin:0; box-shadow:none; border-radius:0; padding:0; }
    .rpm-invoice-v3{ max-width:100%; width:100%; margin:0; }
  }
  .inv-head{
    display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
    margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid var(--inv-line);
  }
  .inv-brand{ display:flex; align-items:center; gap:12px; min-width:0; }
  .inv-brand img{
    width:58px; height:58px; object-fit:contain; border-radius:14px;
    border:1px solid var(--inv-line); background:#fff; padding:5px;
  }
  .inv-brand-text{ display:flex; flex-direction:column; gap:4px; }
  .inv-brand-text strong{ font-size:15px; font-weight:700; color:var(--inv-navy); }
  .inv-brand-text span{
    align-self:flex-start; font-size:10px; font-weight:700; letter-spacing:.04em;
    color:var(--inv-accent); background:#fef2f2; border-radius:999px; padding:2px 9px;
  }
  .inv-title-block{ text-align:start; min-width:42%; }
  .inv-kicker{ margin:0 0 2px; font-size:11px; color:var(--inv-muted); font-weight:500; }
  .inv-title-block h1{ margin:0 0 8px; font-size:26px; font-weight:700; letter-spacing:-.02em; color:var(--inv-ink); }
  .inv-meta{ display:grid; gap:5px; font-size:11px; }
  .inv-meta > div{ display:flex; flex-wrap:wrap; gap:6px 10px; align-items:baseline; }
  .inv-meta .lbl{ color:var(--inv-muted); font-weight:500; }
  .inv-meta .val{
    font-weight:700; font-variant-numeric:tabular-nums;
    background:var(--inv-soft); border:1px solid var(--inv-line);
    border-radius:999px; padding:1px 9px;
  }
  .inv-section{ margin:14px 0; }
  .inv-section-title{
    display:flex; align-items:center; gap:8px;
    margin:0 0 10px; font-size:13px; font-weight:700; color:var(--inv-navy);
  }
  .inv-section-title::before{
    content:""; width:7px; height:7px; border-radius:999px; background:var(--inv-accent);
    box-shadow:0 0 0 3px #fef2f2;
  }
  .inv-cards{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .inv-card{
    background:var(--inv-soft); border:1px solid var(--inv-line);
    border-radius:12px; padding:9px 11px;
  }
  .inv-card.wide{ grid-column:1 / -1; }
  .inv-card .k{ display:block; font-size:10px; color:var(--inv-muted); margin-bottom:2px; font-weight:500; }
  .inv-card .v{ display:block; font-weight:600; font-size:12px; word-break:break-word; font-variant-numeric:tabular-nums; }
  .inv-finance-wrap{
    border:1px solid var(--inv-line); border-radius:14px; overflow:hidden; background:#fff;
  }
  .inv-finance-table{ width:100%; border-collapse:collapse; }
  .inv-finance-table thead th{
    background:linear-gradient(180deg,#0b1f3a 0%,#123056 100%);
    color:#fff; font-weight:600; font-size:11px; padding:9px 10px; text-align:start;
  }
  .inv-finance-table td{
    padding:9px 10px; border-top:1px solid var(--inv-line); font-size:11.5px; vertical-align:middle;
  }
  .inv-finance-table tbody tr:nth-child(even) td{ background:#fbfdff; }
  .inv-finance-table .desc{ text-align:start; }
  .inv-finance-table .num{ text-align:center; font-variant-numeric:tabular-nums; font-weight:600; width:18%; }
  .inv-finance-table tr.totals td{ background:#f1f5f9; font-weight:700; }
  .inv-finance-table tr.balance td{ background:#fef2f2; }
  .inv-note{ margin:8px 2px 0; font-size:10.5px; color:var(--inv-muted); }
  .inv-terms{
    margin-top:14px; background:var(--inv-soft); border:1px solid var(--inv-line);
    border-radius:12px; padding:11px 13px;
  }
  .inv-terms h3{ margin:0 0 6px; font-size:12.5px; color:var(--inv-navy); }
  .inv-terms ol{ margin:0; padding-inline-start:1.2rem; color:#334155; }
  .inv-terms li{ margin:4px 0; }
  .inv-sign{ display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:18px; }
  .inv-sign .box{
    border:1px dashed #cbd5e1; border-radius:12px; padding:12px; min-height:78px;
  }
  .inv-sign strong{ display:block; margin-bottom:28px; font-size:11px; color:var(--inv-muted); font-weight:600; }
  .sign-line{ border-bottom:1px solid #94a3b8; height:1px; margin-top:auto; }
  .inv-foot{
    display:flex; justify-content:space-between; gap:12px; margin-top:16px;
    font-size:10.5px; color:var(--inv-muted); border-top:1px solid var(--inv-line); padding-top:8px;
  }
  .inv-print-footer{ margin-top:6px; font-size:10px; color:var(--inv-muted); text-align:center; }
  .inv-credit{
    margin-top:14px; padding-top:10px; border-top:1px solid var(--inv-line);
    text-align:center; font-size:10.5px; color:var(--inv-muted); letter-spacing:.01em;
  }
  .inv-credit a{
    color:var(--inv-navy); font-weight:700; text-decoration:none;
    border-bottom:1px solid rgba(11,31,58,.25);
  }
  @media (max-width:640px){
    .inv-cards{ grid-template-columns:1fr; }
    .inv-sign{ grid-template-columns:1fr; }
  }
  @media print{ body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style></head><body><div class="rpm-preview-root">${body}</div></body></html>`
}

export function isInvoiceBody(html: string): boolean {
  return html.includes('rpm-invoice-v3')
    || html.includes('rpm-invoice-v1')
    || html.includes('INVOICE / فاتورة')
    || html.includes('rpm-invoice-v2')
}
