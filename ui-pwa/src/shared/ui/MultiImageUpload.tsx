import { useId, useRef, useState } from 'react'
import { api, apiUpload, type ApiError } from '@/shared/api/client'
import { heicToJpeg, isHeicFile } from '@/shared/lib/heicConvert'
import {
  acceptForKind,
  DEFAULT_MEDIA_LIMITS,
  getMediaLimits,
  maxMbForFile,
} from '@/shared/lib/mediaLimits'
import { AuthMediaImage } from './AuthMediaImage'
import { FieldShell } from './Field'
import './MultiImageUpload.css'

export type MultiImageItem = {
  id: number
  path: string
  original_name?: string
  purpose?: string | null
  url?: string
}

type Props = {
  label: string
  value: MultiImageItem[]
  onChange: (items: MultiImageItem[]) => void
  collection?: string
  /** إن وُجدت عربية: تُربط الصور فوراً */
  attachableType?: string
  attachableId?: number
  max?: number
  required?: boolean
  error?: string
  hint?: string
  disabled?: boolean
}

async function prepareUploadFile(file: File): Promise<File> {
  if (!isHeicFile(file)) return file
  try {
    return await heicToJpeg(file)
  } catch {
    return file
  }
}

/** رفع عدة صور مع معاينة شبكة وإزالة فردية. */
export function MultiImageUpload({
  label,
  value,
  onChange,
  collection = 'vehicle_photos',
  attachableType,
  attachableId,
  max = 12,
  required,
  error,
  hint,
  disabled,
}: Props) {
  const inputId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [localError, setLocalError] = useState<string | null>(null)

  async function onPick(files: FileList | null) {
    if (!files?.length || disabled) return
    setLocalError(null)
    const room = Math.max(0, max - value.length)
    if (room <= 0) {
      setLocalError(`الحد الأقصى ${max} صور`)
      return
    }

    const limits = await getMediaLimits()
    const picked = Array.from(files).slice(0, room)
    setUploading(true)
    const next = [...value]
    try {
      for (let i = 0; i < picked.length; i += 1) {
        const file = picked[i]
        const maxMb = maxMbForFile(limits, file, 'image')
        if (file.size > maxMb * 1024 * 1024) {
          setLocalError(`«${file.name}» أكبر من ${maxMb} ميجا`)
          break
        }
        const uploadFile = await prepareUploadFile(file)
        const fd = new FormData()
        fd.append('file', uploadFile)
        fd.append('collection', collection)
        const purpose = next.length === 0 ? 'primary' : 'photo'
        fd.append('purpose', purpose)
        if (attachableType) fd.append('attachable_type', attachableType)
        if (attachableId) fd.append('attachable_id', String(attachableId))

        const res = await apiUpload<{
          data: {
            id: number
            original_name: string
            purpose?: string | null
            url?: string
          }
        }>('/media', fd)
        const media = res.data
        next.push({
          id: media.id,
          path: `/media/${media.id}/file`,
          original_name: media.original_name,
          purpose: media.purpose || purpose,
          url: media.url,
        })
      }
      onChange(next)
    } catch (e) {
      setLocalError((e as ApiError).message || 'فشل رفع الصور')
      if (next.length !== value.length) onChange(next)
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  async function removeAt(index: number) {
    const item = value[index]
    if (!item) return
    setLocalError(null)
    try {
      await api(`/media/${item.id}`, { method: 'DELETE' })
    } catch {
      /* إن فشل الحذف نُزيل من القائمة محلياً؛ الربط يُصحَّح عند الحفظ */
    }
    const next = value.filter((_, i) => i !== index).map((p, i) => ({
      ...p,
      purpose: i === 0 ? 'primary' : (p.purpose === 'primary' ? 'photo' : p.purpose),
    }))
    onChange(next)
  }

  const showError = error || localError || undefined
  const acceptAttr = acceptForKind(DEFAULT_MEDIA_LIMITS, 'image')
  const resolvedHint = disabled
    ? undefined
    : (hint ?? `يمكن رفع حتى ${max} صور · الأولى تُعرض جنب اللوحة`)

  return (
    <FieldShell label={label} required={required} error={showError} hint={resolvedHint} htmlFor={disabled ? undefined : inputId}>
      <div className={`rpm-multi-img ${disabled ? 'is-disabled' : ''} ${uploading ? 'is-uploading' : ''}`}>
        {value.length > 0 ? (
          <ul className="rpm-multi-img__grid" aria-label={label}>
            {value.map((item, index) => (
              <li key={item.id} className="rpm-multi-img__card">
                <AuthMediaImage
                  src={item.url || item.path}
                  alt={item.original_name || `صورة ${index + 1}`}
                  className="rpm-multi-img__thumb"
                />
                {index === 0 ? <span className="rpm-multi-img__badge">أساسية</span> : null}
                {!disabled ? (
                  <button
                    type="button"
                    className="rpm-multi-img__remove"
                    aria-label={`إزالة ${item.original_name || `صورة ${index + 1}`}`}
                    onClick={() => void removeAt(index)}
                  >
                    إزالة
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <div className="rpm-multi-img__empty" aria-hidden>
            لا صور بعد — ارفع صورة أو أكثر
          </div>
        )}
        {!disabled ? (
          <div className="rpm-multi-img__actions">
            <input
              ref={inputRef}
              id={inputId}
              type="file"
              accept={acceptAttr}
              multiple
              disabled={uploading || value.length >= max}
              className="rpm-multi-img__input"
              onChange={(e) => void onPick(e.target.files)}
            />
            <button
              type="button"
              className="rpm-multi-img__btn"
              disabled={uploading || value.length >= max}
              onClick={() => inputRef.current?.click()}
            >
              {uploading ? 'جارٍ الرفع…' : value.length ? 'إضافة صور' : 'اختيار صور'}
            </button>
            {value.length > 0 ? (
              <span className="rpm-multi-img__count">{value.length} / {max}</span>
            ) : null}
          </div>
        ) : null}
        {uploading ? (
          <p className="rpm-multi-img__progress" role="status" aria-live="polite">جارٍ الرفع…</p>
        ) : null}
      </div>
    </FieldShell>
  )
}
