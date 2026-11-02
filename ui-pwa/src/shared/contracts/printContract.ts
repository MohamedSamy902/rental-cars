import { api, type ApiError } from '@/shared/api/client'
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

type PrintPayload = {
  tenant: { center_name?: string; print_footer?: string }
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
      .actions{margin-top:16px}
      .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px}
    </style>
  </head><body>${body}</body></html>`
}

function contractBody(tenant: PrintPayload['tenant'], c: PrintContract) {
  return `
    <h1>${esc(tenant.center_name || 'عقد تأجير')}</h1>
    <p class="muted">عقد رقم ${esc(c.number)} · <span class="badge">${esc(statusLabel[c.status] || c.status)}</span></p>
    <table>
      <tr><th>العميل</th><td>${esc(c.customer?.full_name || '—')}${c.customer?.phone ? ` · <span dir="ltr">${esc(c.customer.phone)}</span>` : ''}</td></tr>
      <tr><th>العربية</th><td>${c.vehicle ? esc(`${c.vehicle.brand} ${c.vehicle.model} · ${c.vehicle.plate}`) : '—'}</td></tr>
      <tr><th>البداية</th><td>${formatDate(c.starts_at)}</td></tr>
      <tr><th>النهاية المخططة</th><td>${formatDate(c.planned_ends_at)}</td></tr>
      <tr><th>السعر اليومي</th><td>${formatMoney(c.frozen_daily_rate)}</td></tr>
      <tr><th>التأمين النقدي</th><td>${formatMoney(c.frozen_agreed_deposit || 0)}</td></tr>
      <tr><th>الإجمالي النهائي</th><td>${c.final_total != null ? formatMoney(c.final_total) : '—'}</td></tr>
    </table>
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

function invoiceBody(tenant: PrintPayload['tenant'], c: PrintContract) {
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
    <h1>${esc(tenant.center_name || 'كشف حساب')}</h1>
    <p class="muted">فاتورة / كشف حساب للعقد ${esc(c.number)} · ${esc(c.customer?.full_name || '—')}</p>
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

function receiptBody(tenant: PrintPayload['tenant'], c: PrintContract) {
  const handover = (c.inspections || []).find((i) => i.type === 'handover')
  const ret = (c.inspections || []).find((i) => i.type === 'return')
  const deposit = c.deposit
  return `
    <h1>${esc(tenant.center_name || 'سند قبض')}</h1>
    <p class="muted">سند قبض التأمين ومحضر التسليم/الاستلام · عقد ${esc(c.number)}</p>
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
      <tr><th>تاريخ التسليم</th><td>${handover?.inspected_at ? formatDate(handover.inspected_at) : '—'}</td></tr>
    </table>
    <h2>محضر الاستلام</h2>
    <table>
      <tr><th>عداد العودة</th><td>${ret?.odometer ?? c.odometer_in ?? '—'}</td></tr>
      <tr><th>مستوى البنزين</th><td>${ret?.fuel_level != null ? `${ret.fuel_level}%` : '—'}</td></tr>
      <tr><th>تاريخ الاستلام</th><td>${ret?.inspected_at ? formatDate(ret.inspected_at) : (c.actual_ends_at ? formatDate(c.actual_ends_at) : '—')}</td></tr>
    </table>
    <p class="foot">${esc(tenant.print_footer || '')}</p>`
}

async function loadPrintPayload(id: number): Promise<PrintPayload> {
  const res = await api<{ data: PrintPayload }>(`/contracts/${id}/print`)
  const { tenant, contract } = res.data
  if (!contract?.number) {
    throw Object.assign(new Error('تعذّر تحميل بيانات العقد للطباعة.'), { status: 422 })
  }
  return { tenant, contract }
}

function renderDoc(kind: PrintDocKind, payload: PrintPayload): string {
  const { tenant, contract } = payload
  const title = `${titles[kind]} ${contract.number}`
  if (kind === 'invoice') return shell(title, invoiceBody(tenant, contract))
  if (kind === 'receipt') return shell(title, receiptBody(tenant, contract))
  return shell(title, contractBody(tenant, contract))
}

/** Print one of the three core contract documents. */
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
