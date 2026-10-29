import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { useDragControls, type PanInfo } from 'framer-motion'

const MOBILE_QUERY = '(max-width: 980px)'
const DISMISS_OFFSET = 120
const FLICK_VELOCITY = 700
const FLICK_MIN_OFFSET = 56

export function useIsMobile() {
  const [mobile, setMobile] = useState(() =>
    typeof window !== 'undefined' ? window.matchMedia(MOBILE_QUERY).matches : false,
  )
  useEffect(() => {
    const mq = window.matchMedia(MOBILE_QUERY)
    const sync = () => setMobile(mq.matches)
    sync()
    mq.addEventListener('change', sync)
    return () => mq.removeEventListener('change', sync)
  }, [])
  return mobile
}

/**
 * Bottom-sheet swipe-to-dismiss. Drag starts only from the grab zone (handle/header)
 * so the sheet body keeps native scrolling and never chains into browser pull-to-refresh.
 */
export function useSheetDrag(onDismiss: () => void, enabled: boolean) {
  const controls = useDragControls()
  const dismissRef = useRef(onDismiss)
  useEffect(() => {
    dismissRef.current = onDismiss
  }, [onDismiss])

  const startDrag = (e: ReactPointerEvent<HTMLElement>) => {
    if (!enabled) return
    const target = e.target as HTMLElement
    if (target.closest('button, a, input, select, textarea, [data-no-drag]')) return
    controls.start(e)
  }

  const dragProps = enabled
    ? {
        drag: 'y' as const,
        dragControls: controls,
        dragListener: false,
        dragConstraints: { top: 0, bottom: 0 },
        dragElastic: { top: 0, bottom: 0.9 },
        dragMomentum: false,
        onDragEnd: (_: unknown, info: PanInfo) => {
          const flicked = info.velocity.y > FLICK_VELOCITY && info.offset.y > FLICK_MIN_OFFSET
          if (info.offset.y > DISMISS_OFFSET || flicked) {
            dismissRef.current()
          }
        },
      }
    : {}

  return { startDrag, dragProps }
}
