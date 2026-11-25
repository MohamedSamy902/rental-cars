/** Scroll positions keyed by pathname + search (list ↔ detail). Survives remounts via sessionStorage. */

const STORAGE_KEY = 'rpm.routeScroll.v1'

type Store = Record<string, number>

let freezeKey: string | null = null
let freezeUntil = 0

function readStore(): Store {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as Store
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

function writeStore(store: Store): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(store))
  } catch {
    /* private mode / quota — ignore */
  }
}

function isFrozen(key: string): boolean {
  return freezeKey === key && performance.now() < freezeUntil
}

export function routeScrollKey(pathname: string, search = ''): string {
  return `${pathname}${search}`
}

export function rememberScroll(key: string, y: number): void {
  if (!key || y < 0) return
  if (isFrozen(key) && Math.round(y) !== (readStore()[key] ?? -1)) return
  const store = readStore()
  store[key] = Math.round(y)
  writeStore(store)
}

/** Pin scroll right before opening a detail row (ignores transition spikes). */
export function pinScrollForNavigation(key: string, y: number, holdMs = 2000): void {
  if (!key || y < 0) return
  const store = readStore()
  const prev = store[key] ?? 0
  // Click/scrollIntoView can jump the offset — keep the last steady list position.
  const pinned = prev > 80 && Math.abs(y - prev) > 400 ? prev : Math.round(y)
  store[key] = pinned
  writeStore(store)
  freezeKey = key
  freezeUntil = performance.now() + holdMs
}

export function recallScroll(key: string): number | null {
  if (!key) return null
  const y = readStore()[key]
  return typeof y === 'number' && y >= 0 ? y : null
}

export function forgetScroll(key: string): void {
  const store = readStore()
  delete store[key]
  writeStore(store)
}

/**
 * Update memory from a live scroll event.
 * Ignores a sudden collapse to ~0 while we already had a real offset —
 * that spike is almost always a route transition reset, not the user.
 */
export function rememberScrollFromEvent(key: string, y: number): void {
  if (!key || y < 0) return
  if (isFrozen(key)) return
  const store = readStore()
  const prev = store[key] ?? 0
  if (y <= 8 && prev > 80) return
  store[key] = Math.round(y)
  writeStore(store)
}

/** Prefer the actual scrolling surface (window vs `.shell-main`). */
export function readScrollY(): number {
  const main = document.querySelector('.shell-main') as HTMLElement | null
  const winY = window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0
  if (!main) return winY
  const mainScrolls = main.scrollHeight > main.clientHeight + 8
  if (mainScrolls) return Math.max(main.scrollTop, winY)
  return winY
}

export function writeScrollY(y: number): void {
  const top = Math.max(0, Math.round(y))
  const main = document.querySelector('.shell-main') as HTMLElement | null
  window.scrollTo(0, top)
  document.documentElement.scrollTop = top
  document.body.scrollTop = top
  if (main) main.scrollTop = top
}
