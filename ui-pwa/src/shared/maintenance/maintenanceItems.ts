/** بنود الصيانة المعتمدة — اختيار من القائمة مع إمكانية إضافة بند غير موجود. */
export const MAINTENANCE_ITEM_PRESETS = [
  'زيت موتور',
  'فلتر زيت موتور',
  'زيت فتيس',
  'فلتر زيت فتيس',
  'فلتر هوا',
  'فلتر تكيف',
  'فلتر بنزين',
  'بيوجوهات',
  'سير مجموعه',
  'إطارات أو كاوتش',
] as const

export type MaintenanceItemPreset = (typeof MAINTENANCE_ITEM_PRESETS)[number]

export function normalizeMaintenanceItemLabel(raw: string): string {
  return raw.replace(/\s+/g, ' ').trim()
}
