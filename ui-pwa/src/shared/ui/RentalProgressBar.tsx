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

/** Legacy progress messages that only repeated the limiting reason — hide them. */
function isLimitingReasonMessage(value: string) {
  return /ينتهي\s*فعلياً?\s*بسبب/u.test(value)
}

export function RentalProgressBar({ progress, compact }: Props) {
  if (!progress) return null

  const labelNorm = normalizeProgressCopy(progress.remaining_label)
  const messageNorm = normalizeProgressCopy(progress.message)
  const messageIsRedundant = !progress.message
    || isLimitingReasonMessage(progress.message)
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
      aria-label={progress.remaining_label || progress.message}
    >
      <div className="rpm-rental-progress__meta">
        <span className="rpm-rental-progress__label">{progress.remaining_label}</span>
      </div>
      <div className="rpm-rental-progress__track">
        <div className="rpm-rental-progress__fill" style={{ width: `${progress.percent}%` }} />
      </div>
      {!compact && !messageIsRedundant ? <p className="rpm-rental-progress__msg">{progress.message}</p> : null}
    </div>
  )
}
