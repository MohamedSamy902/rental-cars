/**
 * Popup-free HTML printing via a hidden iframe.
 *
 * Avoids Brave/Chrome `window.open(..., 'noopener')` / async-boundary
 * failures that orphan an `about:blank` tab and falsely report
 * "اسمح بالنوافذ المنبثقة للطباعة".
 *
 * The iframe must keep a real A4 layout box (not 0×0). Zero-size frames
 * often print blank pages in Chromium while still opening the print dialog.
 */

const SETTLE_MS = 180
const FALLBACK_CLEANUP_MS = 60_000
/** CSS px ≈ A4 @ 96dpi — enough for layout/paint before print. */
const PRINT_FRAME_WIDTH_PX = 794
const PRINT_FRAME_HEIGHT_PX = 1123

function applyHiddenFrameStyles(frame: HTMLIFrameElement) {
  frame.setAttribute('aria-hidden', 'true')
  frame.setAttribute('title', 'print-frame')
  // Real A4 box for layout/paint, clipped in-place so it never widens the page.
  frame.style.position = 'fixed'
  frame.style.insetInlineStart = '0'
  frame.style.top = '0'
  frame.style.width = `${PRINT_FRAME_WIDTH_PX}px`
  frame.style.height = `${PRINT_FRAME_HEIGHT_PX}px`
  frame.style.border = '0'
  frame.style.opacity = '0'
  frame.style.pointerEvents = 'none'
  frame.style.visibility = 'hidden'
  frame.style.zIndex = '-1'
  frame.style.clipPath = 'inset(50%)'
  frame.style.overflow = 'hidden'
}

function cleanupFrame(frame: HTMLIFrameElement) {
  try {
    frame.remove()
  } catch {
    /* already detached */
  }
}

/**
 * Inject `html` into a hidden iframe, wait for load + a short settle window
 * (fonts / images / QR canvases), then trigger the browser print dialog.
 */
export function printHtmlDocument(html: string): void {
  if (typeof document === 'undefined') return

  const frame = document.createElement('iframe')
  applyHiddenFrameStyles(frame)
  document.body.appendChild(frame)

  const win = frame.contentWindow
  const doc = frame.contentDocument || win?.document
  if (!win || !doc) {
    cleanupFrame(frame)
    throw new Error('تعذّر تجهيز إطار الطباعة.')
  }

  let cleaned = false
  const finish = () => {
    if (cleaned) return
    cleaned = true
    win.removeEventListener('afterprint', finish)
    cleanupFrame(frame)
  }

  const runPrint = () => {
    try {
      win.focus()
      win.print()
    } finally {
      // Prefer afterprint; fall back so the iframe never leaks.
      win.addEventListener('afterprint', finish)
      window.setTimeout(finish, FALLBACK_CLEANUP_MS)
    }
  }

  const waitForImages = (): Promise<void> => {
    const images = Array.from(doc.images || [])
    if (!images.length) return Promise.resolve()
    return Promise.all(
      images.map(
        (img) =>
          new Promise<void>((resolve) => {
            if (img.complete) {
              resolve()
              return
            }
            img.addEventListener('load', () => resolve(), { once: true })
            img.addEventListener('error', () => resolve(), { once: true })
          }),
      ),
    ).then(() => undefined)
  }

  const schedulePrint = () => {
    void waitForImages().finally(() => {
      window.setTimeout(runPrint, SETTLE_MS)
    })
  }

  doc.open()
  doc.write(html)
  doc.close()

  if (doc.readyState === 'complete') {
    schedulePrint()
  } else {
    frame.addEventListener('load', schedulePrint, { once: true })
    // Safety if load never fires after document.write/close.
    window.setTimeout(() => {
      if (!cleaned && frame.isConnected) schedulePrint()
    }, SETTLE_MS + 50)
  }
}

/**
 * Print a live DOM subtree (e.g. vehicle dossier) without `window.open`.
 * Copies active stylesheets so `@media print` rules still apply.
 */
export function printDomSubtree(root: Element, title = 'طباعة'): void {
  const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
    .map((node) => {
      if (node instanceof HTMLLinkElement) {
        const href = node.href || node.getAttribute('href') || ''
        return href ? `<link rel="stylesheet" href="${href}">` : ''
      }
      return node.outerHTML
    })
    .filter(Boolean)
    .join('\n')

  const lang = document.documentElement.lang || 'ar'
  const dir = document.documentElement.getAttribute('dir') || 'rtl'
  const bodyClass = document.body.className || ''

  printHtmlDocument(`<!doctype html><html lang="${lang}" dir="${dir}"><head><meta charset="utf-8"><title>${title.replace(/[<>&]/g, '')}</title>
${styles}
<style>
  .vd-no-print, .rpm-shell__nav, .rpm-shell__bottom, .rpm-shell__aside, .rpm-toast-host { display: none !important; }
  body { background: #fff !important; }
</style>
</head><body class="${bodyClass}">${root.outerHTML}</body></html>`)
}
