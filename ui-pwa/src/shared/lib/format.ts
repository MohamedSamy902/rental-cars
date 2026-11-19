import { civilDateOnly, isoToDisplayDate, nowLocalDateTimeValue } from '@/shared/lib/dates'

/** Western digits (0-9) required — numberingSystem: latn */
const latn = { numberingSystem: 'latn' as const }

const egp = new Intl.NumberFormat('ar-EG', {
  style: 'currency',
  currency: 'EGP',
  maximumFractionDigits: 0,
  ...latn,
})

const numberAr = new Intl.NumberFormat('ar-EG', latn)

const pctAr = new Intl.NumberFormat('ar-EG', {
  maximumFractionDigits: 0,
  ...latn,
})

export function formatMoney(value: number): string {
  return egp.format(value)
}

/** Default label for agreed cash deposit currency (writable free text). */
export const DEFAULT_DEPOSIT_CURRENCY = 'جنيه مصري'

/** Format deposit amount with its written currency (not forced EGP). */
export function formatDepositAmount(value: number, currency?: string | null): string {
  const n = Number.isFinite(value) ? Math.round(value) : 0
  const cur = String(currency || '').trim() || DEFAULT_DEPOSIT_CURRENCY
  const num = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n)
  return `${num} ${cur}`
}

/** Plain money for WhatsApp/share (Western digits, no RTL isolates). */
export function formatMoneyPlain(value: number): string {
  const n = Number.isFinite(value) ? Math.round(value) : 0
  return `${new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n)} ج.م.`
}

export function formatNumber(value: number): string {
  return numberAr.format(value)
}

export function formatPercent(value: number): string {
  return `${pctAr.format(value)}%`
}

export function formatDateTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  const date = formatDate(iso)
  const time = new Intl.DateTimeFormat('ar-EG', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Africa/Cairo',
    ...latn,
  }).format(d)
  return date === '—' ? '—' : `${date} ${time}`
}

/** Normalize any ISO/datetime string to YYYY-MM-DD (Western digits, Africa/Cairo). */
export function toDateOnly(iso: string | null | undefined): string {
  return civilDateOnly(iso)
}

/**
 * Date display as DD/MM/YYYY (Western digits).
 * Storage/API stay YYYY-MM-DD via `toDateOnly`. UI inputs use DateField (same display).
 */
export function formatDate(iso: string | null | undefined): string {
  return isoToDisplayDate(iso) || '—'
}

/**
 * API datetime in Africa/Cairo wall clock.
 * Keeps an explicit time when present; date-only values get the current Cairo HH:mm
 * (never forced midnight — contract print shows الساعة from this instant).
 */
export function toApiDateTime(dateOrDateTime: string): string {
  const raw = String(dateOrDateTime || '').trim()
  if (!raw) return ''

  const withTime = raw.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/)
  if (withTime) {
    return `${withTime[1]}T${withTime[2]}:${withTime[3]}:00`
  }

  const d = toDateOnly(raw)
  if (!d) return ''
  const now = nowLocalDateTimeValue()
  const hm = now.slice(11, 16) || '12:00'
  return `${d}T${hm}:00`
}

export function formatTime(iso: string): string {
  return new Intl.DateTimeFormat('ar-EG', {
    hour: '2-digit',
    minute: '2-digit',
    ...latn,
  }).format(new Date(iso))
}

export function formatDayShort(iso: string): string {
  return new Intl.DateTimeFormat('ar-EG', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    ...latn,
  }).format(new Date(iso))
}
