import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Sheet, Button, useToast } from '@/shared/ui'
import { type ApiError } from '@/shared/api/client'
import {
  commitPrintDocument,
  extractPrintableBody,
  loadContractPrintPayload,
  renderDoc,
  wrapEditedPrintHtml,
  type PrintDocKind,
  type PrintPayload,
} from './printContract'

/** Logical A4 content width used by rental/invoice shells (mm → CSS px at 96dpi). */
const PAPER_WIDTH_PX = 756

/** Open the preview-before-print sheet for a contract document. */
export function useContractPrint() {
  const [target, setTarget] = useState<{ id: number; kind: PrintDocKind } | null>(null)
  const openPrint = (id: number, kind: PrintDocKind = 'contract') => setTarget({ id, kind })
  const printSheet = (
    <ContractPrintSheet
      open={Boolean(target)}
      contractId={target?.id ?? null}
      kind={target?.kind ?? 'contract'}
      onClose={() => setTarget(null)}
    />
  )
  return { openPrint, printSheet }
}

type Props = {
  open: boolean
  contractId: number | null
  kind?: PrintDocKind
  onClose: () => void
}

const kindTitle: Record<PrintDocKind, string> = {
  contract: 'عقد الإيجار',
  invoice: 'الفاتورة',
  receipt: 'إقرار الاستلام والتسليم',
}

/**
 * Preview before printing a contract document.
 * Raw HTML source editing is not shown to operators — templates are edited in settings only.
 */
