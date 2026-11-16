import { civilDateOnly } from '@/shared/lib/dates'

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
 * Storage/API and `<input type="date">` stay YYYY-MM-DD via `toDateOnly`.
 */
export function formatDate(iso: string | null | undefined): string {
  const only = toDateOnly(iso)
  if (!only) return '—'
  const [y, m, d] = only.split('-')
  if (!y || !m || !d) return '—'
  return `${d}/${m}/${y}`
}

/** Append midnight for APIs that still expect datetime. */
export function toApiDateTime(dateOnly: string): string {
  const d = toDateOnly(dateOnly)
  return d ? `${d}T00:00:00` : ''
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
