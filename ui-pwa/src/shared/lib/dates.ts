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

/** YYYY-MM-DD → DD/MM/YYYY (Western digits). Empty → ''. */
export function isoToDisplayDate(iso: string | null | undefined): string {
  const only = civilDateOnly(iso)
  if (!only) return ''
  const [y, m, d] = only.split('-')
  if (!y || !m || !d) return ''
  return `${d}/${m}/${y}`
}

/**
 * Parse user-facing day/month/year into YYYY-MM-DD.
 * Accepts DD/MM/YYYY, D/M/YYYY, DD-MM-YYYY, DD.MM.YYYY, or bare YYYY-MM-DD.
 */
export function parseDisplayDate(raw: string | null | undefined): string | null {
  if (raw == null) return null
  const s = String(raw).trim()
  if (!s) return null
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s

  const match = s.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/)
  if (!match) return null
  const day = Number(match[1])
  const month = Number(match[2])
  const year = Number(match[3])
  if (!Number.isFinite(day) || !Number.isFinite(month) || !Number.isFinite(year)) return null
  if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1000) return null

  const iso = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
  const probe = new Date(`${iso}T12:00:00`)
  if (Number.isNaN(probe.getTime())) return null
  if (probe.getFullYear() !== year || probe.getMonth() + 1 !== month || probe.getDate() !== day) return null
  return iso
}

/** Auto-insert slashes while typing digits for DD/MM/YYYY. */
export function maskDisplayDateInput(raw: string): string {
  const digits = String(raw).replace(/\D/g, '').slice(0, 8)
  if (digits.length <= 2) return digits
  if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`
  return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`
}
