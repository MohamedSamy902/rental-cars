/** Logical A4 content width used by rental/invoice shells (mm → CSS px at 96dpi). */
export const PRINT_PAPER_WIDTH_PX = 756

const fittingFrames = new WeakSet<HTMLIFrameElement>()

/**
 * Scale an in-app print preview iframe so the full paper fits the frame width
 * and the iframe height matches the scaled document — no clipped bottom.
 *
 * Avoids CSS `zoom` (under-measures height on mobile WebKit). Uses transform
 * scale with an oversized temporary iframe so scrollHeight is complete.
 */
export function fitPrintPreviewIframe(
  iframe: HTMLIFrameElement,
  setIframeHeight: (height: string) => void,
): void {
  if (fittingFrames.has(iframe)) return
  const doc = iframe.contentDocument
  if (!doc?.documentElement) return
  fittingFrames.add(iframe)

  const root = (doc.querySelector('.rpm-preview-root') as HTMLElement | null) || doc.body
  const paper = (doc.querySelector(
    '.rpm-paper-v3, .rpm-paper-v2, .rpm-invoice-v1, .rpm-legal-contract, .rpm-preview-root',
  ) as HTMLElement | null) || root

  // Reset any prior fit so we measure the natural document.
  root.style.transform = ''
  root.style.transformOrigin = ''
  root.style.width = ''
  root.style.marginInline = ''
  root.style.height = ''
  doc.body.style.removeProperty('--rpm-preview-scale')
  doc.body.style.removeProperty('zoom')
  ;(doc.documentElement.style as CSSStyleDeclaration & { zoom?: string }).zoom = ''
  ;(doc.body.style as CSSStyleDeclaration & { zoom?: string }).zoom = ''
  doc.body.style.height = 'auto'
  doc.body.style.width = 'auto'
  doc.body.style.minHeight = '0'
  doc.body.style.overflow = 'visible'
  doc.documentElement.style.overflow = 'visible'
  doc.documentElement.style.height = 'auto'

  const frameWidth = Math.max(iframe.clientWidth - 2, 1)

  // Temporarily grow the iframe so overflow:hidden print shells still report full scrollHeight.
  const prevHeight = iframe.style.height
  iframe.style.height = '20000px'

  const naturalWidth = Math.ceil(Math.max(
    paper.scrollWidth,
    paper.offsetWidth,
    root.scrollWidth,
    doc.body.scrollWidth,
    doc.documentElement.scrollWidth,
    PRINT_PAPER_WIDTH_PX,
  ))
  const naturalHeight = Math.ceil(Math.max(
    paper.scrollHeight,
    root.scrollHeight,
    doc.body.scrollHeight,
    doc.documentElement.scrollHeight,
    320,
  ))

  iframe.style.height = prevHeight

  const scale = Math.min(1, frameWidth / Math.max(naturalWidth, 1))
  const scaledHeight = Math.ceil(naturalHeight * scale) + 16
  const scaledWidth = Math.ceil(naturalWidth * scale)

  doc.body.style.setProperty('--rpm-preview-scale', String(scale))
  root.style.width = `${naturalWidth}px`
  root.style.marginInline = 'auto'
  root.style.transformOrigin = 'top center'
  root.style.transform = `scale(${scale})`
  // Wrapper box matches the visual (scaled) size so the iframe does not clip.
  doc.body.style.width = `${scaledWidth}px`
  doc.body.style.height = `${scaledHeight}px`
  doc.body.style.overflow = 'hidden'
  doc.documentElement.style.overflow = 'hidden'
  doc.documentElement.style.height = `${scaledHeight}px`

  setIframeHeight(`${scaledHeight}px`)
  // Release after layout settles so ResizeObserver re-entry is skipped mid-fit.
  window.requestAnimationFrame(() => {
    fittingFrames.delete(iframe)
  })
}
