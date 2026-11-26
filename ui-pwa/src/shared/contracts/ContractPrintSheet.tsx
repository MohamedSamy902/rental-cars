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
import { fitPrintPreviewIframe } from './fitPrintPreview'

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
  receipt: 'إقرار استلام سيارة',
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
        // الفاتورة تُبنى كاملة من الكود الحي — احفظ المستند كما هو لتفادي فقدان الستايل في المعاينة
        setPayload(data)
        setBodyHtml(kind === 'invoice' ? full : extractPrintableBody(full))
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
    ? (
      kind === 'invoice' && (/^<!doctype/i.test(bodyHtml.trim()) || /<html[\s>]/i.test(bodyHtml.trim()))
        ? bodyHtml
        : wrapEditedPrintHtml(kind, title, bodyHtml)
    )
    : ''

  const fitPreviewToFrame = useCallback(() => {
    const iframe = iframeRef.current
    if (!iframe) return
    fitPrintPreviewIframe(iframe, setIframeHeight)
  }, [])

  useEffect(() => {
    if (!previewHtml) return
    const iframe = iframeRef.current
    if (!iframe) return

    let cancelled = false
    const runFit = () => {
      if (!cancelled) fitPreviewToFrame()
    }
    const onLoad = () => {
      runFit()
      window.setTimeout(runFit, 80)
      window.setTimeout(runFit, 250)
      window.setTimeout(runFit, 700)
    }
    iframe.addEventListener('load', onLoad)
    if (iframe.contentDocument?.readyState === 'complete') onLoad()

    const ro = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(() => runFit())
      : null
    ro?.observe(iframe)

    return () => {
      cancelled = true
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
            {kind === 'contract'
              ? 'الطباعة: العقد (وش) ثم إقرار الاستلام (ضهر) ثم نسخة إضافية من العقد فقط بلا إقرار. لتعديل القالب العام استخدم الإعدادات.'
              : 'معاينة المستند بعد تعبئة بيانات العقد. لتعديل القالب العام استخدم الإعدادات.'}
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
