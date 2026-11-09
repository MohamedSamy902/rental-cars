import { useEffect, useMemo, useState } from 'react'
import { Sheet, Button, TextAreaField, useToast } from '@/shared/ui'
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

/** Open the edit-before-print sheet for a contract document. */
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
  invoice: 'الفاتورة / كشف الحساب',
  receipt: 'إيصال الاستلام والتأمين',
}

/**
 * Preview + optional one-off edit before printing a contract document.
 * Edits apply to this print only and do not change the shared template.
 */
export function ContractPrintSheet({ open, contractId, kind = 'contract', onClose }: Props) {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [payload, setPayload] = useState<PrintPayload | null>(null)
  const [bodyHtml, setBodyHtml] = useState('')
  const [baselineBody, setBaselineBody] = useState('')
  const [showSource, setShowSource] = useState(kind === 'contract')

  useEffect(() => {
    if (!open || !contractId) return
    let cancelled = false
    setLoading(true)
    setPayload(null)
    setBodyHtml('')
    setBaselineBody('')
    setShowSource(kind === 'contract')
    void loadContractPrintPayload(contractId)
      .then((data) => {
        if (cancelled) return
        const full = renderDoc(kind, data)
        const body = extractPrintableBody(full)
        setPayload(data)
        setBodyHtml(body)
        setBaselineBody(body)
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

  const dirty = bodyHtml !== baselineBody
  const previewHtml = payload
    ? wrapEditedPrintHtml(kind, title, bodyHtml)
    : ''

  async function handlePrint() {
    if (!payload || !contractId) return
    setPrinting(true)
    try {
      const html = wrapEditedPrintHtml(kind, title, bodyHtml)
      await commitPrintDocument(contractId, kind, payload, html, { editedBeforePrint: dirty })
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
      title={`طباعة ${kindTitle[kind]}`}
      onClose={onClose}
      closeLabel="إغلاق"
      footer={
        <div className="rpm-actions-row" style={{ flexWrap: 'wrap', gap: '0.5rem' }}>
          <Button
            type="button"
            variant="secondary"
            disabled={loading || !dirty}
            onClick={() => setBodyHtml(baselineBody)}
          >
            استعادة القالب
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>إلغاء</Button>
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
            البيانات تُملأ تلقائياً من العقد. يمكنك تعديل النص لهذا العقد فقط قبل الطباعة — التعديل لا يغيّر القالب العام في الإعدادات.
            {dirty ? ' · تم تعديل النسخة الحالية.' : ''}
          </p>

          <div className="rpm-actions-row" style={{ gap: '0.5rem' }}>
            <Button
              type="button"
              size="sm"
              variant={showSource ? 'secondary' : 'primary'}
              onClick={() => setShowSource(false)}
            >
              معاينة
            </Button>
            <Button
              type="button"
              size="sm"
              variant={showSource ? 'primary' : 'secondary'}
              onClick={() => setShowSource(true)}
            >
              تعديل النص
            </Button>
          </div>

          {showSource ? (
            <TextAreaField
              label="نص المستند (قابل للتعديل لهذه الطباعة فقط)"
              value={bodyHtml}
              onChange={(e) => setBodyHtml(e.target.value)}
              hint="أضف أو احذف أو عدّل أي بند أو بيان لهذا العميل فقط، ثم اضغط طباعة."
              rows={18}
            />
          ) : (
            <div
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                overflow: 'hidden',
                background: '#fff',
                minHeight: 320,
              }}
            >
              <iframe
                title="معاينة الطباعة"
                srcDoc={previewHtml}
                style={{ width: '100%', height: '70vh', border: 0, background: '#fff' }}
              />
            </div>
          )}
        </div>
      )}
    </Sheet>
  )
}
