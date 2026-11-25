import { api, type ApiError } from '@/shared/api/client'
import { openWhatsAppText } from '@/shared/lib/whatsappShare'

/** تجهيز فاتورة موقّعة وفتح واتساب للعميل (مثل مشاركة المخالفات). */
export async function shareContractInvoiceWhatsApp(opts: {
  contractId: number
  phone?: string | null
}): Promise<{ ok: true } | { ok: false; error: string }> {
  const phone = (opts.phone || '').trim()
  if (!phone) {
    return { ok: false, error: 'لا يوجد رقم واتساب للعميل على هذا العقد.' }
  }
  try {
    const res = await api<{
      data: { message_text: string; customer_phone?: string | null; share_url: string }
    }>(`/contracts/${opts.contractId}/invoice/share`, { method: 'POST' })
    openWhatsAppText(res.data.message_text, phone || res.data.customer_phone)
    return { ok: true }
  } catch (e) {
    return { ok: false, error: (e as ApiError).message || 'تعذّر تجهيز فاتورة واتساب.' }
  }
}
