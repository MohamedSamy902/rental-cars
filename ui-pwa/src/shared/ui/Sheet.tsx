import { useEffect, type ReactNode } from 'react'
import { IconX } from './Icons'
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
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="rpm-sheet-root" role="presentation">
      <button className="rpm-sheet-backdrop" aria-label="إغلاق اللوحة" onClick={onClose} type="button" />
      <aside className="rpm-sheet" role="dialog" aria-modal="true" aria-labelledby="rpm-sheet-title">
        <button
          type="button"
          className="rpm-sheet__close"
          aria-label="إغلاق"
          onClick={onClose}
        >
          <IconX size={18} />
        </button>
        <header className="rpm-sheet__head">
          <h2 id="rpm-sheet-title">{title}</h2>
        </header>
        <div className="rpm-sheet__body rpm-scroll">{children}</div>
        {footer ? <footer className="rpm-sheet__foot">{footer}</footer> : null}
      </aside>
    </div>
  )
}
