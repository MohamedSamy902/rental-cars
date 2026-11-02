import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

type Options<T extends string> = {
  /** Query key — default `tab` */
  key?: string
  /** Allowed values; invalid/missing falls back to `defaultValue` */
  allowed: readonly T[]
  defaultValue: T
}

/**
 * Persist segmented UI state (detail tabs / FilterBar chips) in the URL
 * so refresh and shared links restore the same view.
 */
export function useQueryTab<T extends string>({
  key = 'tab',
  allowed,
  defaultValue,
}: Options<T>): [T, (next: string) => void] {
  const [params, setParams] = useSearchParams()
  const allowedSet = useMemo(() => new Set<string>(allowed), [allowed])

  const value = useMemo(() => {
    const raw = params.get(key)
    if (raw && allowedSet.has(raw)) return raw as T
    return defaultValue
  }, [params, key, allowedSet, defaultValue])

  const setValue = useCallback((next: string) => {
    const resolved = (allowedSet.has(next) ? next : defaultValue) as T
    setParams((prev) => {
      const sp = new URLSearchParams(prev)
      if (resolved === defaultValue) sp.delete(key)
      else sp.set(key, resolved)
      return sp
    }, { replace: true })
  }, [setParams, key, defaultValue, allowedSet])

  return [value, setValue]
}
