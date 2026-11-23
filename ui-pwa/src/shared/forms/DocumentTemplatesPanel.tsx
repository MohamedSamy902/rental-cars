import {
  forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState,
  type RefObject,
} from 'react'
import { api, apiUpload, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { Button, Sheet, TextField, useToast } from '@/shared/ui'
import { printHtmlDocument } from '@/utils/printHtml'
import { shellOwnerAgreementHtml } from '@/shared/contracts/ownerAgreementPrint'
import { shellRentalContractHtml } from '@/shared/contracts/rentalContractPrint'
import { fitPrintPreviewIframe } from '@/shared/contracts/fitPrintPreview'
import './DocumentTemplatesPanel.css'

export type DocumentTemplatesPanelHandle = {
  /** هل فيه تعديلات قالب لم تُنشر بعد؟ */
  hasDirty: () => boolean
  /** حفظ كل القوالب المعدَّلة — يُستدعى من زر حفظ صفحة الإعدادات. */
  flushDirty: () => Promise<{ saved: number; failed: number }>
}

type TemplateType = 'contract' | 'handover_receipt' | 'owner_agreement' | 'custody_agreement'

const ALL_TYPES: TemplateType[] = ['contract', 'handover_receipt', 'owner_agreement', 'custody_agreement']

type Placeholder = {
  key: string
  aliases: string[]
  label: string
  sample: string
  group?: string
}

type TemplateRow = {
  id: number
  type: TemplateType
  title: string
  content: string
  format: string
  version: number
  is_active: boolean
  storage_path?: string | null
}

const TYPE_META: Record<TemplateType, { title: string; hint: string }> = {
  contract: {
    title: 'عقد إيجار سيارة (نموذج الشركة)',
    hint: 'عدّل البنود والنصوص كما تظهر في الطباعة. الحقول الجاهزة بالأسفل تُدرج بيانات العقد تلقائياً.',
  },
  handover_receipt: {
    title: 'إقرار استلام سيارة (ظهر العقد)',
    hint: 'يُطبع تلقائياً في الصفحة ٢ مع العقد (ظهر الورقة) بنفس التصميم والألوان.',
  },
  owner_agreement: {
    title: 'عقد إدارة وتشغيل مركبة (مالك ↔ شركة)',
    hint: 'يُملأ تلقائياً ببيانات الشركة والمالك والعربية عند الحفظ/الطباعة.',
  },
  custody_agreement: {
    title: 'قالب عقد الاعتماد',
    hint: 'نسخ صادرة ثابتة من ملف العربية (شركة + مالك + عربية + مدة).',
  },
}

const GROUP_ORDER = ['الشركة', 'العميل', 'العربية', 'العقد', 'التأمين', 'المالك', 'أخرى']

/** عناصر زخرفية/ترويسة تُطوى في المحرر البصري حتى لا تعطل الكتابة. */
const DECOR_SELECTORS = [
  'header.rpm-lc-head',
  'header.print-head',
  '.rpm-lc-ribbon',
  '.rpm-lc-stamp',
  '.rpm-lc-float-ico',
  '.rpm-ack2-seal-top-wrap',
  '.rpm-ack2-finger-block',
  '.page-break',
  'svg',
  'img',
] as const

function shellPreview(type: TemplateType, title: string, body: string) {
  if (type === 'owner_agreement') {
    return shellOwnerAgreementHtml(body, title)
  }
  if (type === 'contract' || type === 'handover_receipt') {
    return shellRentalContractHtml(body, title)
  }
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${title}</title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body{font-family:'Cairo','IBM Plex Sans Arabic',Tahoma,sans-serif;margin:0;padding:16px;color:#0f172a;background:#fff}
  @media screen{
    html,body{overflow:hidden}
    .rpm-preview-root{transform:scale(var(--rpm-preview-scale,1));transform-origin:top center;width:100%}
  }
  @media print{
    html,body{overflow:visible!important}
    .rpm-preview-root{transform:none!important;width:100%!important}
  }
</style></head><body><div class="rpm-preview-root">${body}</div></body></html>`
}

function fillSamplePlaceholders(content: string, placeholders: Placeholder[]): string {
  const sampleMap: Record<string, string> = {}
  for (const p of placeholders) {
    sampleMap[p.key] = p.sample
    for (const a of p.aliases) sampleMap[a] = p.sample
  }
  return content.replace(/\{\{\s*([a-zA-Z0-9_\u0600-\u06FF]+)\s*\}\}/g, (_, key: string) => {
    const v = sampleMap[key]
    return v != null
      ? String(v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
      : `{{${key}}}`
  })
}

function TemplatePreviewSheet({
  open,
  title,
  html,
  onClose,
}: {
  open: boolean
  title: string
  html: string
  onClose: () => void
}) {
  const [iframeHeight, setIframeHeight] = useState('70vh')
  const [printing, setPrinting] = useState(false)
  const iframeRef = useRef<HTMLIFrameElement | null>(null)

  const fitPreviewToFrame = useCallback(() => {
    const iframe = iframeRef.current
    if (!iframe) return
    fitPrintPreviewIframe(iframe, setIframeHeight)
  }, [])

  useEffect(() => {
    if (!open || !html) {
      setIframeHeight('70vh')
      return
    }
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
    // srcDoc may already be complete when the effect runs.
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
  }, [open, html, fitPreviewToFrame])

  function handlePrint() {
    if (!html) return
    setPrinting(true)
    try {
      printHtmlDocument(html)
    } finally {
      window.setTimeout(() => setPrinting(false), 400)
    }
  }

  return (
    <Sheet
      open={open}
      title={`معاينة ${title}`}
      onClose={onClose}
      closeLabel="إغلاق"
      footer={(
        <div className="rpm-actions-row" style={{ flexWrap: 'wrap', gap: '0.5rem' }}>
          <Button type="button" variant="secondary" onClick={onClose}>إغلاق</Button>
          <Button type="button" loading={printing} disabled={!html} onClick={handlePrint}>
            طباعة
          </Button>
        </div>
      )}
    >
      {!html ? (
        <p className="muted">لا يوجد محتوى للمعاينة.</p>
      ) : (
        <div className="rpm-stack" style={{ gap: '0.85rem' }}>
          <p className="muted" style={{ margin: 0 }}>
            معاينة بالبيانات التجريبية. احفظ القالب قبل طباعة عقود حقيقية.
          </p>
          <div className="rpm-print-preview">
            <iframe
              ref={iframeRef}
              title="معاينة القالب"
              srcDoc={html}
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

function assetLabel(node: HTMLElement): string {
  if (node.matches('header.rpm-lc-head, header.print-head')) return 'ترويسة العقد (محمية)'
  if (node.matches('.rpm-lc-ribbon')) return 'شريط علوي (محمي)'
  if (node.matches('.rpm-lc-stamp')) return 'ختم الشركة (محمي)'
  if (node.matches('.rpm-lc-float-ico')) return 'أيقونة تصميم (محمية)'
  if (node.matches('.rpm-ack2-seal-top-wrap')) return 'ختم إقرار الاستلام (محمي)'
  if (node.matches('.rpm-ack2-finger-block')) return 'موضع البصمة (محمي)'
  if (node.matches('.page-break')) return 'فاصل صفحة (محمي)'
  if (node.matches('img')) return 'صورة / شعار (محمي)'
  if (node.matches('svg')) return 'رسم تصميم (محمي)'
  return 'عنصر تصميم (محمي)'
}

/** يطوي العناصر الزخرفية إلى شارات محمية ويحفظ أصلها لاستعادته عند الحفظ. */
function prepareVisualHtml(html: string, assets: Map<string, string>): string {
  const wrap = document.createElement('div')
  wrap.innerHTML = html?.trim() ? html : '<p><br></p>'
  assets.clear()
  let seq = 0

  for (const sel of DECOR_SELECTORS) {
    const nodes = Array.from(wrap.querySelectorAll(sel))
    for (const node of nodes) {
      if (!(node instanceof HTMLElement)) continue
      if (node.closest('[data-rpm-asset]')) continue
      const id = `a${++seq}`
      assets.set(id, node.outerHTML)
      const chip = document.createElement('span')
      chip.className = 'rpm-template-asset'
      chip.contentEditable = 'false'
      chip.setAttribute('data-rpm-asset', id)
      chip.setAttribute('data-rpm-lock', '1')
      chip.setAttribute('title', 'يظهر في الطباعة كما هو — غير قابل للتعديل هنا')
      chip.textContent = assetLabel(node)
      node.replaceWith(chip)
    }
  }

  wrap.querySelectorAll('table thead, [data-rpm-lock]:not([data-rpm-asset])').forEach((node) => {
    if (node instanceof HTMLElement) {
      node.contentEditable = 'false'
      node.setAttribute('data-rpm-lock', '1')
    }
  })

  return wrap.innerHTML
}

/** يعيد الرسوم/الترويسة الأصلية بدل شارات المحرر قبل الحفظ أو المعاينة. */
function restoreVisualHtml(editedHtml: string, assets: Map<string, string>): string {
  const wrap = document.createElement('div')
  wrap.innerHTML = editedHtml
  wrap.querySelectorAll('[data-rpm-asset]').forEach((node) => {
    const id = node.getAttribute('data-rpm-asset')
    const original = id ? assets.get(id) : null
    if (!original) {
      node.remove()
      return
    }
    const tmp = document.createElement('div')
    tmp.innerHTML = original
    const el = tmp.firstElementChild
    if (el) node.replaceWith(el)
    else node.remove()
  })
  return wrap.innerHTML
}

function runEditorCommand(command: string, value?: string) {
  try {
    document.execCommand(command, false, value)
  } catch {
    /* contentEditable command unsupported in this browser — ignore */
  }
}

function VisualBodyEditor({
  html,
  seed,
  disabled,
  onChange,
  editorRef,
  assetsRef,
}: {
  html: string
  seed: number
  disabled: boolean
  onChange: (nextHtml: string) => void
  editorRef: RefObject<HTMLDivElement | null>
  assetsRef: RefObject<Map<string, string>>
}) {
  useEffect(() => {
    const el = editorRef.current
    const assets = assetsRef.current
    if (!el || !assets) return
    el.innerHTML = prepareVisualHtml(html, assets)
  }, [seed, editorRef, assetsRef]) // eslint-disable-line react-hooks/exhaustive-deps -- seed remounts external content only

  function emitChange() {
    const root = editorRef.current
    const assets = assetsRef.current
    if (!root || !assets) return
    onChange(restoreVisualHtml(root.innerHTML, assets))
  }

  function focusEditor() {
    editorRef.current?.focus()
  }

  return (
    <div className="rpm-template-editor">
      <div className="rpm-template-toolbar" role="toolbar" aria-label="تنسيق النص">
        <button type="button" className="rpm-template-toolbar__btn" disabled={disabled} title="غامق" onMouseDown={(e) => { e.preventDefault(); focusEditor(); runEditorCommand('bold') }}>
          غامق
        </button>
        <button type="button" className="rpm-template-toolbar__btn" disabled={disabled} title="تسطير" onMouseDown={(e) => { e.preventDefault(); focusEditor(); runEditorCommand('underline') }}>
          تسطير
        </button>
        <span className="rpm-template-toolbar__sep" aria-hidden />
        <button type="button" className="rpm-template-toolbar__btn" disabled={disabled} title="قائمة مرقّمة" onMouseDown={(e) => { e.preventDefault(); focusEditor(); runEditorCommand('insertOrderedList') }}>
          ١٬٢٬٣
        </button>
        <button type="button" className="rpm-template-toolbar__btn" disabled={disabled} title="قائمة نقطية" onMouseDown={(e) => { e.preventDefault(); focusEditor(); runEditorCommand('insertUnorderedList') }}>
          • قائمة
        </button>
        <span className="rpm-template-toolbar__sep" aria-hidden />
        <button type="button" className="rpm-template-toolbar__btn" disabled={disabled} title="إزالة التنسيق" onMouseDown={(e) => { e.preventDefault(); focusEditor(); runEditorCommand('removeFormat') }}>
          مسح تنسيق
        </button>
      </div>

      <div
        ref={editorRef}
        className="rpm-template-visual"
        contentEditable={!disabled}
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label="محتوى القالب"
        data-placeholder="اكتب بنود العقد هنا…"
        onInput={emitChange}
        onBlur={emitChange}
      />
    </div>
  )
}

export const DocumentTemplatesPanel = forwardRef<DocumentTemplatesPanelHandle>(function DocumentTemplatesPanel(_props, ref) {
  const toast = useToast()
  const { can, isIntegrator } = useAuth()
  const canEdit = can('settings.update') || isIntegrator
  const [loading, setLoading] = useState(true)
  const [placeholders, setPlaceholders] = useState<Placeholder[]>([])
  const [active, setActive] = useState<Partial<Record<TemplateType, TemplateRow>>>({})
  const [drafts, setDrafts] = useState<Record<TemplateType, { title: string; content: string }>>({
    contract: { title: '', content: '' },
    handover_receipt: { title: '', content: '' },
    owner_agreement: { title: '', content: '' },
    custody_agreement: { title: '', content: '' },
  })
  const [files, setFiles] = useState<Partial<Record<TemplateType, File | null>>>({})
  const [saving, setSaving] = useState<TemplateType | null>(null)
  const [previewing, setPreviewing] = useState<TemplateType | null>(null)
  const [previewDoc, setPreviewDoc] = useState<{ title: string; html: string } | null>(null)
  const [expanded, setExpanded] = useState<Partial<Record<TemplateType, boolean>>>({
    contract: true,
    handover_receipt: true,
  })
  const [codeMode, setCodeMode] = useState<Partial<Record<TemplateType, boolean>>>({})
  const [editorSeed, setEditorSeed] = useState<Record<TemplateType, number>>({
    contract: 0,
    handover_receipt: 0,
    owner_agreement: 0,
    custody_agreement: 0,
  })
  const visualRefs = {
    contract: useRef<HTMLDivElement | null>(null),
    handover_receipt: useRef<HTMLDivElement | null>(null),
    owner_agreement: useRef<HTMLDivElement | null>(null),
    custody_agreement: useRef<HTMLDivElement | null>(null),
  }
  const assetsRefs = {
    contract: useRef<Map<string, string>>(new Map()),
    handover_receipt: useRef<Map<string, string>>(new Map()),
    owner_agreement: useRef<Map<string, string>>(new Map()),
    custody_agreement: useRef<Map<string, string>>(new Map()),
  }
  const textRefs = {
    contract: useRef<HTMLTextAreaElement | null>(null),
    handover_receipt: useRef<HTMLTextAreaElement | null>(null),
    owner_agreement: useRef<HTMLTextAreaElement | null>(null),
    custody_agreement: useRef<HTMLTextAreaElement | null>(null),
  }
  /** آخر محتوى مؤكَّد من المحرر — لا يعتمد على توقيت setState قبل الحفظ. */
  const latestContentRef = useRef<Partial<Record<TemplateType, string>>>({})
  const latestTitleRef = useRef<Partial<Record<TemplateType, string>>>({})
  const dirtyRef = useRef<Partial<Record<TemplateType, boolean>>>({})
  const codeModeRef = useRef(codeMode)
  codeModeRef.current = codeMode
  const draftsRef = useRef(drafts)
  draftsRef.current = drafts
  const filesRef = useRef(files)
  filesRef.current = files

  const groupedPlaceholders = useMemo(() => {
    const map = new Map<string, Placeholder[]>()
    for (const p of placeholders) {
      const g = p.group || 'أخرى'
      if (!map.has(g)) map.set(g, [])
      map.get(g)!.push(p)
    }
    return GROUP_ORDER
      .filter((g) => map.has(g))
      .map((g) => ({ group: g, items: map.get(g)! }))
  }, [placeholders])

  function markDirty(type: TemplateType, content: string, title?: string) {
    dirtyRef.current[type] = true
    latestContentRef.current[type] = content
    if (title !== undefined) latestTitleRef.current[type] = title
  }

  function readVisualContent(type: TemplateType): string {
    const visual = visualRefs[type].current
    const assets = assetsRefs[type].current
    if (visual && assets) return restoreVisualHtml(visual.innerHTML, assets)
    return latestContentRef.current[type] ?? draftsRef.current[type].content
  }

  function resolveContentForSave(type: TemplateType): string {
    if (codeModeRef.current[type]) {
      return (latestContentRef.current[type] ?? draftsRef.current[type].content).trim()
    }
    // فضّل DOM الحي ثم الـ ref ثم المسودة.
    const live = readVisualContent(type).trim()
    if (live) return live
    return (latestContentRef.current[type] ?? draftsRef.current[type].content).trim()
  }

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    void api<{ data: TemplateRow[]; placeholders: Placeholder[] }>('/document-templates?active=1')
      .then((res) => {
        if (cancelled) return
        setPlaceholders(res.placeholders || [])
        const nextActive: Partial<Record<TemplateType, TemplateRow>> = {}
        const nextDrafts: Record<TemplateType, { title: string; content: string }> = {
          contract: { title: TYPE_META.contract.title, content: '' },
          handover_receipt: { title: TYPE_META.handover_receipt.title, content: '' },
          owner_agreement: { title: TYPE_META.owner_agreement.title, content: '' },
          custody_agreement: { title: TYPE_META.custody_agreement.title, content: '' },
        }
        for (const row of res.data || []) {
          if (row.type !== 'contract' && row.type !== 'handover_receipt' && row.type !== 'owner_agreement' && row.type !== 'custody_agreement') continue
          nextActive[row.type] = row
          // لا تمسح مسودة المستخدم إن كان يعدّل أثناء إعادة التحميل.
          if (dirtyRef.current[row.type]) {
            nextDrafts[row.type] = {
              title: latestTitleRef.current[row.type] ?? draftsRef.current[row.type].title ?? row.title,
              content: latestContentRef.current[row.type] ?? draftsRef.current[row.type].content ?? row.content,
            }
          } else {
            nextDrafts[row.type] = { title: row.title, content: row.content }
            latestContentRef.current[row.type] = row.content
            latestTitleRef.current[row.type] = row.title
          }
        }
        setActive(nextActive)
        setDrafts(nextDrafts)
        setEditorSeed((prev) => ({
          contract: dirtyRef.current.contract ? prev.contract : (nextActive.contract?.version ?? 0) + 1,
          handover_receipt: dirtyRef.current.handover_receipt ? prev.handover_receipt : (nextActive.handover_receipt?.version ?? 0) + 1,
          owner_agreement: dirtyRef.current.owner_agreement ? prev.owner_agreement : (nextActive.owner_agreement?.version ?? 0) + 1,
          custody_agreement: dirtyRef.current.custody_agreement ? prev.custody_agreement : (nextActive.custody_agreement?.version ?? 0) + 1,
        }))
      })
      .catch((e) => {
        if (!cancelled) toast.error((e as ApiError).message || 'تعذّر تحميل القوالب.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // تحميل مرة عند الدخول فقط — لا تعتمد على هوية toast حتى لا تُمسَح المسودة.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- mount-only load
  }, [])

  async function save(type: TemplateType, opts?: { quiet?: boolean }): Promise<boolean> {
    if (!canEdit) {
      if (!opts?.quiet) toast.error('لا صلاحية لتعديل قوالب المستندات.')
      return false
    }
    const content = resolveContentForSave(type)
    const title = (latestTitleRef.current[type] ?? draftsRef.current[type].title ?? TYPE_META[type].title).trim()
    if (!content) {
      if (!opts?.quiet) toast.error('محتوى القالب فارغ — عدّل النص ثم احفظ.')
      return false
    }
    latestContentRef.current[type] = content
    latestTitleRef.current[type] = title
    setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content, title } }))
    setSaving(type)
    try {
      const file = filesRef.current[type]
      let row: TemplateRow
      let message: string | undefined
      if (file) {
        const fd = new FormData()
        fd.append('type', type)
        fd.append('title', title)
        fd.append('content', content)
        fd.append('format', 'html')
        fd.append('file', file)
        const json = await apiUpload<{ data: TemplateRow; message?: string }>('/document-templates', fd)
        row = json.data
        message = json.message
      } else {
        const json = await api<{ data: TemplateRow; message?: string }>('/document-templates', {
          method: 'POST',
          json: { type, title, content, format: 'html' },
        })
        row = json.data
        message = json.message
      }

      // تحقق فوري من الثبات بعد الحفظ — لو GET رجّع غير ما نُشر ننبّه بدل نجاح كاذب.
      const verify = await api<{ data: TemplateRow[] }>('/document-templates?active=1').catch(() => null)
      const confirmed = verify?.data?.find((r) => r.type === type)
      if (confirmed && confirmed.content !== row.content) {
        throw new Error('الحفظ لم يثبت على السيرفر — أعد المحاولة.')
      }
      const stuck = confirmed ?? row

      dirtyRef.current[type] = false
      latestContentRef.current[type] = stuck.content
      latestTitleRef.current[type] = stuck.title
      setActive((prev) => ({ ...prev, [type]: stuck }))
      setDrafts((prev) => ({ ...prev, [type]: { title: stuck.title, content: stuck.content } }))
      setEditorSeed((prev) => ({ ...prev, [type]: prev[type] + 1 }))
      setFiles((prev) => ({ ...prev, [type]: null }))
      if (!opts?.quiet) toast.success(message || `تم حفظ القالب (الإصدار ${stuck.version}).`)
      return true
    } catch (e) {
      if (!opts?.quiet) toast.error((e as ApiError).message || 'تعذّر حفظ القالب.')
      return false
    } finally {
      setSaving(null)
    }
  }

  useImperativeHandle(ref, () => ({
    hasDirty: () => ALL_TYPES.some((t) => Boolean(dirtyRef.current[t])),
    flushDirty: async () => {
      let saved = 0
      let failed = 0
      for (const type of ALL_TYPES) {
        if (!dirtyRef.current[type]) continue
        const ok = await save(type, { quiet: true })
        if (ok) saved += 1
        else failed += 1
      }
      return { saved, failed }
    },
  }))

  async function preview(type: TemplateType) {
    setPreviewing(type)
    try {
      // Prefer live editor content; fall back to draft (works even when accordion collapsed).
      const content = (codeMode[type] ? drafts[type].content : readVisualContent(type)).trim()
      if (!content) {
        toast.error('لا يوجد محتوى للمعاينة. افتح القالب أو احفظه أولاً.')
        return
      }
      const rendered = fillSamplePlaceholders(content, placeholders)
      const title = drafts[type].title || TYPE_META[type].title
      setPreviewDoc({ title, html: shellPreview(type, title, rendered) })
      // Server audit/ack — ignore failures (preview is client-side with sample data).
      await api('/document-templates/preview', { method: 'POST', json: { type } }).catch(() => undefined)
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّر معاينة القالب.')
    } finally {
      setPreviewing(null)
    }
  }

  function insertToken(type: TemplateType, token: string, label: string) {
    const snippet = `{{${token}}}`
    if (codeMode[type]) {
      const el = textRefs[type].current
      if (el) {
        const start = el.selectionStart ?? el.value.length
        const end = el.selectionEnd ?? el.value.length
        const next = `${el.value.slice(0, start)}${snippet}${el.value.slice(end)}`
        markDirty(type, next)
        setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: next } }))
        requestAnimationFrame(() => {
          el.focus()
          const pos = start + snippet.length
          el.setSelectionRange(pos, pos)
        })
        toast.success(`أُدرج: ${label}`)
        return
      }
    }

    const root = visualRefs[type].current
    if (root) {
      root.focus()
      const sel = window.getSelection()
      if (sel && sel.rangeCount > 0) {
        const range = sel.getRangeAt(0)
        if (root.contains(range.commonAncestorContainer)) {
          range.deleteContents()
          const node = document.createTextNode(snippet)
          range.insertNode(node)
          range.setStartAfter(node)
          range.collapse(true)
          sel.removeAllRanges()
          sel.addRange(range)
          const nextHtml = restoreVisualHtml(root.innerHTML, assetsRefs[type].current)
          markDirty(type, nextHtml)
          setDrafts((prev) => ({
            ...prev,
            [type]: { ...prev[type], content: nextHtml },
          }))
          toast.success(`أُدرج: ${label}`)
          return
        }
      }
      // لا يوجد مؤشر داخل المحرر — ألحق في النهاية
      const p = document.createElement('p')
      p.textContent = snippet
      root.appendChild(p)
      const nextHtml = restoreVisualHtml(root.innerHTML, assetsRefs[type].current)
      markDirty(type, nextHtml)
      setDrafts((prev) => ({
        ...prev,
        [type]: { ...prev[type], content: nextHtml },
      }))
      toast.success(`أُدرج في النص: ${label}`)
      return
    }

    const fallback = `${draftsRef.current[type].content}${draftsRef.current[type].content && !draftsRef.current[type].content.endsWith('\n') ? ' ' : ''}${snippet}`
    markDirty(type, fallback)
    setDrafts((prev) => ({
      ...prev,
      [type]: { ...prev[type], content: fallback },
    }))
    setEditorSeed((prev) => ({ ...prev, [type]: prev[type] + 1 }))
    toast.success(`أُدرج في النص: ${label}`)
  }

  if (loading) {
    return <p className="muted">جاري تحميل قوالب المستندات…</p>
  }

  return (
    <div className="rpm-stack" style={{ gap: '1rem' }}>
      <TemplatePreviewSheet
        open={Boolean(previewDoc)}
        title={previewDoc?.title || ''}
        html={previewDoc?.html || ''}
        onClose={() => setPreviewDoc(null)}
      />
      <p className="rpm-template-intro">
        عدّل النص ثم اضغط «حفظ ونشر» بجانب القالب — أو «حفظ الكل» أعلى الصفحة ليثبّت القوالب المعدَّلة مع إعدادات الشركة.
        الرسوم والترويسة والختم شارات محمية. «معاينة» تعرض الشكل النهائي قبل النشر.
      </p>

      {ALL_TYPES.map((type) => {
        const meta = TYPE_META[type]
        const row = active[type]
        const isOpen = Boolean(expanded[type])
        const showCode = Boolean(codeMode[type])
        return (
          <section key={type} className="rpm-panel">
            <div className="rpm-panel__head" style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center', justifyContent: 'space-between' }}>
              <button
                type="button"
                onClick={() => setExpanded((prev) => ({ ...prev, [type]: !prev[type] }))}
                aria-expanded={isOpen}
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'flex-start',
                  gap: '0.25rem',
                  background: 'transparent',
                  border: 0,
                  padding: 0,
                  cursor: 'pointer',
                  textAlign: 'start',
                  flex: '1 1 12rem',
                  color: 'inherit',
                  font: 'inherit',
                }}
              >
                <h3 style={{ margin: 0 }}>
                  <span aria-hidden style={{ marginInlineEnd: '0.4rem' }}>{isOpen ? '▾' : '▸'}</span>
                  {meta.title}
                </h3>
                <p className="muted" style={{ margin: 0, fontSize: '0.82rem' }}>
                  {meta.hint}
                  {row ? ` · الإصدار النشط: ${row.version}` : ''}
                </p>
              </button>
              <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap' }}>
                <Button size="sm" variant="secondary" loading={previewing === type} onClick={() => void preview(type)}>
                  معاينة
                </Button>
                <Button size="sm" loading={saving === type} disabled={!canEdit || !isOpen} onClick={() => void save(type)}>
                  حفظ ونشر
                </Button>
              </div>
            </div>

            {isOpen ? (
              <div className="rpm-stack" style={{ gap: '0.85rem', marginBlockStart: '0.75rem' }}>
                <TextField
                  label="عنوان القالب"
                  value={drafts[type].title}
                  disabled={!canEdit}
                  onChange={(e) => {
                    const title = e.target.value
                    latestTitleRef.current[type] = title
                    dirtyRef.current[type] = true
                    setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], title } }))
                  }}
                />

                <div className="rpm-field">
                  <span className="rpm-field__label">حقول تُملأ تلقائياً عند الطباعة</span>
                  <span className="rpm-field__hint">
                    ضع المؤشر داخل النص ثم اضغط الاسم. عند الطباعة يتحول إلى بيانات العقد الحقيقية.
                  </span>
                  <div className="rpm-template-vars">
                    {groupedPlaceholders.map(({ group, items }) => (
                      <div key={group} className="rpm-template-vars__group">
                        <strong className="rpm-template-vars__group-title">{group}</strong>
                        <div className="rpm-template-vars__chips">
                          {items.map((p) => (
                            <button
                              key={p.key}
                              type="button"
                              className="rpm-template-vars__chip"
                              title={`مثال عند الطباعة: ${p.sample}`}
                              disabled={!canEdit}
                              onClick={() => insertToken(type, p.aliases[0] || p.key, p.label)}
                            >
                              <span className="rpm-template-vars__chip-label">{p.label}</span>
                              <span className="rpm-template-vars__chip-sample">مثال: {p.sample}</span>
                            </button>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="rpm-field">
                  <div className="rpm-template-editor-head">
                    <div>
                      <span className="rpm-field__label">محتوى القالب</span>
                      <span className="rpm-field__hint">
                        {showCode
                          ? 'وضع متقدم: تعديل كود HTML — للمُدمج فقط عند الحاجة.'
                          : 'محرّر نص للبنود — الأيقونات والترويسة والختم محمية ولن تتكسر أثناء الكتابة.'}
                      </span>
                    </div>
                    {isIntegrator ? (
                      <button
                        type="button"
                        className="rpm-template-linkish"
                        onClick={() => {
                          const next = !showCode
                          if (!next) {
                            setEditorSeed((prev) => ({ ...prev, [type]: prev[type] + 1 }))
                          } else {
                            setDrafts((prev) => ({
                              ...prev,
                              [type]: { ...prev[type], content: readVisualContent(type) },
                            }))
                          }
                          setCodeMode((prev) => ({ ...prev, [type]: next }))
                        }}
                      >
                        {showCode ? 'العودة للتعديل العادي' : 'تعديل متقدم (كود)'}
                      </button>
                    ) : null}
                  </div>

                  {showCode ? (
                    <textarea
                      ref={textRefs[type]}
                      className="rpm-template-source"
                      disabled={!canEdit}
                      value={drafts[type].content}
                      onChange={(e) => {
                        const next = e.target.value
                        markDirty(type, next)
                        setFiles((prev) => (prev[type] ? { ...prev, [type]: null } : prev))
                        setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: next } }))
                      }}
                      rows={16}
                      spellCheck={false}
                    />
                  ) : (
                    <VisualBodyEditor
                      html={drafts[type].content}
                      seed={editorSeed[type]}
                      disabled={!canEdit}
                      editorRef={visualRefs[type]}
                      assetsRef={assetsRefs[type]}
                      onChange={(nextHtml) => {
                        markDirty(type, nextHtml)
                        setFiles((prev) => (prev[type] ? { ...prev, [type]: null } : prev))
                        setDrafts((prev) => ({
                          ...prev,
                          [type]: { ...prev[type], content: nextHtml },
                        }))
                      }}
                    />
                  )}
                </div>

                <div className="rpm-field">
                  <span className="rpm-field__label">استبدال من ملف (اختياري)</span>
                  <input
                    type="file"
                    accept=".docx,.html,.htm,.md,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/html,text/markdown,text/plain"
                    disabled={!canEdit}
                    onChange={(e) => {
                      dirtyRef.current[type] = true
                      setFiles((prev) => ({ ...prev, [type]: e.target.files?.[0] || null }))
                    }}
                    style={{ marginBlockStart: '0.35rem', width: '100%' }}
                  />
                  {files[type] ? (
                    <p className="muted" style={{ marginBlockStart: '0.35rem', fontSize: '0.78rem' }}>
                      سيتم استخراج المحتوى من: {files[type]?.name}
                    </p>
                  ) : null}
                </div>
              </div>
            ) : null}
          </section>
        )
      })}
    </div>
  )
})
