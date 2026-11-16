/** Fleet vehicle category options (stored/displayed as Arabic/Latin labels). */
export const VEHICLE_CATEGORY_OPTIONS = [
  { value: 'اقتصادي', label: 'اقتصادي' },
  { value: 'سيدان', label: 'سيدان' },
  { value: 'SUV', label: 'SUV' },
  { value: 'دفع رباعي', label: 'دفع رباعي' },
  { value: 'فاخر', label: 'فاخر' },
  { value: 'لاكشري', label: 'لاكشري' },
] as const

export type VehicleCategory = (typeof VEHICLE_CATEGORY_OPTIONS)[number]['value']

export function vehicleCategorySelectOptions(current?: string | null): Array<{ value: string; label: string }> {
  const base: Array<{ value: string; label: string }> = VEHICLE_CATEGORY_OPTIONS.map((o) => ({
    value: o.value,
    label: o.label,
  }))
  const cur = (current || '').trim()
  if (cur && !base.some((o) => o.value === cur)) {
    base.push({ value: cur, label: cur })
  }
  return base
}
