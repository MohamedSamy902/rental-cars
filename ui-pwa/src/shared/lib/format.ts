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

export function formatNumber(value: number): string {
  return numberAr.format(value)
}

export function formatPercent(value: number): string {
  return `${pctAr.format(value)}%`
}

export function formatDateTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('ar-EG', {
    dateStyle: 'medium',
    timeStyle: 'short',
    ...latn,
  }).format(d)
}

/** Normalize any ISO/datetime string to YYYY-MM-DD (Western digits). */
export function toDateOnly(iso: string | null | undefined): string {
  if (!iso) return ''
  const raw = String(iso).trim()
  if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 10)
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return ''
  const y = d.getUTCFullYear()
  const m = String(d.getUTCMonth() + 1).padStart(2, '0')
  const day = String(d.getUTCDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/** Date only as YYYY-MM-DD (Western digits) — platform display standard. */
export function formatDate(iso: string | null | undefined): string {
  const only = toDateOnly(iso)
  return only || '—'
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
