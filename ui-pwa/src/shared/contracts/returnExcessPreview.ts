import { formatMoney, formatNumber } from '@/shared/lib/format'
import { countDaysBetween } from './extendPricing'

export type ReturnKmBasis = {
  prior_driven_km?: number | null
  segment_start_odometer?: number | null
}

export type ExcessPreviewInput = {
  odometerIn: number
  startsAt: string
  odometerOut?: number | null
  dailyKmAllowance?: number | null
  excessKmRate?: number | null
  prepaidExcessKm?: number | null
  minDays?: number | null
  /** عند وجود تبديل عربيات — من ملف العقد */
  kmBasis?: ReturnKmBasis | null
  /** وقت الاستلام الافتراضي = الآن */
  returnedAt?: Date | string | null
}

export type ExcessPreview = {
  ok: boolean
  days: number
  drivenKm: number
  allowedKm: number
  excessKm: number
  billableExtraKm: number
  fee: number
  rate: number
  prepaidKm: number
  segmentStart: number
}

/**
 * تقدير رسوم كيلو الزيادة عند الاستلام — مطابق لمعادلة الـ Service
 * (بدل يومي × أيام · زيادة بعد المسموح والمسبق).
 */
export function estimateReturnExcess(input: ExcessPreviewInput): ExcessPreview | null {
  const odoIn = Math.floor(Number(input.odometerIn))
  if (!Number.isFinite(odoIn) || odoIn < 0) return null

  const segmentStart = Math.max(
    0,
    Math.floor(Number(
      input.kmBasis?.segment_start_odometer
        ?? input.odometerOut
        ?? 0,
    )),
  )
  if (odoIn < segmentStart) {
    return {
      ok: false,
      days: 0,
      drivenKm: 0,
      allowedKm: 0,
      excessKm: 0,
      billableExtraKm: 0,
      fee: 0,
      rate: Math.max(0, Math.floor(Number(input.excessKmRate ?? 0))),
      prepaidKm: Math.max(0, Math.floor(Number(input.prepaidExcessKm ?? 0))),
      segmentStart,
    }
  }

  const returnedAt = input.returnedAt
    ? (typeof input.returnedAt === 'string' ? new Date(input.returnedAt) : input.returnedAt)
    : new Date()
  const daysRaw = countDaysBetween(input.startsAt, returnedAt.toISOString())
  const minDays = Math.max(0, Math.floor(Number(input.minDays ?? 0)))
  const days = Math.max(daysRaw, minDays, 1)

  const daily = Math.max(0, Math.floor(Number(input.dailyKmAllowance ?? 0)))
  const allowedKm = daily * days
  const prior = Math.max(0, Math.floor(Number(input.kmBasis?.prior_driven_km ?? 0)))
  const drivenKm = prior + Math.max(0, odoIn - segmentStart)
  const excessKm = Math.max(0, drivenKm - allowedKm)
  const prepaidKm = Math.max(0, Math.floor(Number(input.prepaidExcessKm ?? 0)))
  const billableExtraKm = Math.max(0, excessKm - prepaidKm)
  const rate = Math.max(0, Math.floor(Number(input.excessKmRate ?? 0)))
  const fee = billableExtraKm * rate

  return {
    ok: true,
    days,
    drivenKm,
    allowedKm,
    excessKm,
    billableExtraKm,
    fee,
    rate,
    prepaidKm,
    segmentStart,
  }
}

export function excessPreviewHint(p: ExcessPreview): string {
  if (!p.ok) return 'عداد العودة أقل من عداد الخروج الحالي.'
  if (p.billableExtraKm < 1) {
    if (p.excessKm > 0 && p.prepaidKm > 0) {
      return `زيادة ${formatNumber(p.excessKm)} كم ضمن الباقة المسبقة (${formatNumber(p.prepaidKm)} كم) — بلا رسوم إضافية.`
    }
    return `المقطوع ${formatNumber(p.drivenKm)} كم ضمن المسموح ${formatNumber(p.allowedKm)} كم (${formatNumber(p.days)} يوم).`
  }
  return [
    `زيادة ${formatNumber(p.excessKm)} كم`,
    p.prepaidKm > 0 ? `بعد خصم ${formatNumber(p.prepaidKm)} كم مسبق` : null,
    `المطلوب ${formatNumber(p.billableExtraKm)} كم × ${formatMoney(p.rate)} = ${formatMoney(p.fee)}`,
  ].filter(Boolean).join(' · ')
}
