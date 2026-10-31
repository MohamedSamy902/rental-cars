import { api, type ApiError } from '@/shared/api/client'
import { formatDate, formatMoney } from '@/shared/lib/format'
import { statusLabel } from './contractModel'

export async function printContract(id: number, onError: (message: string) => void) {
  try {
    const res = await api<{ data: { tenant: { center_name?: string; print_footer?: string }; contract: Record<string, unknown> & {
      number: string
      status: string
      customer?: { full_name?: string }
      vehicle?: { brand: string; model: string; plate: string }
      starts_at: string
      planned_ends_at: string
      frozen_daily_rate: number
      frozen_agreed_deposit?: number
      final_total?: number | null
    } } }>(`/contracts/${id}/print`)
    const { tenant, contract } = res.data
    if (!contract?.number) {
      onError('تعذّر تحميل بيانات العقد للطباعة.')
      return
    }
    await api('/print-snapshots', {
      method: 'POST',
      json: { type: 'contract', record_type: 'contract', record_id: id, payload: { tenant, contract } },
    }).catch(() => undefined)
    const w = window.open('', '_blank', 'noopener,noreferrer,width=900,height=700')
    if (!w) {
      onError('اسمح بالنوافذ المنبثقة للطباعة.')
      return
    }
    const esc = (s: string) => s.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
    w.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>عقد ${esc(contract.number)}</title>
      <style>body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a} h1{margin:0 0 4px} .muted{color:#64748b} table{width:100%;border-collapse:collapse;margin-top:16px} td,th{border:1px solid #cbd5e1;padding:8px;text-align:start} .foot{margin-top:24px;color:#64748b;font-size:12px}</style>
      </head><body>
      <h1>${esc(tenant.center_name || 'عقد تأجير')}</h1>
      <p class="muted">عقد رقم ${esc(contract.number)} · ${esc(statusLabel[contract.status] || contract.status)}</p>
      <table>
        <tr><th>العميل</th><td>${esc(contract.customer?.full_name || '—')}</td></tr>
        <tr><th>المركبة</th><td>${contract.vehicle ? esc(`${contract.vehicle.brand} ${contract.vehicle.model} · ${contract.vehicle.plate}`) : '—'}</td></tr>
        <tr><th>البداية</th><td>${formatDate(contract.starts_at)}</td></tr>
        <tr><th>النهاية المخططة</th><td>${formatDate(contract.planned_ends_at)}</td></tr>
        <tr><th>السعر اليومي</th><td>${formatMoney(contract.frozen_daily_rate)}</td></tr>
        <tr><th>التأمين النقدي</th><td>${formatMoney(contract.frozen_agreed_deposit || 0)}</td></tr>
        <tr><th>الإجمالي النهائي</th><td>${contract.final_total != null ? formatMoney(contract.final_total) : '—'}</td></tr>
      </table>
      <p class="foot">${esc(tenant.print_footer || '')}</p>
      <script>window.onload=()=>window.print()</script>
      </body></html>`)
    w.document.close()
  } catch (e) {
    onError((e as ApiError).message || 'تعذّر طباعة العقد.')
  }
}
