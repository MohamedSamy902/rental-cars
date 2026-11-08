/**
 * Civil-date helpers for Africa/Cairo business day (RPM ن٫١).
 * Never use `toISOString().slice(0, 10)` for form defaults — that is UTC.
 */

export const BUSINESS_TIME_ZONE = 'Africa/Cairo'

const civilPartsFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: BUSINESS_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  numberingSystem: 'latn',
})

const localDateTimePartsFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: BUSINESS_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
  numberingSystem: 'latn',
})

function partsMap(fmt: Intl.DateTimeFormat, date: Date): Record<string, string> {
  const out: Record<string, string> = {}
  for (const p of fmt.formatToParts(date)) {
    if (p.type !== 'literal') out[p.type] = p.value
  }
  return out
}

/** Today's civil date in Africa/Cairo as YYYY-MM-DD. */
export function todayCivilIso(now: Date = new Date()): string {
  const p = partsMap(civilPartsFormatter, now)
  return `${p.year}-${p.month}-${p.day}`
}

/** Current Cairo month as YYYY-MM. */
export function currentCivilMonth(now: Date = new Date()): string {
  return todayCivilIso(now).slice(0, 7)
}

/**
 * Add whole calendar days to a YYYY-MM-DD civil date (noon anchor avoids DST edge flips).
 */
export function addCivilDays(baseIso: string, days: number): string {
  const base = baseIso.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(base)) return ''
  const d = new Date(`${base}T12:00:00`)
  d.setDate(d.getDate() + days)
  // Re-express via Cairo so the civil day matches business zone after local math.
  return todayCivilIso(d)
}

/**
 * Value for `<input type="datetime-local">` in Africa/Cairo wall clock: YYYY-MM-DDTHH:mm
 */
export function nowLocalDateTimeValue(now: Date = new Date()): string {
  const p = partsMap(localDateTimePartsFormatter, now)
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

/**
 * Civil YYYY-MM-DD for an instant or date-only string, in Africa/Cairo.
 * Bare `YYYY-MM-DD` (optional time ignored when already date-prefixed) is returned as-is
 * when the string starts with a calendar date with no explicit Z/offset shift needed:
 * - `1990-01-01` → `1990-01-01`
 * - `1989-12-31T22:00:00.000000Z` → `1990-01-01` (Cairo)
 */
export function civilDateOnly(iso: string | null | undefined): string {
  if (!iso) return ''
  const raw = String(iso).trim()
  if (!raw) return ''

  // Pure civil date (or datetime without zone) — keep the written calendar day.
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(raw) && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)) {
    return raw.slice(0, 10)
  }

  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return ''
  return todayCivilIso(d)
}
