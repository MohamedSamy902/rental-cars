import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { IconClose } from './Icons'
import './Sheet.css'

type Props = {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  /** Mobile header dismiss label — إلغاء forms, إغلاق detail */
  closeLabel?: string
}

export function Sheet({ open, title, onClose, children, footer, closeLabel = 'إلغاء' }: Props) {
  const rootRef = useRef<HTMLDivElement>(null)
  const bodyRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    const prev = document.body.style.overflow
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [open, onClose])

  // Keep sheet inside the visual viewport so the iOS keyboard doesn't shove the UI away
  useEffect(() => {
    if (!open) return
    const root = rootRef.current
    const vv = window.visualViewport
    if (!root) return

    const sync = () => {
      const height = vv?.height ?? window.innerHeight
      const offsetTop = vv?.offsetTop ?? 0
      root.style.height = `${height}px`
      root.style.top = `${offsetTop}px`
      root.style.bottom = 'auto'
    }

    sync()
    vv?.addEventListener('resize', sync)
    vv?.addEventListener('scroll', sync)
    window.addEventListener('resize', sync)
    return () => {
      vv?.removeEventListener('resize', sync)
      vv?.removeEventListener('scroll', sync)
      window.removeEventListener('resize', sync)
      root.style.height = ''
      root.style.top = ''
      root.style.bottom = ''
    }
  }, [open])

  // Scroll focused inputs into the sheet body instead of scrolling the page
  useEffect(() => {
    if (!open) return
    const body = bodyRef.current
    if (!body) return
    const onFocusIn = (e: FocusEvent) => {
      const target = e.target as HTMLElement | null
      if (!target || !body.contains(target)) return
      window.setTimeout(() => {
        target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' })
      }, 80)
    }
    body.addEventListener('focusin', onFocusIn)
    return () => body.removeEventListener('focusin', onFocusIn)
  }, [open])

  if (!open) return null

  return createPortal(
    <div className="rpm-sheet-root" role="presentation" ref={rootRef}>
      <button
        className="rpm-sheet-backdrop"
        aria-label="إغلاق اللوحة"
        onClick={onClose}
        type="button"
      />
      <div className="rpm-sheet-wrap">
        <button
          type="button"
          className="rpm-sheet__close rpm-sheet__close--desk"
          aria-label="إغلاق"
          onClick={onClose}
        >
          <IconClose size={16} />
        </button>
        <aside className="rpm-sheet" role="dialog" aria-modal="true" aria-labelledby="rpm-sheet-title">
          <div className="rpm-sheet__handle" aria-hidden />
          <header className="rpm-sheet__head">
            <button type="button" className="rpm-sheet__cancel" onClick={onClose}>
              {closeLabel}
            </button>
            <h2 id="rpm-sheet-title">{title}</h2>
            <span className="rpm-sheet__head-spacer" aria-hidden />
          </header>
          <div className="rpm-sheet__body" ref={bodyRef}>{children}</div>
          {footer ? <footer className="rpm-sheet__foot">{footer}</footer> : null}
        </aside>
      </div>
    </div>,
    document.body,
  )
}
