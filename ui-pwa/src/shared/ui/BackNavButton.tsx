import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { IconArrowRight } from './Icons'
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

/**
 * Distinct RTL back control — arrow points toward the right edge (start of reading).
 * Use on every detail page as the primary return-to-list affordance.
 */
export function BackNavButton({ to, label, className = '', onClick }: Props) {
  const navigate = useNavigate()

  return (
    <button
      type="button"
      className={`rpm-back-nav ${className}`.trim()}
      onClick={() => {
        if (onClick) onClick()
        else navigate(to)
      }}
    >
      <span className="rpm-back-nav__icon" aria-hidden>
        <IconArrowRight size={18} />
      </span>
      <span className="rpm-back-nav__label">{label}</span>
    </button>
  )
}
