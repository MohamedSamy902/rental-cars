import { formatDate, formatMoneyPlain } from '@/shared/lib/format'
import { normalizePhoneDigits } from '@/shared/ui/PhoneActions'
import { mediaFileUrl } from '@/shared/lib/mediaUrl'

export type VehicleShareInput = {
  brand: string
  model: string
  year: number | string
  category: string
  fuel_type: string
  transmission?: string | null
  daily_rate: number
  half_monthly_rate: number
  monthly_rate: number
}

/** نص مشاركة العربية على واتساب — بالشكل المعتمد تجارياً. */
export function vehicleWhatsAppText(
  v: VehicleShareInput,
  opts: { centerName: string; phone: string; minDays?: number },
): string {
  const minDays = Math.max(1, opts.minDays ?? 5)
  const center = (opts.centerName || 'شركة آر بي إم للتأجير').trim()
  const phone = (opts.phone || '').trim()
  const transmission = (v.transmission || '').trim()
  const categoryLine = transmission
    ? `الفئة: ${v.category} · ${v.fuel_type} · ${transmission}`
    : `الفئة: ${v.category} · ${v.fuel_type}`

  const lines = [
    `${center} — ${v.brand} ${v.model} ${v.year}`,
    categoryLine,
    `يومي ${formatMoneyPlain(v.daily_rate)} (اقل مده ${minDays}ايام)`,
    `· نص شهر ${formatMoneyPlain(v.half_monthly_rate)}`,
    `· شهري ${formatMoneyPlain(v.monthly_rate)}`,
  ]
  if (phone) lines.push(`للتواصل و الاستفسار: ${phone}`)
  return lines.join('\n')
}

export type ViolationShareItem = {
  amount: number
  occurred_at: string
  photo_paths?: string[] | null
  photo_path?: string | null
  vehicle?: { brand?: string; model?: string; plate?: string } | null
  contract?: { number?: string; code?: string } | null
}

function violationPhotoPaths(v: ViolationShareItem): string[] {
  if (v.photo_paths && v.photo_paths.length > 0) {
    return v.photo_paths.map((p) => String(p).trim()).filter(Boolean)
  }
  return v.photo_path ? [String(v.photo_path).trim()] : []
}

/**
 * نص مخالفات للعميل على واتساب.
 * المسار الحي يفضّل `POST /violations/share` (رابط بيان موقّع يفتح بلا لوجين).
 * هذه الدالة للاختبارات/الاحتياط عندما يتوفر `shareUrl` جاهز.
 */
export function violationsWhatsAppText(
  items: ViolationShareItem[],
  opts: {
    centerName?: string
    customerName?: string
    shareUrl?: string
    absolutePhotoUrls?: string[]
  } = {},
): string {
  const center = (opts.centerName || 'شركة آر بي إم للتأجير').trim()
  const customer = (opts.customerName || '').trim()
  const header = customer
    ? `${center} — بيان مخالفات للعميل ${customer}`
    : `${center} — بيان مخالفات`

  const lines: string[] = [header, '']
  if (opts.shareUrl?.trim()) {
    lines.push('افتح البيان والصور من الرابط:')
    lines.push(opts.shareUrl.trim())
    lines.push('')
  }

  let total = 0
  items.forEach((v, i) => {
    total += Number(v.amount) || 0
    const plate = v.vehicle?.plate ? ` · ${v.vehicle.plate}` : ''
    const car = v.vehicle?.brand
      ? `${v.vehicle.brand} ${v.vehicle.model || ''}${plate}`.trim()
      : (plate ? plate.replace(/^ · /, '') : '')
    const contractNo = (v.contract?.number || v.contract?.code || '').trim()
    lines.push(`${i + 1}) تاريخ ${formatDate(v.occurred_at)} · المبلغ ${formatMoneyPlain(v.amount)}`)
    if (car) lines.push(`   العربية: ${car}`)
    if (contractNo) lines.push(`   الحجز: ${contractNo}`)
  })
  lines.push('')
  lines.push(`الإجمالي: ${formatMoneyPlain(total)}`)
  lines.push(`عدد المخالفات: ${items.length}`)

  if (!opts.shareUrl?.trim()) {
    const urls = (opts.absolutePhotoUrls || []).filter(Boolean)
    if (urls.length > 0) {
      lines.push('')
      lines.push('صور المخالفات:')
      urls.forEach((u, i) => lines.push(`${i + 1}. ${u}`))
    }
  }

  return lines.join('\n')
}

export function absoluteMediaUrls(paths: string[], origin = typeof window !== 'undefined' ? window.location.origin : ''): string[] {
  const out: string[] = []
  for (const path of paths) {
    const apiPath = mediaFileUrl(path)
    if (!apiPath) continue
    // الروابط العامة تحتاج أصل التطبيق؛ المصادقة تمنع فتحها للعميل غالباً — تُعرض للاستعمال الداخلي/النسخ.
    out.push(apiPath.startsWith('http') ? apiPath : `${origin}${apiPath.startsWith('/') ? '' : '/'}${apiPath}`)
  }
  return out
}

export function collectViolationPhotoPaths(items: ViolationShareItem[]): string[] {
  const all: string[] = []
  for (const v of items) all.push(...violationPhotoPaths(v))
  return all
}

/** فتح واتساب بنص جاهز · إن وُجد رقم يُوجَّه لنفس العميل. */
export function openWhatsAppText(text: string, phone?: string | null): void {
  const digits = phone ? normalizePhoneDigits(phone) : ''
  const base = digits ? `https://wa.me/${digits}` : 'https://wa.me/'
  const url = `${base}?text=${encodeURIComponent(text)}`
  window.open(url, '_blank', 'noopener,noreferrer')
}
