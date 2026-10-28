import { useEffect, useRef, useState } from 'react'
import { animate, useReducedMotion } from 'framer-motion'
import { formatMoney, formatNumber, formatPercent } from '@/shared/lib/format'

type Props = {
  value: number
  kind?: 'number' | 'money' | 'percent'
  className?: string
  duration?: number
}

export function AnimatedNumber({ value, kind = 'number', className, duration = 0.8 }: Props) {
  const reduce = useReducedMotion()
  const [display, setDisplay] = useState(value)
  const first = useRef(true)

  useEffect(() => {
    if (reduce) {
      setDisplay(value)
      return
    }
    const from = first.current ? Math.max(0, value * 0.2) : display
    first.current = false
    const controls = animate(from, value, {
      duration,
      ease: [0.22, 1, 0.36, 1],
      onUpdate: (v) => setDisplay(v),
    })
    return () => controls.stop()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, duration, reduce])

  const rounded = Math.round(display)
  const text =
    kind === 'money' ? formatMoney(rounded) : kind === 'percent' ? formatPercent(rounded) : formatNumber(rounded)

  return <span className={`tabular-nums ${className ?? ''}`}>{text}</span>
}
