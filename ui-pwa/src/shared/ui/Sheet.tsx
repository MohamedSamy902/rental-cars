import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { IconClose } from './Icons'
import { useIsMobile, useSheetDrag } from './useSheetDrag'
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

const sheetSpring = { type: 'spring', stiffness: 420, damping: 40, mass: 0.9 } as const

function resetPageScroll() {
  const main = document.querySelector('.shell-main') as HTMLElement | null
  window.scrollTo({ top: 0, left: 0, behavior: 'instant' in window ? 'instant' as ScrollBehavior : 'auto' })
  document.documentElement.scrollTop = 0
  document.body.scrollTop = 0
  if (main) main.scrollTop = 0
  if (window.visualViewport && window.visualViewport.offsetTop > 0) {
    window.scrollTo(0, 0)
  }
}

function isField(el: HTMLElement) {
  return el.matches('input, textarea, select, [contenteditable="true"]')
}

export function Sheet(props: Props) {
  return createPortal(
    <AnimatePresence>{props.open ? <SheetLayer key="rpm-sheet" {...props} /> : null}</AnimatePresence>,
    document.body,
  )
}

function SheetLayer({ title, onClose, children, footer, closeLabel = 'إلغاء' }: Omit<Props, 'open'>) {
  const rootRef = useRef<HTMLDivElement>(null)
  const wrapRef = useRef<HTMLDivElement>(null)
  const bodyRef = useRef<HTMLDivElement>(null)
  const savedScroll = useRef(0)
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])
  const close = useCallback(() => onCloseRef.current(), [])
  const titleId = useId()
  const mobile = useIsMobile()
  const { startDrag, dragProps } = useSheetDrag(close, mobile)

  useEffect(() => {
    savedScroll.current = window.scrollY || document.documentElement.scrollTop || 0
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close()
    }
    const prev = document.body.style.overflow
    const prevPos = document.body.style.position
    const prevTop = document.body.style.top
    const prevWidth = document.body.style.width
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    document.body.style.position = 'fixed'
    document.body.style.top = `-${savedScroll.current}px`
    document.body.style.width = '100%'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
      document.body.style.position = prevPos
      document.body.style.top = prevTop
      document.body.style.width = prevWidth
      window.scrollTo(0, savedScroll.current)
      window.setTimeout(() => resetPageScroll(), 0)
    }
  }, [close])

  // Pin the overlay to the visual viewport and pad the sticky footer by the keyboard inset.
  useEffect(() => {
    const root = rootRef.current
    const wrap = wrapRef.current
    const vv = window.visualViewport
    if (!root) return

    const sync = () => {
      const layoutH = window.innerHeight
      const height = vv?.height ?? layoutH
      const offsetTop = vv?.offsetTop ?? 0
      const keyboard = Math.max(0, Math.round(layoutH - height - offsetTop))
      root.style.height = `${Math.round(height)}px`
      root.style.top = `${Math.round(offsetTop)}px`
      root.style.bottom = 'auto'
      root.style.setProperty('--kb-inset', `${keyboard}px`)
      wrap?.style.setProperty('--kb-inset', `${keyboard}px`)
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
      root.style.removeProperty('--kb-inset')
    }
  }, [])

  // Scroll the focused field into the sheet body — never the page.
  useEffect(() => {
    const body = bodyRef.current
    if (!body) return
    const reveal = (target: HTMLElement) => {
      window.setTimeout(() => {
        const bodyRect = body.getBoundingClientRect()
        const targetRect = target.getBoundingClientRect()
        const pad = 24
        if (targetRect.bottom > bodyRect.bottom - pad) {
          body.scrollTop += targetRect.bottom - bodyRect.bottom + pad
        } else if (targetRect.top < bodyRect.top + pad) {
          body.scrollTop -= bodyRect.top + pad - targetRect.top
        }
      }, 280)
    }
    const onFocusIn = (e: FocusEvent) => {
      const target = e.target as HTMLElement | null
      if (!target || !body.contains(target) || !isField(target)) return
      reveal(target)
    }
    body.addEventListener('focusin', onFocusIn)
    return () => body.removeEventListener('focusin', onFocusIn)
  }, [])

  const motionIn = mobile
    ? { initial: { y: '100%' }, animate: { y: 0 }, exit: { y: '100%' } }
    : {
        initial: { opacity: 0, y: 14, scale: 0.98 },
        animate: { opacity: 1, y: 0, scale: 1 },
        exit: { opacity: 0, y: 10, scale: 0.98 },
      }

  return (
    <div className="rpm-sheet-root" role="presentation" ref={rootRef}>
      <motion.button
        className="rpm-sheet-backdrop"
        aria-label="إغلاق اللوحة"
        onClick={close}
        type="button"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }}
      />
      <motion.div
        className="rpm-sheet-wrap"
        ref={wrapRef}
        {...motionIn}
        transition={sheetSpring}
        {...dragProps}
      >
        <aside className="rpm-sheet" role="dialog" aria-modal="true" aria-labelledby={titleId}>
          <div className="rpm-sheet__grab" onPointerDown={startDrag}>
            <div className="rpm-sheet__handle" aria-hidden />
            <header className="rpm-sheet__head">
              <button type="button" className="rpm-sheet__cancel" onClick={close}>
                {closeLabel}
              </button>
              <h2 id={titleId}>{title}</h2>
              <button
                type="button"
                className="rpm-sheet__close rpm-sheet__close--desk"
                aria-label="إغلاق"
                onClick={close}
              >
                <IconClose size={16} />
              </button>
              <span className="rpm-sheet__head-spacer" aria-hidden />
            </header>
          </div>
          <div className="rpm-sheet__body" ref={bodyRef}>{children}</div>
          {footer ? <footer className="rpm-sheet__foot">{footer}</footer> : null}
        </aside>
      </motion.div>
    </div>
  )
}
