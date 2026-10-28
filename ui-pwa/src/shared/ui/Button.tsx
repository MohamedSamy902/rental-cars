import {
  useEffect,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type MouseEvent,
  type ReactNode,
} from 'react'
import './Button.css'

type Variant = 'primary' | 'secondary' | 'accent' | 'ghost' | 'danger'
type Size = 'md' | 'sm' | 'lg'

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  size?: Size
  loading?: boolean
  /** Leading icon (RTL-aware via flex order + gap) */
  iconStart?: ReactNode
  /** Trailing icon */
  iconEnd?: ReactNode
  children: ReactNode
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  children,
  className = '',
  onClick,
  type = 'button',
  iconStart,
  iconEnd,
  ...rest
}: Props) {
  const [internalBusy, setInternalBusy] = useState(false)
  const inFlight = useRef(false)
  const sawLoading = useRef(false)
  const loadingRef = useRef(loading)
  loadingRef.current = loading

  useEffect(() => {
    if (loading) {
      sawLoading.current = true
      return
    }
    if (sawLoading.current && internalBusy) {
      sawLoading.current = false
      inFlight.current = false
      setInternalBusy(false)
    }
  }, [loading, internalBusy])

  const locked = Boolean(disabled || loading || internalBusy)
  const busy = loading || internalBusy

  function handleClick(e: MouseEvent<HTMLButtonElement>) {
    if (locked || inFlight.current) {
      e.preventDefault()
      e.stopPropagation()
      return
    }

    // أزرار submit بدون onClick: لا تعطّل الزر قبل onSubmit وإلا يُلغى إرسال الفورم
    if (type === 'submit' && !onClick) {
      return
    }

    inFlight.current = true
    setInternalBusy(true)

    let result: unknown
    try {
      result = onClick?.(e)
    } catch (err) {
      inFlight.current = false
      setInternalBusy(false)
      throw err
    }

    if (result != null && typeof (result as PromiseLike<unknown>).then === 'function') {
      Promise.resolve(result as PromiseLike<unknown>).finally(() => {
        inFlight.current = false
        setInternalBusy(false)
      })
      return
    }

    window.setTimeout(() => {
      if (!loadingRef.current) {
        inFlight.current = false
        setInternalBusy(false)
      }
    }, 450)
  }

  return (
    <button
      type={type}
      className={`rpm-btn rpm-btn--${variant} rpm-btn--${size} ${className}`.trim()}
      disabled={locked}
      aria-busy={busy || undefined}
      {...rest}
      onClick={handleClick}
    >
      {busy ? <span className="rpm-btn__spinner" aria-hidden /> : null}
      {!busy && iconStart ? <span className="rpm-btn__icon">{iconStart}</span> : null}
      <span className={busy ? 'rpm-btn__label is-loading' : 'rpm-btn__label'}>{children}</span>
      {!busy && iconEnd ? <span className="rpm-btn__icon">{iconEnd}</span> : null}
    </button>
  )
}
