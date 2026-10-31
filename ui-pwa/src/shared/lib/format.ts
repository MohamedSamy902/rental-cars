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

/** Date only as YYYY/MM/DD (Western digits) — contracts period display. */
export function formatDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}/${m}/${day}`
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