export function ContractPrintSheet({ open, contractId, kind = 'contract', onClose }: Props) {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [payload, setPayload] = useState<PrintPayload | null>(null)
  const [bodyHtml, setBodyHtml] = useState('')
  const [iframeHeight, setIframeHeight] = useState('70vh')
  const iframeRef = useRef<HTMLIFrameElement | null>(null)

  useEffect(() => {
    if (!open || !contractId) return
    let cancelled = false
    setLoading(true)
    setPayload(null)
    setBodyHtml('')
    setIframeHeight('70vh')
    void loadContractPrintPayload(contractId)
      .then((data) => {
        if (cancelled) return
        const full = renderDoc(kind, data)
        const body = extractPrintableBody(full)
        setPayload(data)
        setBodyHtml(body)
      })
      .catch((e) => {
        if (!cancelled) toast.error((e as ApiError).message || 'تعذّر تجهيز مستند الطباعة.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, contractId, kind, toast])

  const title = useMemo(() => {
    const number = payload?.contract?.number || ''
    return number ? `${kindTitle[kind]} · ${number}` : kindTitle[kind]
  }, [kind, payload])

  const previewHtml = payload
    ? wrapEditedPrintHtml(kind, title, bodyHtml)
    : ''

  const fitPreviewToFrame = useCallback(() => {
    const iframe = iframeRef.current
    const doc = iframe?.contentDocument
    if (!iframe || !doc?.documentElement) return

    const root = (doc.querySelector('.rpm-preview-root') as HTMLElement | null) || doc.body
    const paper = (doc.querySelector('.rpm-paper-v3, .rpm-paper-v2, .rpm-invoice-v1, .rpm-legal-contract') as HTMLElement | null)
      || root

    // Reset measurement styles.
    root.style.transform = ''
    root.style.width = ''
    root.style.marginInline = ''
    doc.body.style.removeProperty('--rpm-preview-scale')
    doc.body.style.removeProperty('zoom')
    doc.body.style.height = 'auto'
    doc.body.style.width = 'auto'
    doc.body.style.overflow = 'visible'
    doc.documentElement.style.overflow = 'visible'

    const frameWidth = Math.max(iframe.clientWidth - 2, 1)
    const naturalWidth = Math.ceil(Math.max(
      paper.scrollWidth,
      paper.offsetWidth,
      root.scrollWidth,
      PAPER_WIDTH_PX,
    ))
    const naturalHeight = Math.ceil(Math.max(
      root.scrollHeight,
      paper.scrollHeight,
      doc.body.scrollHeight,
      320,
    ))
    const scale = Math.min(1, frameWidth / naturalWidth)

    // Prefer zoom when available — it shrinks layout box (no clipped transform leftovers).
    const supportsZoom = typeof (doc.body.style as CSSStyleDeclaration & { zoom?: string }).zoom === 'string'
      || 'zoom' in doc.body.style

    if (supportsZoom) {
      // zoom shrinks the visual size; keep layout height unscaled then size the iframe to the visual.
      ;(doc.body.style as CSSStyleDeclaration & { zoom?: string }).zoom = String(scale)
      doc.body.style.height = `${naturalHeight}px`
      doc.body.style.width = '100%'
      doc.body.style.overflow = 'hidden'
      doc.documentElement.style.overflow = 'hidden'
      setIframeHeight(`${Math.ceil(naturalHeight * scale) + 12}px`)
      return
    }

    doc.body.style.setProperty('--rpm-preview-scale', String(scale))
    root.style.width = `${naturalWidth}px`
    root.style.marginInline = 'auto'
    root.style.transformOrigin = 'top center'
    root.style.transform = `scale(${scale})`
    const scaledHeight = Math.ceil(naturalHeight * scale) + 12
    const scaledWidth = Math.ceil(naturalWidth * scale)
    doc.body.style.width = `${scaledWidth}px`
    doc.body.style.height = `${scaledHeight}px`
    doc.body.style.overflow = 'hidden'
    doc.documentElement.style.overflow = 'hidden'
    setIframeHeight(`${scaledHeight}px`)
  }, [])

  useEffect(() => {
    if (!previewHtml) return
    const iframe = iframeRef.current
    if (!iframe) return

    const onLoad = () => {
      fitPreviewToFrame()
      window.setTimeout(fitPreviewToFrame, 120)
      window.setTimeout(fitPreviewToFrame, 450)
    }
    iframe.addEventListener('load', onLoad)

    const ro = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(() => fitPreviewToFrame())
      : null
    ro?.observe(iframe)

    return () => {
      iframe.removeEventListener('load', onLoad)
      ro?.disconnect()
    }
  }, [previewHtml, fitPreviewToFrame])

  async function handlePrint() {
    if (!payload || !contractId) return
    setPrinting(true)
    try {
      const html = wrapEditedPrintHtml(kind, title, bodyHtml)
      await commitPrintDocument(contractId, kind, payload, html, { editedBeforePrint: false })
      onClose()
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّرت الطباعة.')
    } finally {
      setPrinting(false)
    }
  }

  return (
    <Sheet
      open={open}
      title={`معاينة ${kindTitle[kind]}`}
      onClose={onClose}
      closeLabel="إغلاق"
      footer={
        <div className="rpm-actions-row" style={{ flexWrap: 'wrap', gap: '0.5rem' }}>
          <Button type="button" variant="secondary" onClick={onClose}>إغلاق</Button>
          <Button type="button" loading={printing} disabled={loading || !payload} onClick={() => void handlePrint()}>
            طباعة
          </Button>
        </div>
      }
    >
      {loading ? (
        <p className="muted">جاري تجهيز المستند وتعبئة البيانات…</p>
      ) : !payload ? (
        <p className="muted">تعذّر تحميل بيانات الطباعة.</p>
      ) : (
        <div className="rpm-stack" style={{ gap: '0.85rem' }}>
          <p className="muted" style={{ margin: 0 }}>
            معاينة المستند بعد تعبئة بيانات العقد. لتعديل القالب العام استخدم الإعدادات.
          </p>

          <div className="rpm-print-preview">
            <iframe
              ref={iframeRef}
              title="معاينة الطباعة"
              srcDoc={previewHtml}
              style={{
                width: '100%',
                height: iframeHeight,
                minHeight: 280,
                border: 0,
                background: '#fff',
                display: 'block',
              }}
            />
          </div>
        </div>
      )}
    </Sheet>
  )
}
