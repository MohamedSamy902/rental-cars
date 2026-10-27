import { useEffect, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { IconClose } from './Icons'
import './Sheet.css'

type Props = {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}

export function Sheet({ open, title, onClose, children, footer }: Props) {
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

  if (!open) return null

  return createPortal(
    <div className="rpm-sheet-root" role="presentation">
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
              إلغاء
            </button>
            <h2 id="rpm-sheet-title">{title}</h2>
            <span className="rpm-sheet__head-spacer" aria-hidden />
          </header>
          <div className="rpm-sheet__body">{children}</div>
          {footer ? <footer className="rpm-sheet__foot">{footer}</footer> : null}
        </aside>
      </div>
    </div>,
    document.body,
  )
}
