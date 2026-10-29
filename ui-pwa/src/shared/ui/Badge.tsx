import type { HTMLAttributes, ReactNode } from 'react'
import './Badge.css'

export type BadgeTone = 'success' | 'warning' | 'danger' | 'steel' | 'brand' | 'muted' | 'accent'

type Props = HTMLAttributes<HTMLSpanElement> & {
  tone?: BadgeTone
  /** Show the tiny status dot (default true) */
  dot?: boolean
  children: ReactNode
}

export function Badge({ tone = 'muted', dot = true, className = '', children, ...rest }: Props) {
  return (
    <span className={`rpm-badge rpm-badge--${tone} ${className}`.trim()} {...rest}>
      {dot ? <i className="rpm-badge__dot" aria-hidden /> : null}
      {children}
    </span>
  )
}
