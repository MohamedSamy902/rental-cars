import { VehicleFormSheet } from './VehicleFormSheet'

/** @deprecated Prefer VehicleFormSheet — kept as create-mode alias. */
export function VehicleCreateSheet(props: {
  open: boolean
  onClose: () => void
  onSaved: (vehicleId?: number) => void
}) {
  return <VehicleFormSheet mode="create" {...props} />
}
