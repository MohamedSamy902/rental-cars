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

// #region agent log
function dbgSheet(hypothesisId: string, location: string, message: string, data: Record<string, unknown> = {}) {
  try {
    const payload = { hypothesisId, location, message, data, timestamp: Date.now() }
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const w = window as any
    w.__rpmDbg = w.__rpmDbg || []
    w.__rpmDbg.push(payload)
    fetch('http://127.0.0.1:7242/ingest/3b8c0e2a-8f5d-4c1a-9e7b-dbg-sheet', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Debug-Session-Id': 'license-sheet' },
      body: JSON.stringify(payload),
    }).catch(() => {})
    // Mirror to NDJSON file via beacon when available; also console for Playwright
    console.info('[rpm-dbg]', JSON.stringify(payload))
    const line = JSON.stringify(payload) + '\n'
    // Best-effort local write through a custom hook the e2e script can scrape
    if (typeof w.__rpmDbgWrite === 'function') w.__rpmDbgWrite(line)
  } catch { /* ignore */ }
}

function sheetGeom(root: HTMLElement | null, wrap: HTMLElement | null, body: HTMLElement | null) {
  const sheet = root?.querySelector('.rpm-sheet') as HTMLElement | null
  const head = root?.querySelector('.rpm-sheet__head') as HTMLElement | null
  const foot = root?.querySelector('.rpm-sheet__foot') as HTMLElement | null
  const title = root?.querySelector('.rpm-sheet__head h2') as HTMLElement | null
  const form = body?.querySelector('.rpm-form-grid') as HTMLElement | null
  const rect = (el: HTMLElement | null) => {
    if (!el) return null
    const r = el.getBoundingClientRect()
    return { top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height), width: Math.round(r.width), opacity: getComputedStyle(el).opacity, transform: getComputedStyle(el).transform, visibility: getComputedStyle(el).visibility }
  }
  const vv = window.visualViewport
  return {
    bodyScrollTop: body?.scrollTop ?? null,
    bodyScrollHeight: body?.scrollHeight ?? null,
    bodyClientHeight: body?.clientHeight ?? null,
    sheetScrollTop: sheet?.scrollTop ?? null,
    docScrollTop: document.documentElement.scrollTop,
    pageScrollY: window.scrollY,
    bodyElScrollTop: document.body.scrollTop,
    bodyPos: document.body.style.position,
    bodyTop: document.body.style.top,
    vv: vv ? { height: vv.height, offsetTop: vv.offsetTop, pageTop: vv.pageTop, width: vv.width } : null,
    innerHeight: window.innerHeight,
    cssVvHeight: root?.style.getPropertyValue('--vv-height') || null,
    cssVvTop: root?.style.getPropertyValue('--vv-top') || null,
    cssSheetMaxH: root?.style.getPropertyValue('--sheet-max-h') || null,
    root: rect(root),
    wrap: rect(wrap),
    sheet: rect(sheet),
    head: rect(head),
    foot: rect(foot),
    titleText: title?.textContent || null,
    titleRect: rect(title),
    form: rect(form),
  }
}
// #endregion

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
      // #region agent log
      dbgSheet('C', 'Sheet.tsx:sync', 'visualViewport sync', {
        layoutH, rawVv, height, offsetTop, keyboardOpen, sheetMax,
        pageTop: vv?.pageTop ?? null,
        geom: sheetGeom(root, wrapRef.current, bodyRef.current),
      })
      // #endregion
      // Keep page scroll locked to the visual viewport origin on iOS.
      if (offsetTop > 0 || (vv && vv.pageTop > 0)) {
        window.scrollTo(0, 0)
      }
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
    }
  }, [])

  // Pin `.rpm-sheet` scrollTop at 0. overflow:hidden panels are still
  // programmatically scrollable; native focus / scrollIntoView can shift
  // head+body+footer out of the clipped box → blank dark sheet.
  useEffect(() => {
    const root = rootRef.current
    const sheet = root?.querySelector('.rpm-sheet') as HTMLElement | null
    if (!sheet) return
    const pin = () => {
      if (sheet.scrollTop !== 0 || sheet.scrollLeft !== 0) {
        // #region agent log
        dbgSheet('A', 'Sheet.tsx:pinSheet', 'reset sheet scroll', {
          runId: 'post-fix',
          wasTop: sheet.scrollTop,
          wasLeft: sheet.scrollLeft,
        })
        // #endregion
        sheet.scrollTop = 0
        sheet.scrollLeft = 0
      }
    }
    pin()
    sheet.addEventListener('scroll', pin, { passive: true })
    return () => sheet.removeEventListener('scroll', pin)
  }, [])

  // Scroll the focused field inside the sheet body only — never scrollIntoView
  // (that scrolls `.rpm-sheet` / page and blanks the dialog).
  useEffect(() => {
    const body = bodyRef.current
    if (!body) return
    const reveal = (target: HTMLElement) => {
      const run = (phase: string) => {
        // Always re-pin sheet in case native focus scrolled it first.
        const sheet = rootRef.current?.querySelector('.rpm-sheet') as HTMLElement | null
        if (sheet && sheet.scrollTop !== 0) sheet.scrollTop = 0

        // #region agent log
        const before = sheetGeom(rootRef.current, wrapRef.current, body)
        dbgSheet('A', 'Sheet.tsx:reveal', `reveal ${phase} BEFORE body-only`, {
          runId: 'post-fix',
          targetTag: target.tagName,
          targetType: (target as HTMLInputElement).type || null,
          targetClass: target.className,
          targetId: target.id,
          sheetScrollTop: sheet?.scrollTop ?? null,
          targetRect: (() => { const r = target.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height), w: Math.round(r.width) } })(),
          before,
        })
        // #endregion

        const bodyRect = body.getBoundingClientRect()
        const targetRect = target.getBoundingClientRect()
        const pad = 20
        let adjust: string = 'none'
        // Keep the field in the visible body band by adjusting body.scrollTop only.
        if (targetRect.bottom > bodyRect.bottom - pad) {
          body.scrollTop += targetRect.bottom - bodyRect.bottom + pad
          adjust = 'down'
        } else if (targetRect.top < bodyRect.top + pad) {
          body.scrollTop -= bodyRect.top + pad - targetRect.top
          adjust = 'up'
        }
        if (sheet && sheet.scrollTop !== 0) sheet.scrollTop = 0

        // #region agent log
        dbgSheet('A', 'Sheet.tsx:reveal', `reveal ${phase} AFTER body-only`, {
          runId: 'post-fix',
          adjust,
          bodyScrollTop: body.scrollTop,
          sheetScrollTop: sheet?.scrollTop ?? null,
          after: sheetGeom(rootRef.current, wrapRef.current, body),
        })
        // #endregion
        // #region agent log
        window.setTimeout(() => {
          if (sheet && sheet.scrollTop !== 0) sheet.scrollTop = 0
          dbgSheet('B', 'Sheet.tsx:reveal', `reveal ${phase} +200ms settle`, {
            runId: 'post-fix',
            settle: sheetGeom(rootRef.current, wrapRef.current, body),
            sheetScrollTop: sheet?.scrollTop ?? null,
          })
        }, 200)
        // #endregion
      }
      window.setTimeout(() => run('t50'), 50)
      window.setTimeout(() => run('t320'), 320)
    }
    const onFocusIn = (e: FocusEvent) => {
      const target = e.target as HTMLElement | null
      if (!target || !body.contains(target) || !isField(target)) return
      // Immediate pin: browser may scroll `.rpm-sheet` synchronously on focus.
      const sheet = rootRef.current?.querySelector('.rpm-sheet') as HTMLElement | null
      if (sheet && sheet.scrollTop !== 0) sheet.scrollTop = 0
      const revealable = shouldRevealField(target)
      // #region agent log
      dbgSheet('A', 'Sheet.tsx:focusin', revealable ? 'focusin field' : 'focusin skipped', {
        runId: 'post-fix',
        tag: target.tagName,
        type: (target as HTMLInputElement).type || null,
        className: String(target.className || ''),
        isFile: (target as HTMLInputElement).type === 'file',
        skipped: !revealable,
        sheetScrollTop: sheet?.scrollTop ?? null,
        isHiddenStyle: getComputedStyle(target).opacity === '0' || getComputedStyle(target).position === 'absolute',
      })
      // #endregion
      if (!revealable) return
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
