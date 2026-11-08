import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { Button, TextAreaField, TextField, useToast } from '@/shared/ui'
import { printHtmlDocument } from '@/utils/printHtml'
import { shellOwnerAgreementHtml } from '@/shared/contracts/ownerAgreementPrint'

type TemplateType = 'contract' | 'handover_receipt' | 'owner_agreement'

type Placeholder = {
  key: string
  aliases: string[]
  label: string
  sample: string
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
    title: 'قالب العقد الأساسي',
    hint: 'يُستخدم عند طباعة عقد التأجير. يدعم HTML أو Markdown أو رفع ملف Word (.docx).',
  },
  handover_receipt: {
    title: 'قالب إيصال الاستلام والتأمين',
    hint: 'يُستخدم عند طباعة إيصال الاستلام والتأمين.',
  },
  owner_agreement: {
    title: 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
    hint: 'عقد قانوني متعدد الصفحات يُملأ تلقائياً ببيانات المركز والمالك والعربية عند الحفظ/الطباعة، وقابل للتعديل هنا.',
  },
}

function shellPreview(type: TemplateType, title: string, body: string) {
  if (type === 'owner_agreement') {
    return shellOwnerAgreementHtml(body, title)
  }
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${title}</title>
<style>
  body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#0f172a}
  h1{margin:0 0 4px;font-size:22px} h2{margin:20px 0 8px;font-size:16px}
  .muted{color:#64748b} table{width:100%;border-collapse:collapse;margin-top:12px}
  td,th{border:1px solid #cbd5e1;padding:8px;text-align:start} th{background:#f8fafc;width:32%}
  .foot{margin-top:24px;color:#64748b;font-size:12px}
  .terms{margin-top:20px;white-space:pre-wrap;font-size:13px;line-height:1.7}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px}
  .print-head{display:flex;align-items:center;gap:16px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:12px}
  .print-logo{width:96px;height:auto;object-fit:contain}
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

      const token = localStorage.getItem('rpm_token')
      const res = await fetch('/api/document-templates', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: fd,
      })
      const json = await res.json()
      if (!res.ok) {
        throw Object.assign(new Error(json?.message || 'تعذّر حفظ القالب.'), { status: res.status, errors: json?.errors })
      }
      const row = json.data as TemplateRow
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
      // Persist draft content temporarily via preview endpoint sample vars (does not publish).
      // If draft differs from active, send override variables only — preview uses active DB template.
      // So we first save? No — for unsaved draft preview, render locally with sample placeholders.
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

      // Also hit server preview for active published version (optional confirmation).
      await api('/document-templates/preview', {
        method: 'POST',
        json: { type },
      }).catch(() => undefined)
    } catch (e) {
      toast.error((e as ApiError).message || 'تعذّر معاينة القالب.')
    } finally {
      setPreviewing(null)
    }
  }

  function insertToken(type: TemplateType, token: string) {
    setDrafts((prev) => ({
      ...prev,
      [type]: {
        ...prev[type],
        content: `${prev[type].content}${prev[type].content.endsWith('\n') || !prev[type].content ? '' : ' '}{{${token}}}`,
      },
    }))
  }

  if (loading) {
    return <p className="muted">جاري تحميل قوالب المستندات…</p>
  }

  return (
    <div className="rpm-stack" style={{ gap: '1rem' }}>
      <p className="muted" style={{ margin: 0 }}>
        خصّص قوالب الطباعة بعناصر ديناميكية مثل{' '}
        <code>{'{{اسم_العميل}}'}</code> و<code>{'{{رقم_اللوحة}}'}</code>. الحفظ ينشر إصداراً جديداً ويصبح فوراً
        القالب النشط لكل المستخدمين عند الطباعة.
      </p>

      <div className="rpm-panel" style={{ padding: '0.85rem 1rem' }}>
        <strong style={{ display: 'block', marginBlockEnd: '0.55rem' }}>المتغيرات المتاحة</strong>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
          {placeholders.map((p) => (
            <button
              key={p.key}
              type="button"
              className="rpm-chip"
              title={`${p.label} · مثال: ${p.sample}`}
              onClick={() => insertToken('contract', p.aliases[0] || p.key)}
              disabled={!canEdit}
              style={{
                border: '1px solid var(--border)',
                background: 'var(--surface-2, var(--surface))',
                borderRadius: 999,
                padding: '0.25rem 0.65rem',
                fontSize: '0.78rem',
                cursor: canEdit ? 'pointer' : 'default',
              }}
            >
              {`{{${p.aliases[0] || p.key}}}`}
            </button>
          ))}
        </div>
      </div>

      {(['contract', 'handover_receipt', 'owner_agreement'] as TemplateType[]).map((type) => {
        const meta = TYPE_META[type]
        const row = active[type]
        return (
          <section key={type} className="rpm-panel">
            <div className="rpm-panel__head" style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <h3 style={{ margin: 0 }}>{meta.title}</h3>
                <p className="muted" style={{ margin: '0.25rem 0 0', fontSize: '0.82rem' }}>
                  {meta.hint}
                  {row ? ` · الإصدار النشط: ${row.version}` : ''}
                </p>
              </div>
              <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap' }}>
                <Button
                  size="sm"
                  variant="secondary"
                  loading={previewing === type}
                  onClick={() => void preview(type)}
                >
                  معاينة
                </Button>
                <Button
                  size="sm"
                  loading={saving === type}
                  disabled={!canEdit}
                  onClick={() => void save(type)}
                >
                  حفظ ونشر
                </Button>
              </div>
            </div>

            <div className="rpm-form-grid" style={{ marginBlockStart: '0.75rem' }}>
              <TextField
                label="عنوان القالب"
                value={drafts[type].title}
                disabled={!canEdit}
                onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], title: e.target.value } }))}
              />
              <div className="rpm-field">
                <span className="rpm-field__label">رفع ملف Word / HTML / Markdown (اختياري)</span>
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
              <TextAreaField
                label="محتوى القالب (HTML / Markdown مع المتغيرات)"
                value={drafts[type].content}
                disabled={!canEdit}
                onChange={(e) => setDrafts((prev) => ({ ...prev, [type]: { ...prev[type], content: e.target.value } }))}
                hint="اترك الحقول فارغة في المحتوى لتبقى كما هي؛ استخدم {{المتغير}} للإحلال الديناميكي."
              />
            </div>
          </section>
        )
      })}
    </div>
  )
}
