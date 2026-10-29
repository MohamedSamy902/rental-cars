import type { ReactNode } from 'react'
import { motion, useReducedMotion } from 'framer-motion'
import { AnimatedNumber } from './AnimatedNumber'
import './MetricCardGrid.css'

export type MetricTone = 'default' | 'success' | 'warning' | 'danger' | 'info'

export type Metric = {
  id: string
  label: string
  value: number | string
  format?: 'money' | 'number' | 'percent'
  tone?: MetricTone
  hint?: ReactNode
  icon?: ReactNode
}

type Props = {
  items: Metric[]
  label?: string
  /** Phones: `scroll` keeps one swipeable row; `wrap` reflows into two proportional columns */
  mobile?: 'scroll' | 'wrap'
  className?: string
}

const spring = { type: 'spring', stiffness: 420, damping: 34, mass: 0.8 } as const

export function MetricCardGrid({ items, label = 'مؤشرات', mobile = 'scroll', className }: Props) {
  const reduce = useReducedMotion()
  return (
    <div
      className={['rpm-metrics', `rpm-metrics--${mobile}`, className].filter(Boolean).join(' ')}
      role="list"
      aria-label={label}
    >
      {items.map((m, i) => (
        <motion.article
          key={m.id}
          role="listitem"
          className={`rpm-metric is-${m.tone ?? 'default'}`}
          initial={reduce ? false : { opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ ...spring, delay: reduce ? 0 : i * 0.045 }}
        >
          <header className="rpm-metric__head">
            <span className="rpm-metric__label">{m.label}</span>
            {m.icon ? <span className="rpm-metric__icon" aria-hidden>{m.icon}</span> : null}
          </header>
          <strong className="rpm-metric__value">
            {typeof m.value === 'number' ? <AnimatedNumber value={m.value} kind={m.format ?? 'number'} /> : m.value}
          </strong>
          {m.hint ? <span className="rpm-metric__hint">{m.hint}</span> : null}
        </motion.article>
      ))}
    </div>
  )
}
