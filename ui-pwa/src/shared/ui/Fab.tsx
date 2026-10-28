import {
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type MouseEvent,
  type ReactNode,
} from 'react'
import { IconPlus } from './Icons'
import './Fab.css'

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  label: string
  icon?: ReactNode
}

export function Fab({ label, icon, className = '', disabled, onClick, ...rest }: Props) {
  const [busy, setBusy] = useState(false)
  const inFlight = useRef(false)
  const locked = Boolean(disabled || busy)

  function handleClick(e: MouseEvent<HTMLButtonElement>) {
    if (locked || inFlight.current) {
      e.preventDefault()
      e.stopPropagation()
      return
    }
    inFlight.current = true
    setBusy(true)
    let result: unknown
    try {
      result = onClick?.(e)
    } catch (err) {
      inFlight.current = false
      setBusy(false)
      throw err
    }
    if (result != null && typeof (result as PromiseLike<unknown>).then === 'function') {
      Promise.resolve(result as PromiseLike<unknown>).finally(() => {
        inFlight.current = false
        setBusy(false)
      })
      return
    }
    window.setTimeout(() => {
      inFlight.current = false
      setBusy(false)
    }, 450)
  }

  return (
    <button
      type="button"
      className={`rpm-fab ${className}`.trim()}
      aria-label={label}
      disabled={locked}
      aria-busy={busy || undefined}
      onClick={handleClick}
      {...rest}
    >
      {icon ?? <IconPlus size={22} />}
    </button>
  )
}
