import type { ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { IconChevronRight } from './Icons'
import './BackNavButton.css'

type Props = {
  /** List route to navigate to */
  to: string
  /** Visible label, e.g. رجوع للعربيات */
  label: string
  className?: string
  /** Optional override click (still renders the same chrome) */
  onClick?: () => void
  children?: ReactNode
}

type LocationState = {
  from?: string
} | null

function isSameList(from: string, to: string) {
  return from === to || from.startsWith(`${to}?`)
}

/**
 * Minimal RTL back control — matches toolbar secondary geometry.
 * Chevron points toward the right edge (start of reading in RTL).
 * Uses history back when detail was opened with `state.from` matching `to`
 * so list filters and scroll memory apply.
 */
export function BackNavButton({ to, label, className = '', onClick }: Props) {
  const navigate = useNavigate()
  const location = useLocation()

  return (
    <button
      type="button"
      className={`rpm-back-nav ${className}`.trim()}
      onClick={() => {
        if (onClick) {
          onClick()
          return
        }
        const from = (location.state as LocationState)?.from
        if (typeof from === 'string' && isSameList(from, to)) {
          navigate(-1)
          return
        }
        navigate(to)
      }}
    >
      <span className="rpm-back-nav__icon" aria-hidden>
        <IconChevronRight size={16} />
      </span>
      <span className="rpm-back-nav__label">{label}</span>
    </button>
  )
}
