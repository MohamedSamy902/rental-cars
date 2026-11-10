import { useEffect, useMemo, useRef, useState } from 'react'
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
    hint: 'عدّل البنود والنصوص هنا. استخدم الحقول الجاهزة بالأسفل لإدراج بيانات العقد تلقائياً عند الطباعة.',
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
  body{font-family:'Cairo','IBM Plex Sans Arabic',Tahoma,sans-serif;padding:24px;color:#0f172a}
</style></head><body>${body}</body></html>`
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
  const textRefs = {
    contract: useRef<HTMLTextAreaElement | null>(null),
    handover_receipt: useRef<HTMLTextAreaElement | null>(null),
    owner_agreement: useRef<HTMLTextAreaElement | null>(null),
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
    const el = textRefs[type].current
    const snippet = `{{${token}}}`
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
      <p className="rpm-template-intro">
        القالب هو شكل المستند المطبوع. عدّل النص من المربع بالأسفل، واضغط اسم الحقل لإدراج بيانات تُملأ وحدها عند الطباعة (اسم العميل، اللوحة، السعر…). استخدم «معاينة طباعة» قبل الحفظ.
      </p>

      {(['contract', 'handover_receipt', 'owner_agreement'] as TemplateType[]).map((type) => {
        const meta = TYPE_META[type]
        const row = active[type]
        const isOpen = Boolean(expanded[type])
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
                    اضغط الاسم لإدراجه عند المؤشر. عند الطباعة يتحول إلى بيانات العقد الحقيقية.
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
                  <span className="rpm-field__label">محتوى القالب</span>
                  <span className="rpm-field__hint">
                    نص القالب للتعديل. المعاينة تظهر الشكل النهائي للطباعة.
                  </span>
                  <textarea
                    ref={textRefs[type]}
                    className="rpm-template-source"
                    disabled={!canEdit}
                    value={drafts[type].content}
                    onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: e.target.value } }))}
                    rows={16}
                  />
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
