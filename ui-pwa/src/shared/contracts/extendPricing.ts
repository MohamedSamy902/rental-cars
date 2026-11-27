import { formatMoney } from '@/shared/lib/format'

const HALF_MONTH_UNIT_DAYS = 15
const MONTH_UNIT_DAYS = 30

export type RentBasis = 'daily' | 'half_monthly' | 'monthly'

function packRent(days: number, unitDays: number, unitRate: number): number {
  if (days < 1 || unitDays < 1 || unitRate < 1) return 0
  const full = Math.floor(days / unitDays)
  const extra = days % unitDays
  return full * unitRate + Math.round((extra * unitRate) / unitDays)
}

function basisFromDays(days: number): RentBasis {
  if (days <= 10) return 'daily'
  if (days <= 20) return 'half_monthly'
  return 'monthly'
}

function basisRank(basis: RentBasis): number {
  if (basis === 'monthly') return 2
  if (basis === 'half_monthly') return 1
  return 0
}

function normalizeBasis(raw?: string | null): RentBasis {
  if (raw === 'half_monthly' || raw === 'weekly') return 'half_monthly'
  if (raw === 'monthly') return 'monthly'
  return 'daily'
}

/** ن٫١٨: تثبيت الشريحة إلا لو المدة المضافة وحدها فترة كاملة أعلى. */
export function resolveBasisAfterExtension(
  previousBasis: string | null | undefined,
  previousDays: number,
  newDays: number,
): RentBasis {
  const current = previousBasis ? normalizeBasis(previousBasis) : basisFromDays(Math.max(1, previousDays))
  const added = Math.max(0, newDays - Math.max(0, previousDays))
  if (added < 1) return current
  const addedBasis = basisFromDays(added)
  return basisRank(current) >= basisRank(addedBasis) ? current : addedBasis
}

export function estimatePeriodRentWithBasis(
  days: number,
  basis: RentBasis,
  daily: number,
  halfMonthly?: number | null,
  monthly?: number | null,
): number {
  if (days < 1) return 0
  if (basis === 'half_monthly') {
    const unit = halfMonthly && halfMonthly > 0 ? halfMonthly : Math.round(daily * 15)
    return packRent(days, HALF_MONTH_UNIT_DAYS, unit)
  }
  if (basis === 'monthly') {
    const unit = monthly && monthly > 0 ? monthly : Math.round(daily * 22)
    return packRent(days, MONTH_UNIT_DAYS, unit)
  }
  return days * Math.max(0, daily)
}

export function countDaysBetween(startsAt: string, endsAt: string): number {
  const a = new Date(startsAt)
  const b = new Date(endsAt)
  if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime()) || b <= a) return 0
  return Math.max(1, Math.floor((b.getTime() - a.getTime()) / 86400000))
}

export function addDaysIso(iso: string, days: number): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime()) || days < 1) return ''
  d.setTime(d.getTime() + days * 86400000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function extendPriceHint(
  basis: RentBasis,
  extensionDays: number,
  unitRate: number,
  previousRent: number,
  extensionRent: number,
): string {
  const total = previousRent + extensionRent
  const tier =
    basis === 'daily' ? 'يومي'
      : basis === 'half_monthly' ? 'نص شهري'
        : 'شهري'
  return `إيجار التمديد فقط لـ ${extensionDays} يوم (${tier} · ${formatMoney(unitRate)}) — السابق ${formatMoney(previousRent)} يبقى · الإجمالي ${formatMoney(total)}`
}

export function unitRateForBasis(
  basis: RentBasis,
  daily: number,
  halfMonthly?: number | null,
  monthly?: number | null,
): number {
  if (basis === 'half_monthly') {
    return halfMonthly && halfMonthly > 0 ? halfMonthly : Math.round(daily * 15)
  }
  if (basis === 'monthly') {
    return monthly && monthly > 0 ? monthly : Math.round(daily * 22)
  }
  return Math.max(0, daily)
}
