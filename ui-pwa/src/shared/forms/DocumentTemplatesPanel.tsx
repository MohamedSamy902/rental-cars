import { useEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { api, apiUpload, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { Button, TextField, useToast } from '@/shared/ui'
import { printHtmlDocument } from '@/utils/printHtml'
import { shellOwnerAgreementHtml } from '@/shared/contracts/ownerAgreementPrint'
import { shellRentalContractHtml } from '@/shared/contracts/rentalContractPrint'
import './DocumentTemplatesPanel.css'

type TemplateType = 'contract' | 'handover_receipt' | 'owner_agreement' | 'custody_agreement'

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
    title: 'عقد إيجار سيارة (نموذج المركز)',
    hint: 'عدّل البنود والنصوص كما تظهر في الطباعة. الحقول الجاهزة بالأسفل تُدرج بيانات العقد تلقائياً.',
  },
  handover_receipt: {
    title: 'قالب إيصال الاستلام والتأمين',
    hint: 'يُستخدم عند طباعة إيصال الاستلام والتأمين.',
  },
  owner_agreement: {
    title: 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
    hint: 'يُملأ تلقائياً ببيانات المركز والمالك والعربية عند الحفظ/الطباعة.',
  },
  custody_agreement: {
    title: 'قالب عقد الاعتماد',
    hint: 'نسخ صادرة ثابتة من ملف العربية (مركز + مالك + عربية + مدة).',
  },
}

const GROUP_ORDER = ['المركز', 'العميل', 'العربية', 'العقد', 'التأمين', 'المالك', 'أخرى']

/** عناصر زخرفية/ترويسة تُطوى في المحرر البصري حتى لا تعطل الكتابة. */
const DECOR_SELECTORS = [
  'header.rpm-lc-head',
  'header.print-head',
  '.rpm-lc-ribbon',
  '.rpm-lc-stamp',
  '.rpm-lc-float-ico',
  '.page-break',
  'svg',
  'img',
] as const

function shellPreview(type: TemplateType, title: string, body: string) {
  if (type === 'owner_agreement') {
    return shellOwnerAgreementHtml(body, title)
  }
  if (type === 'contract') {
    return shellRentalContractHtml(body, title)
  }
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${title}</title>
<style>
  body{font-family:'Cairo','IBM Plex Sans Arabic',Tahoma,sans-serif;padding:24px;color:#0f172a}
</style></head><body>${body}</body></html>`
}

function assetLabel(node: HTMLElement): string {
  if (node.matches('header.rpm-lc-head, header.print-head')) return 'ترويسة العقد (محمية)'
  if (node.matches('.rpm-lc-ribbon')) return 'شريط علوي (محمي)'
  if (node.matches('.rpm-lc-stamp')) return 'ختم المركز (محمي)'
  if (node.matches('.rpm-lc-float-ico')) return 'أيقونة تصميم (محمية)'
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

export function DocumentTemplatesPanel() {
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
  const [expanded, setExpanded] = useState<Partial<Record<TemplateType, boolean>>>({ contract: true })
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

  function readVisualContent(type: TemplateType): string {
    const visual = visualRefs[type].current
    const assets = assetsRefs[type].current
    if (visual && assets) return restoreVisualHtml(visual.innerHTML, assets)
    return drafts[type].content
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
          nextDrafts[row.type] = { title: row.title, content: row.content }
        }
        setActive(nextActive)
        setDrafts(nextDrafts)
        setEditorSeed({
          contract: (nextActive.contract?.version ?? 0) + 1,
          handover_receipt: (nextActive.handover_receipt?.version ?? 0) + 1,
          owner_agreement: (nextActive.owner_agreement?.version ?? 0) + 1,
          custody_agreement: (nextActive.custody_agreement?.version ?? 0) + 1,
        })
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
  }, [toast])

  async function save(type: TemplateType) {
    if (!canEdit) {
      toast.error('لا صلاحية لتعديل قوالب المستندات.')
      return
    }
    const content = codeMode[type] ? drafts[type].content : readVisualContent(type)
    if (!codeMode[type]) {
      setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content } }))
    }
    setSaving(type)
    try {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('title', drafts[type].title || TYPE_META[type].title)
      fd.append('content', content)
      fd.append('format', 'html')
      const file = files[type]
      if (file) fd.append('file', file)

      const json = await apiUpload<{ data: TemplateRow; message?: string }>('/document-templates', fd)
      const row = json.data
      setActive((prev) => ({ ...prev, [type]: row }))
      setDrafts((prev) => ({ ...prev, [type]: { title: row.title, content: row.content } }))
      setEditorSeed((prev) => ({ ...prev, [type]: prev[type] + 1 }))
      setFiles((prev) => ({ ...prev, [type]: null }))
      toast.success(json.message || 'تم حفظ القالب.')
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّر حفظ القالب.')
    } finally {
      setSaving(null)
    }
  }

  async function preview(type: TemplateType) {
    setPreviewing(type)
    try {
      const content = codeMode[type] ? drafts[type].content : readVisualContent(type)
      const sampleMap: Record<string, string> = {}
      for (const p of placeholders) {
        sampleMap[p.key] = p.sample
        for (const a of p.aliases) sampleMap[a] = p.sample
      }
      const rendered = content.replace(/\{\{\s*([a-zA-Z0-9_\u0600-\u06FF]+)\s*\}\}/g, (_, key: string) => {
        const v = sampleMap[key]
        return v != null ? String(v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch)) : `{{${key}}}`
      })
      printHtmlDocument(shellPreview(type, TYPE_META[type].title, rendered))
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
          setDrafts((prev) => ({
            ...prev,
            [type]: { ...prev[type], content: restoreVisualHtml(root.innerHTML, assetsRefs[type].current) },
          }))
          toast.success(`أُدرج: ${label}`)
          return
        }
      }
      // لا يوجد مؤشر داخل المحرر — ألحق في النهاية
      const p = document.createElement('p')
      p.textContent = snippet
      root.appendChild(p)
      setDrafts((prev) => ({
        ...prev,
        [type]: { ...prev[type], content: restoreVisualHtml(root.innerHTML, assetsRefs[type].current) },
      }))
      toast.success(`أُدرج في النص: ${label}`)
      return
    }

    setDrafts((prev) => ({
      ...prev,
      [type]: {
        ...prev[type],
        content: `${prev[type].content}${prev[type].content && !prev[type].content.endsWith('\n') ? ' ' : ''}${snippet}`,
      },
    }))
    setEditorSeed((prev) => ({ ...prev, [type]: prev[type] + 1 }))
    toast.success(`أُدرج في النص: ${label}`)
  }

  if (loading) {
    return <p className="muted">جاري تحميل قوالب المستندات…</p>
  }

  return (
    <div className="rpm-stack" style={{ gap: '1rem' }}>
      <p className="rpm-template-intro">
        محرّر نص خفيف للبنود والجمل — الرسوم والترويسة والختم تظهر كشارات محمية ولا تتكسر أثناء التعديل. استخدم «معاينة طباعة» لرؤية الشكل النهائي قبل الحفظ.
      </p>

      {(['contract', 'handover_receipt', 'owner_agreement', 'custody_agreement'] as TemplateType[]).map((type) => {
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
                  معاينة طباعة
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
                  onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], title: e.target.value } }))}
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
                      onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: e.target.value } }))}
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
                      onChange={(nextHtml) => setDrafts((prev) => ({
                        ...prev,
                        [type]: { ...prev[type], content: nextHtml },
                      }))}
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
}
