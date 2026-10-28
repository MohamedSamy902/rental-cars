import './StatusPill.css'

const tones = {
  available: 'success',
  reserved: 'warning',
  rented: 'brand',
  maintenance: 'danger',
  pending: 'warning',
  approved: 'brand',
  active: 'success',
  completed: 'muted',
  cancelled: 'danger',
} as const

type ToneKey = keyof typeof tones

type Props = {
  status: ToneKey | string
  label: string
}

export function StatusPill({ status, label }: Props) {
  const tone = (tones as Record<string, string>)[status] ?? 'muted'
  return <span className={`rpm-pill rpm-pill--${tone}`}>{label}</span>
}
