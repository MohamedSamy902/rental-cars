import { useEffect, useId, useRef, useState } from 'react'
import { apiBlob, apiUpload, type ApiError } from '@/shared/api/client'
import { FieldShell } from './Field'
import './MediaUpload.css'

export type MediaUploadResult = {
  id: number
  kind: 'image' | 'video' | string
  mime_type: string
  original_name: string
  path: string
  url: string
  collection: string
  purpose?: string | null
}

type AcceptKind = 'image' | 'video' | 'both'

type Props = {
  label: string
  value?: string | null
  onChange: (path: string | null, media?: MediaUploadResult | null) => void
  collection?: string
  purpose?: string
  accept?: AcceptKind
  attachableType?: string
  attachableId?: number
  required?: boolean
  error?: string
  hint?: string
  disabled?: boolean
}

const ACCEPT: Record<AcceptKind, string> = {
  image: 'image/jpeg,image/png,image/webp,image/gif',
  video: 'video/mp4,video/webm,video/quicktime',
  both: 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
}

function mediaApiPath(stored: string): string | null {
  if (!stored) return null
  if (stored.includes('/media/') && stored.includes('/file')) {
    const match = stored.match(/\/media\/(\d+)\/file/)
    return match ? `/media/${match[1]}/file` : null
  }
  if (/^\/media\/\d+\/file$/.test(stored)) return stored
  return null
}

export function MediaUpload({
  label,
  value,
  onChange,
  collection = 'general',
  purpose,
  accept = 'image',
  attachableType,
  attachableId,
  required,
  error,
  hint = 'يُحفظ محلياً على السيرفر — بلا إرسال لخدمة خارجية',
  disabled,
}: Props) {
  const inputId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [localError, setLocalError] = useState<string | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [previewKind, setPreviewKind] = useState<'image' | 'video' | null>(null)
  const blobRef = useRef<string | null>(null)

  useEffect(() => {
    let cancelled = false
    async function loadExisting() {
      if (blobRef.current) {
        URL.revokeObjectURL(blobRef.current)
        blobRef.current = null
      }
      setPreviewUrl(null)
      setPreviewKind(null)
      const apiPath = value ? mediaApiPath(value) : null
      if (!apiPath) return
      try {
        const blob = await apiBlob(apiPath)
        if (cancelled) return
        const url = URL.createObjectURL(blob)
        blobRef.current = url
        setPreviewUrl(url)
        setPreviewKind(blob.type.startsWith('video/') ? 'video' : 'image')
      } catch {
        /* معاينة اختيارية */
      }
    }
    void loadExisting()
    return () => {
      cancelled = true
      if (blobRef.current) {
        URL.revokeObjectURL(blobRef.current)
        blobRef.current = null
      }
    }
  }, [value])

  async function onPick(file: File | null) {
    if (!file) return
    setLocalError(null)
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('collection', collection)
      if (purpose) fd.append('purpose', purpose)
      if (attachableType) fd.append('attachable_type', attachableType)
      if (attachableId) fd.append('attachable_id', String(attachableId))

      const res = await apiUpload<{ data: MediaUploadResult; message: string }>('/media', fd)
      const media = res.data
      const storePath = `/media/${media.id}/file`

      if (blobRef.current) URL.revokeObjectURL(blobRef.current)
      const local = URL.createObjectURL(file)
      blobRef.current = local
      setPreviewUrl(local)
      setPreviewKind(media.kind === 'video' ? 'video' : 'image')

      onChange(storePath, media)
    } catch (e) {
      setLocalError((e as ApiError).message || 'فشل الرفع')
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  function clear() {
    setLocalError(null)
    if (blobRef.current) {
      URL.revokeObjectURL(blobRef.current)
      blobRef.current = null
    }
    setPreviewUrl(null)
    setPreviewKind(null)
    onChange(null, null)
  }

  const showError = error || localError || undefined

  return (
    <FieldShell label={label} required={required} error={showError} hint={hint} htmlFor={inputId}>
      <div className={`rpm-media ${disabled ? 'is-disabled' : ''}`}>
        {previewUrl ? (
          <div className="rpm-media__preview">
            {previewKind === 'video' ? (
              <video src={previewUrl} controls playsInline preload="metadata" />
            ) : (
              <img src={previewUrl} alt={label} />
            )}
          </div>
        ) : (
          <div className="rpm-media__empty" aria-hidden>
            <span>لا ملف بعد</span>
          </div>
        )}
        <div className="rpm-media__actions">
          <input
            ref={inputRef}
            id={inputId}
            type="file"
            accept={ACCEPT[accept]}
            disabled={disabled || uploading}
            className="rpm-media__input"
            onChange={(e) => void onPick(e.target.files?.[0] || null)}
          />
          <button
            type="button"
            className="rpm-media__btn"
            disabled={disabled || uploading}
            onClick={() => inputRef.current?.click()}
          >
            {uploading ? 'جارٍ الرفع…' : value ? 'استبدال' : 'اختيار ملف'}
          </button>
          {value ? (
            <button type="button" className="rpm-media__btn is-muted" disabled={disabled || uploading} onClick={clear}>
              إزالة
            </button>
          ) : null}
        </div>
      </div>
    </FieldShell>
  )
}
