import type { SelectOption } from '@/shared/ui/SelectField'

export type VehicleSelectSource = {
  id: number
  brand: string
  model: string
  plate: string
  primary_photo_url?: string | null
}

/** خيار Select موحّد للعربية — يشمل الصورة الأساسية إن وُجدت. */
export function vehicleSelectOption(
  v: VehicleSelectSource,
  extra?: Partial<Pick<SelectOption, 'description'>>,
): SelectOption {
  return {
    value: String(v.id),
    label: `${v.brand} ${v.model} · ${v.plate}`,
    imageUrl: v.primary_photo_url || null,
    ...extra,
  }
}

export function vehicleSelectOptions(
  vehicles: VehicleSelectSource[],
  empty?: SelectOption,
): SelectOption[] {
  const mapped = vehicles.map((v) => vehicleSelectOption(v))
  return empty ? [empty, ...mapped] : mapped
}
