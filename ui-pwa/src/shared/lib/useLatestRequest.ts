import { useRef } from 'react'

/** Ignore a list response that lost the race to a newer filter or page. */
export function useLatestRequest() {
  const seq = useRef(0)
  return () => {
    const token = ++seq.current
    return () => token === seq.current
  }
}
