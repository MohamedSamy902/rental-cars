/**
 * Nationality is free text. Suggestions are optional UX helpers only.
 * Keep suggestion labels loosely aligned with backend Nationalities::suggestions().
 */
export const NATIONALITY_SUGGESTIONS = [
  'مصري',
  'سعودي',
  'إماراتي',
  'كويتي',
  'قطري',
  'بحريني',
  'عماني',
  'يمني',
  'سوري',
  'لبناني',
  'أردني',
  'فلسطيني',
  'عراقي',
  'سوداني',
  'ليبي',
  'تونسي',
  'جزائري',
  'مغربي',
  'تركي',
  'بريطاني',
  'أمريكي',
  'أخرى',
] as const

/** @deprecated Prefer NATIONALITY_SUGGESTIONS — list is not closed. */
export const NATIONALITIES = NATIONALITY_SUGGESTIONS

export type Nationality = string

/** Default when the form opens empty. */
export const DEFAULT_NATIONALITY = 'مصري'

/** @deprecated Select options — prefer NationalityField free text. */
export const NATIONALITY_OPTIONS = NATIONALITY_SUGGESTIONS.map((n) => ({ value: n, label: n }))

export const NATIONALITY_DATALIST_ID = 'rpm-nationality-suggestions'

export function isEgyptianNationality(value?: string | null): boolean {
  return String(value || '').trim() === DEFAULT_NATIONALITY
}

/** Keep any non-empty written nationality; empty → default for form UX. */
export function normalizeNationality(value?: string | null): string {
  const trimmed = String(value || '').trim()
  return trimmed || DEFAULT_NATIONALITY
}
