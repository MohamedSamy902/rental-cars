import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import {
  readScrollY,
  recallScroll,
  rememberScroll,
  rememberScrollFromEvent,
  routeScrollKey,
  writeScrollY,
} from './scrollMemory'

function rememberLeaving(key: string, y: number) {
  // pinScrollForNavigation freezes the key — rememberScroll no-ops while frozen.
  const prev = recallScroll(key) ?? 0
  if (y <= 8 && prev > 80) return
  if (prev > 80 && Math.abs(y - prev) > 400) return
  rememberScroll(key, y)
}

/**
 * Persist list scroll across list → detail → back (AppShell remounts routes).
 * Saves continuously while scrolling; restores after route change.
 */
export function useRouteScrollMemory() {
  const location = useLocation()
  const key = routeScrollKey(location.pathname, location.search)
  const prevKeyRef = useRef(key)
  const cancelRestoreRef = useRef(false)
  const restoringRef = useRef(false)

  useEffect(() => {
    const onScroll = () => {
      if (restoringRef.current) return
      rememberScrollFromEvent(key, readScrollY())
    }
    window.addEventListener('scroll', onScroll, { passive: true })
    const main = document.querySelector('.shell-main')
    main?.addEventListener('scroll', onScroll, { passive: true })
    return () => {
      if (!restoringRef.current) rememberLeaving(key, readScrollY())
      window.removeEventListener('scroll', onScroll)
      main?.removeEventListener('scroll', onScroll)
    }
  }, [key])

  useEffect(() => {
    const prevKey = prevKeyRef.current
    if (prevKey !== key) {
      rememberLeaving(prevKey, readScrollY())
      prevKeyRef.current = key
    }

    const saved = recallScroll(key)
    cancelRestoreRef.current = false

    if (saved == null || saved <= 0) {
      writeScrollY(0)
      return
    }

    let cancelled = false
    restoringRef.current = true

    const apply = () => {
      if (cancelled || cancelRestoreRef.current) return
      writeScrollY(saved)
    }

    apply()
    const raf = requestAnimationFrame(apply)
    const timers = [50, 150, 300, 500, 800, 1200, 1800].map((ms) => window.setTimeout(apply, ms))
    const release = window.setTimeout(() => {
      restoringRef.current = false
    }, 1900)

    const root = document.querySelector('.shell-main') ?? document.body
    const mo = new MutationObserver(() => apply())
    mo.observe(root, { childList: true, subtree: true })

    const onUserGesture = () => {
      cancelRestoreRef.current = true
      restoringRef.current = false
    }
    window.addEventListener('wheel', onUserGesture, { passive: true })
    window.addEventListener('touchstart', onUserGesture, { passive: true })
    window.addEventListener('pointerdown', onUserGesture, { passive: true })

    return () => {
      cancelled = true
      restoringRef.current = false
      cancelAnimationFrame(raf)
      timers.forEach((t) => window.clearTimeout(t))
      window.clearTimeout(release)
      mo.disconnect()
      window.removeEventListener('wheel', onUserGesture)
      window.removeEventListener('touchstart', onUserGesture)
      window.removeEventListener('pointerdown', onUserGesture)
    }
  }, [key])
}
