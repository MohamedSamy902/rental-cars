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

/** Checkbox/radio/file (and visually hidden inputs) must not trigger reveal —
 * native focus scrolling can scroll `.rpm-sheet` (overflow:hidden is still
 * programmatically scrollable) and clip head/body/footer out of view. */
function shouldRevealField(el: HTMLElement) {
  if (!isField(el)) return false
  if (el instanceof HTMLInputElement) {
    const t = el.type
    if (t === 'checkbox' || t === 'radio' || t === 'file' || t === 'hidden' || t === 'button' || t === 'submit' || t === 'reset') {
      return false
    }
  }
  const cs = getComputedStyle(el)
  if (cs.opacity === '0' || cs.visibility === 'hidden') return false
  const r = el.getBoundingClientRect()
  if (r.width < 2 && r.height < 2) return false
  return true
}

function pinSheetScroll(sheet: HTMLElement | null | undefined) {
  if (!sheet) return
  if (sheet.scrollTop !== 0) sheet.scrollTop = 0
  if (sheet.scrollLeft !== 0) sheet.scrollLeft = 0
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

  // Pin the overlay to the visual viewport. Available height already excludes
  // the keyboard (interactive-widget=overlays-content) — body/footer only use
  // safe-area padding, never a second keyboard inset (that caused the blank void).
  useEffect(() => {
    const root = rootRef.current
    const vv = window.visualViewport
    if (!root) return

    const sync = () => {
      const layoutH = Math.max(320, window.innerHeight || 320)
      // After native file pickers some WebViews briefly report a tiny/zero vv height
      // which collapsed the sheet into a blank dark panel — clamp aggressively.
      const rawVv = Math.round(vv?.height ?? layoutH)
      const height = Math.max(280, rawVv > 80 ? rawVv : layoutH)
      const offsetTop = Math.max(0, Math.round(vv?.offsetTop ?? 0))
      // dvh does not shrink with interactive-widget=overlays-content, so cap
      // the sheet against the visual viewport explicitly. Use full vv height
      // when the keyboard is open; 90% when closed (classic bottom-sheet gap).
      const keyboardOpen = height < layoutH - 40
      const sheetMax = Math.max(240, Math.round(height * (keyboardOpen ? 1 : 0.9)))
      root.style.setProperty('--vv-height', `${height}px`)
      root.style.setProperty('--vv-top', `${offsetTop}px`)
      root.style.setProperty('--sheet-max-h', `${sheetMax}px`)
      root.dataset.keyboard = keyboardOpen ? 'open' : 'closed'
      const foot = root.querySelector('.rpm-sheet__foot') as HTMLElement | null
      if (foot) {
        root.style.setProperty('--sheet-foot-h', `${Math.round(foot.getBoundingClientRect().height)}px`)
      }
      // Keep page scroll locked to the visual viewport origin on iOS.
      if (offsetTop > 0 || (vv && vv.pageTop > 0)) {
        window.scrollTo(0, 0)
      }
      pinSheetScroll(root.querySelector('.rpm-sheet') as HTMLElement | null)
    }

    sync()
    vv?.addEventListener('resize', sync)
    vv?.addEventListener('scroll', sync)
    window.addEventListener('resize', sync)
    window.addEventListener('orientationchange', sync)
    window.addEventListener('focus', sync)
    document.addEventListener('visibilitychange', sync)
    return () => {
      vv?.removeEventListener('resize', sync)
      vv?.removeEventListener('scroll', sync)
      window.removeEventListener('resize', sync)
      window.removeEventListener('orientationchange', sync)
      window.removeEventListener('focus', sync)
      document.removeEventListener('visibilitychange', sync)
      root.style.removeProperty('--vv-height')
      root.style.removeProperty('--vv-top')
      root.style.removeProperty('--sheet-max-h')
      root.style.removeProperty('--sheet-foot-h')
      delete root.dataset.keyboard
    }
  }, [])

  // Pin `.rpm-sheet` scrollTop at 0. overflow:hidden panels are still
  // programmatically scrollable; native focus / scrollIntoView can shift
  // head+body+footer out of the clipped box → blank dark sheet.
  useEffect(() => {
    const sheet = rootRef.current?.querySelector('.rpm-sheet') as HTMLElement | null
    if (!sheet) return
    const pin = () => pinSheetScroll(sheet)
    pin()
    sheet.addEventListener('scroll', pin, { passive: true })
    return () => sheet.removeEventListener('scroll', pin)
  }, [])

  // Scroll the focused field inside the sheet body only — never scrollIntoView
  // (that scrolls `.rpm-sheet` / page and blanks the dialog).
  useEffect(() => {
    const body = bodyRef.current
    if (!body) return
    const sheetEl = () => rootRef.current?.querySelector('.rpm-sheet') as HTMLElement | null
    const reveal = (target: HTMLElement) => {
      const run = () => {
        pinSheetScroll(sheetEl())
        const root = rootRef.current
        const keyboardOpen = root?.dataset.keyboard === 'open'
        const bodyRect = body.getBoundingClientRect()
        const targetRect = target.getBoundingClientRect()
        // Extra room above the sticky-feeling foot + iOS accessory bar.
        const pad = keyboardOpen ? 72 : 28
        if (targetRect.bottom > bodyRect.bottom - pad) {
          body.scrollTop += targetRect.bottom - bodyRect.bottom + pad
        } else if (targetRect.top < bodyRect.top + pad) {
          body.scrollTop -= bodyRect.top + pad - targetRect.top
        }
        pinSheetScroll(sheetEl())
      }
      window.setTimeout(run, 50)
      window.setTimeout(run, 180)
      window.setTimeout(run, 360)
    }
    const onFocusIn = (e: FocusEvent) => {
      const target = e.target as HTMLElement | null
      if (!target || !body.contains(target) || !isField(target)) return
      // Immediate pin: browser may scroll `.rpm-sheet` synchronously on focus.
      pinSheetScroll(sheetEl())
      // Re-measure vv after focus — iOS often opens the keyboard a frame later.
      window.dispatchEvent(new Event('resize'))
      if (!shouldRevealField(target)) return
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
