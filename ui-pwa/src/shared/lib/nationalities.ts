/** Canonical Arabic nationality labels — keep in sync with backend Nationalities. */
export const NATIONALITIES = [
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
  'موريتاني',
  'صومالي',
  'جيبوتي',
  'إريتري',
  'إثيوبي',
  'كيني',
  'نيجيري',
  'جنوب أفريقي',
  'تركي',
  'إيراني',
  'أفغاني',
  'باكستاني',
  'هندي',
  'بنغلاديشي',
  'سريلانكي',
  'نيبالي',
  'فلبيني',
  'إندونيسي',
  'ماليزي',
  'صيني',
  'كوري',
  'ياباني',
  'روسي',
  'أوكراني',
  'بريطاني',
  'أيرلندي',
  'فرنسي',
  'ألماني',
  'إيطالي',
  'إسباني',
  'برتغالي',
  'هولندي',
  'بلجيكي',
  'سويسري',
  'نمساوي',
  'سويدي',
  'نرويجي',
  'دنماركي',
  'بولندي',
  'روماني',
  'يوناني',
  'أمريكي',
  'كندي',
  'مكسيكي',
  'برازيلي',
  'أرجنتيني',
  'أسترالي',
  'نيوزيلندي',
  'أخرى',
] as const

export type Nationality = (typeof NATIONALITIES)[number]

/** Default stored/display value — typed as string so form state stays assignable from inputs/API. */
export const DEFAULT_NATIONALITY = 'مصري'

export const NATIONALITY_OPTIONS = NATIONALITIES.map((n) => ({ value: n, label: n }))

export function isEgyptianNationality(value?: string | null): boolean {
  return String(value || '').trim() === DEFAULT_NATIONALITY
}

export function normalizeNationality(value?: string | null): string {
  const trimmed = String(value || '').trim()
  return (NATIONALITIES as readonly string[]).includes(trimmed) ? trimmed : DEFAULT_NATIONALITY
}
