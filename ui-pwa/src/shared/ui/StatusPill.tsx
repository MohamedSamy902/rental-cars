import { Badge, type BadgeTone } from './Badge'

const tones: Record<string, BadgeTone> = {
  available: 'success',
  reserved: 'warning',
  rented: 'steel',
  maintenance: 'warning',
  pending: 'warning',
  approved: 'success',
  active: 'steel',
  completed: 'muted',
  cancelled: 'danger',
  ok: 'success',
  warn: 'warning',
  danger: 'danger',
  steel: 'steel',
  brand: 'brand',
  muted: 'muted',
  accent: 'accent',
}

type Props = {
  status: string
  label: string
  tone?: BadgeTone
  dot?: boolean
}

/** Status mapping helper — renders the shared Badge with a status dot. */
export function StatusPill({ status, label, tone, dot = true }: Props) {
  const resolved = tone ?? tones[status] ?? 'muted'
  return (
    <Badge tone={resolved} dot={dot}>
      {label}
    </Badge>
  )
}
