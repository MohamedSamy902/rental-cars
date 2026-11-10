import { useEffect, useMemo, useRef, useState, type MutableRefObject } from 'react'
import { api, apiUpload, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { Button, TextField, useToast } from '@/shared/ui'
import { printHtmlDocument } from '@/utils/printHtml'
import { shellOwnerAgreementHtml } from '@/shared/contracts/ownerAgreementPrint'
import { shellRentalContractHtml } from '@/shared/contracts/rentalContractPrint'
import './DocumentTemplatesPanel.css'

type TemplateType = 'contract' | 'handover_receipt' | 'owner_agreement'

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
    hint: 'عدّل النص كما يظهر للطباعة. الحقول الزرقاء تُستبدل تلقائياً ببيانات كل عقد.',
  },
  handover_receipt: {
    title: 'قالب إيصال الاستلام والتأمين',
    hint: 'يُستخدم عند طباعة إيصال الاستلام والتأمين.',
  },
  owner_agreement: {
    title: 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
    hint: 'يُملأ تلقائياً ببيانات المركز والمالك والعربية عند الحفظ/الطباعة.',
  },
}

const GROUP_ORDER = ['المركز', 'العميل', 'العربية', 'العقد', 'التأمين', 'المالك', 'أخرى']

function shellPreview(type: TemplateType, title: string, body: string) {
  if (type === 'owner_agreement') {
    return shellOwnerAgreementHtml(body, title)
  }
  if (type === 'contract') {
    return shellRentalContractHtml(body, title)
  }
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${title}</title>
<style>
  body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a}
  h1{margin:0 0 4px;font-size:22px} h2{margin:20px 0 8px;font-size:16px}
  .muted{color:#64748b} table{width:100%;border-collapse:collapse;margin-top:12px}
  td,th{border:1px solid #cbd5e1;padding:8px;text-align:start} th{background:#f8fafc;width:32%}
</style></head><body>${body}</body></html>`
}

function VisualHtmlEditor({
  value,
  disabled,
  onChange,
  editorRef,
}: {
  value: string
  disabled?: boolean
  onChange: (html: string) => void
  editorRef: MutableRefObject<HTMLDivElement | null>
}) {
  const skipNext = useRef(false)

  useEffect(() => {
    const el = editorRef.current
    if (!el) return
    if (skipNext.current) {
      skipNext.current = false
      return
    }
    if (el.innerHTML !== value) {
      el.innerHTML = value || ''
    }
  }, [value, editorRef])

  return (
    <div
      ref={editorRef}
      className="rpm-template-editor"
      contentEditable={!disabled}
      suppressContentEditableWarning
      dir="rtl"
      role="textbox"
      aria-multiline="true"
      aria-label="محتوى القالب"
      onInput={() => {
        const el = editorRef.current
        if (!el) return
        skipNext.current = true
        onChange(el.innerHTML)
      }}
      onBlur={() => {
        const el = editorRef.current
        if (!el) return
        skipNext.current = true
        onChange(el.innerHTML)
      }}
    />
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
  })
  const [files, setFiles] = useState<Partial<Record<TemplateType, File | null>>>({})
  const [saving, setSaving] = useState<TemplateType | null>(null)
  const [previewing, setPreviewing] = useState<TemplateType | null>(null)
  const [expanded, setExpanded] = useState<Partial<Record<TemplateType, boolean>>>({ contract: true })
  const [showSource, setShowSource] = useState<Partial<Record<TemplateType, boolean>>>({})
  const contractEditorRef = useRef<HTMLDivElement | null>(null)
  const handoverEditorRef = useRef<HTMLDivElement | null>(null)
  const ownerEditorRef = useRef<HTMLDivElement | null>(null)
  const editorRefs: Record<TemplateType, MutableRefObject<HTMLDivElement | null>> = {
    contract: contractEditorRef,
    handover_receipt: handoverEditorRef,
    owner_agreement: ownerEditorRef,
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
        }
        for (const row of res.data || []) {
          if (row.type !== 'contract' && row.type !== 'handover_receipt' && row.type !== 'owner_agreement') continue
          nextActive[row.type] = row
          nextDrafts[row.type] = { title: row.title, content: row.content }
        }
        setActive(nextActive)
        setDrafts(nextDrafts)
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
    setSaving(type)
    try {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('title', drafts[type].title || TYPE_META[type].title)
      fd.append('content', drafts[type].content)
      fd.append('format', 'html')
      const file = files[type]
      if (file) fd.append('file', file)

      const json = await apiUpload<{ data: TemplateRow; message?: string }>('/document-templates', fd)
      const row = json.data
      setActive((prev) => ({ ...prev, [type]: row }))
      setDrafts((prev) => ({ ...prev, [type]: { title: row.title, content: row.content } }))
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
      const content = drafts[type].content
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
    const el = editorRefs[type].current
    const snippet = `{{${token}}}`
    if (el && document.activeElement === el) {
      el.focus()
      const ok = document.execCommand('insertText', false, snippet)
      if (!ok) {
        const sel = window.getSelection()
        if (sel && sel.rangeCount > 0) {
          const range = sel.getRangeAt(0)
          range.deleteContents()
          range.insertNode(document.createTextNode(snippet))
          range.collapse(false)
        } else {
          el.innerHTML = `${el.innerHTML}${snippet}`
        }
      }
      setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: el.innerHTML } }))
      toast.success(`أُدرج: ${label}`)
      return
    }
    setDrafts((prev) => ({
      ...prev,
      [type]: {
        ...prev[type],
        content: `${prev[type].content}${prev[type].content && !prev[type].content.endsWith('\n') ? ' ' : ''}${snippet}`,
      },
    }))
    toast.success(`أُدرج في النص: ${label}`)
  }

  if (loading) {
    return <p className="muted">جاري تحميل قوالب المستندات…</p>
  }

  return (
    <div className="rpm-stack" style={{ gap: '1rem' }}>
      <p className="muted" style={{ margin: 0 }}>
        القالب هو شكل المستند المطبوع. عدّل النص مباشرة كما تراه، واضغط على اسم الحقل بالأسفل لإدراج بيانات تُملأ وحدها عند طباعة كل عقد (اسم العميل، اللوحة، السعر…).
      </p>

      {(['contract', 'handover_receipt', 'owner_agreement'] as TemplateType[]).map((type) => {
        const meta = TYPE_META[type]
        const row = active[type]
        const isOpen = Boolean(expanded[type])
        const sourceMode = Boolean(showSource[type])
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
                    هذه ليست نصوصاً ثابتة — اضغط الاسم لإدراجه في مكان المؤشر داخل المحرر. عند الطباعة يتحول إلى بيانات العقد الحقيقية.
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
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
                    <span className="rpm-field__label">محتوى القالب</span>
                    <button
                      type="button"
                      className="rpm-linkish"
                      onClick={() => setShowSource((prev) => ({ ...prev, [type]: !prev[type] }))}
                      style={{ background: 'none', border: 0, color: 'var(--accent, #0f766e)', cursor: 'pointer', fontSize: '0.82rem' }}
                    >
                      {sourceMode ? 'العودة للتعديل المرئي' : 'عرض المصدر (للمتقدمين)'}
                    </button>
                  </div>
                  <span className="rpm-field__hint">
                    عدّل النص والبنود مباشرة. لا تحتاج كتابة أكواد — استخدم الحقول أعلاه للإدراج.
                  </span>
                  {sourceMode ? (
                    <textarea
                      className="rpm-template-source"
                      dir="rtl"
                      disabled={!canEdit}
                      value={drafts[type].content}
                      onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: e.target.value } }))}
                      rows={16}
                    />
                  ) : (
                    <VisualHtmlEditor
                      value={drafts[type].content}
                      disabled={!canEdit}
                      editorRef={editorRefs[type]}
                      onChange={(html) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: html } }))}
                    />
                  )}
                </div>

                <div className="rpm-field">
                  <span className="rpm-field__label">استبدال من ملف (اختياري)</span>
                  <input
                    type="file"
                    accept=".docx,.html,.htm,.md,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/html,text/markdown,text/plain"
                    disabled={!canEdit}
                    onChange={(e) => setFiles((prev) => ({ ...prev, [type]: e.target.files?.[0] || null }))}
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
