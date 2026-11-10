import { useEffect, useMemo, useState } from 'react'
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
  receipt: 'إيصال الاستلام والتأمين',
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

  useEffect(() => {
    if (!open || !contractId) return
    let cancelled = false
    setLoading(true)
    setPayload(null)
    setBodyHtml('')
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

          <div
            className="rpm-print-preview"
            style={{
              border: '1px solid var(--border)',
              borderRadius: 12,
              overflow: 'auto',
              background: '#fff',
              minHeight: 280,
              maxInlineSize: '100%',
              WebkitOverflowScrolling: 'touch',
            }}
          >
            <iframe
              title="معاينة الطباعة"
              srcDoc={previewHtml}
              style={{
                width: '100%',
                minHeight: 'min(70vh, 720px)',
                height: '70vh',
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
