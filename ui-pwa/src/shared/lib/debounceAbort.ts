/** Debounced runner that aborts the previous scheduled/in-flight generation. */
export function debounceAbortable(ms = 250) {
  let timer: ReturnType<typeof setTimeout> | null = null
  let controller: AbortController | null = null

  function cancel() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
    controller?.abort()
    controller = null
  }

  function schedule(fn: (signal: AbortSignal) => void | Promise<void>) {
    cancel()
    controller = new AbortController()
    const { signal } = controller
    timer = setTimeout(() => {
      timer = null
      void fn(signal)
    }, ms)
  }

  return { schedule, cancel }
}
