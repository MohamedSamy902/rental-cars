import { api, type ApiError } from '@/shared/api/client'
import { openWhatsAppText } from '@/shared/lib/whatsappShare'

/** تجهيز مشاركة واتساب للعربية (نص تجاري + رابط صور/تفاصيل موقّع). */
export async function shareVehicleWhatsApp(vehicleId: number): Promise<void> {
  const res = await api<{
    data: {
      message_text: string
      share_url: string
      photos_count: number
      expires_in_days: number
    }
  }>(`/vehicles/${vehicleId}/share`, { method: 'POST' })
  openWhatsAppText(res.data.message_text)
}

export function shareVehicleWhatsAppError(e: unknown, fallback = 'تعذّر تجهيز مشاركة واتساب.'): string {
  return (e as ApiError).message || fallback
}
