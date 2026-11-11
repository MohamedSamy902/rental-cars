import './RentalProgressBar.css'

export type RentalProgress = {
  status: string
  tone: 'normal' | 'warning' | 'danger' | 'muted'
  percent: number
  remaining_label: string
  overdue_days: number
  limiting_label?: string | null
  message: string
}

type Props = {
  progress?: RentalProgress | null
  compact?: boolean
}

function normalizeProgressCopy(value: string) {
  return value.replace(/[.\u060C\s]/gu, '')
}

export function RentalProgressBar({ progress, compact }: Props) {
  if (!progress) return null

  const labelNorm = normalizeProgressCopy(progress.remaining_label)
  const messageNorm = normalizeProgressCopy(progress.message)
  const messageIsRedundant = !progress.message
    || messageNorm === labelNorm
    || messageNorm.includes(labelNorm)
    || labelNorm.includes(messageNorm)

  return (
    <div
      className={`rpm-rental-progress is-${progress.tone}${compact ? ' is-compact' : ''}`}
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={progress.percent}
      aria-label={progress.message || progress.remaining_label}
    >
      <div className="rpm-rental-progress__meta">
        <span className="rpm-rental-progress__label">{progress.remaining_label}</span>
        {!compact && progress.limiting_label ? (
          <span className="rpm-rental-progress__limit">ينتهي فعلياً بسبب: {progress.limiting_label}</span>
        ) : null}
      </div>
      <div className="rpm-rental-progress__track">
        <div className="rpm-rental-progress__fill" style={{ width: `${progress.percent}%` }} />
      </div>
      {!compact && !messageIsRedundant ? <p className="rpm-rental-progress__msg">{progress.message}</p> : null}
    </div>
  )
}
