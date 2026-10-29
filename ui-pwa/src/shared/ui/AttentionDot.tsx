import './AttentionDot.css'

export type AttentionTone = 'critical' | 'warning'

type Props = {
  tone: AttentionTone
  /** Soft ping for high-priority (critical) only — ignored for warning */
  ping?: boolean
  /** absolute on icon host vs inline beside label */
  placement?: 'absolute' | 'inline'
  label?: string
  className?: string
}

export function AttentionDot({
  tone,
  ping = false,
  placement = 'absolute',
  label,
  className = '',
}: Props) {
  const showPing = tone === 'critical' && ping
  const classes = [
    'rpm-attention-dot',
    `rpm-attention-dot--${tone}`,
    showPing ? 'rpm-attention-dot--ping' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  const dot = (
    <span
      className={classes}
      role="status"
      aria-label={label || (tone === 'critical' ? 'تنبيه عاجل' : 'يتطلب انتباهاً')}
    />
  )

  if (placement === 'inline') {
    return dot
  }

  return dot
}

/** Resolve the strongest tone from counts: critical wins over warning. Zero/undefined → nothing. */
export function resolveAttentionTone(input: {
  critical?: number
  warning?: number
}): AttentionTone | null {
  const critical = Number(input.critical)
  const warning = Number(input.warning)
  if (Number.isFinite(critical) && critical > 0) return 'critical'
  if (Number.isFinite(warning) && warning > 0) return 'warning'
  return null
}
