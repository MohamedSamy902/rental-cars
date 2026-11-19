/** Logical A4 content width used by rental/invoice shells (mm → CSS px at 96dpi). */
export const PRINT_PAPER_WIDTH_PX = 756

const fittingFrames = new WeakSet<HTMLIFrameElement>()

/**
 * Scale an in-app print preview iframe so the full paper fits the frame width
 * and the iframe height matches the scaled document — no clipped edges.
 *
 * App is RTL: transform-origin is `top right` so the scaled paper sits flush
 * on the inline-start edge. Avoids CSS `zoom` (bad height on mobile WebKit).
 */
export function fitPrintPreviewIframe(
  iframe: HTMLIFrameElement,
  setIframeHeight: (height: string) => void,
): void {
  if (fittingFrames.has(iframe)) return
  const doc = iframe.contentDocument
  if (!doc?.documentElement) return
  fittingFrames.add(iframe)

  try {
    const root = (doc.querySelector('.rpm-preview-root') as HTMLElement | null) || doc.body
    const paper = (doc.querySelector(
      '.rpm-paper-v5, .rpm-paper-v4, .rpm-paper-v3, .rpm-paper-v2, .rpm-invoice-v1, .rpm-legal-contract',
    ) as HTMLElement | null) || root

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
    doc.body.style.width = '100%'
    doc.body.style.minHeight = '0'
    doc.body.style.overflow = 'visible'
    doc.documentElement.style.overflow = 'visible'
    doc.documentElement.style.height = 'auto'

    const frameWidth = Math.max(iframe.clientWidth - 2, 1)

    const prevHeight = iframe.style.height
    iframe.style.height = '20000px'
    void doc.body.offsetHeight

    const naturalWidth = Math.ceil(Math.max(
      paper.scrollWidth,
      paper.offsetWidth,
      root.scrollWidth,
      PRINT_PAPER_WIDTH_PX,
    ))
    const naturalHeight = Math.ceil(Math.max(
      paper.scrollHeight,
      root.scrollHeight,
      320,
    ))

    iframe.style.height = prevHeight

    const scale = Math.min(1, frameWidth / Math.max(naturalWidth, 1))
    const scaledHeight = Math.ceil(naturalHeight * scale) + 20

    doc.body.style.setProperty('--rpm-preview-scale', String(scale))
    // Natural paper width; scale from the RTL start edge so nothing is clipped.
    root.style.width = `${naturalWidth}px`
    root.style.marginInline = '0'
    root.style.marginInlineStart = '0'
    root.style.marginInlineEnd = 'auto'
    root.style.transformOrigin = 'top right'
    root.style.transform = `scale(${scale})`

    doc.body.style.width = '100%'
    doc.body.style.height = `${scaledHeight}px`
    doc.body.style.overflow = 'hidden'
    doc.documentElement.style.overflow = 'hidden'
    doc.documentElement.style.height = `${scaledHeight}px`

    setIframeHeight(`${scaledHeight}px`)
  } finally {
    window.requestAnimationFrame(() => {
      fittingFrames.delete(iframe)
    })
  }
}
