import { useEffect, useId, useRef, useState } from 'react'
import { apiBlob, apiUpload, type ApiError } from '@/shared/api/client'
import { heicToJpeg, isHeicFile } from '@/shared/lib/heicConvert'
import {
  acceptForKind,
  DEFAULT_MEDIA_LIMITS,
  getMediaLimits,
  maxMbForFile,
  type MediaLimits,
} from '@/shared/lib/mediaLimits'
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
  /** Optional capture hint for mobile camera; omit to keep gallery/file picker. */
  capture?: 'environment' | 'user'
  attachableType?: string
  attachableId?: number
  required?: boolean
  error?: string
  hint?: string
  disabled?: boolean
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

async function prepareUploadFile(file: File): Promise<File> {
  if (!isHeicFile(file)) return file
  try {
    return await heicToJpeg(file)
  } catch {
    /* السيرفر يقبل HEIC كاحتياط */
    return file
  }
}

export function MediaUpload({
  label,
  value,
  onChange,
  collection = 'general',
  purpose,
  accept = 'image',
  capture,
  attachableType,
  attachableId,
  required,
  error,
  hint,
  disabled,
}: Props) {
  const inputId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [localError, setLocalError] = useState<string | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [previewKind, setPreviewKind] = useState<'image' | 'video' | null>(null)
  const [limits, setLimits] = useState<MediaLimits>(DEFAULT_MEDIA_LIMITS)
  const blobRef = useRef<string | null>(null)

  useEffect(() => {
    let cancelled = false
    void getMediaLimits().then((l) => {
      if (!cancelled) setLimits(l)
    })
    return () => {
      cancelled = true
    }
  }, [])

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

    const currentLimits = await getMediaLimits()
    setLimits(currentLimits)

    const maxMb = maxMbForFile(currentLimits, file, accept)
    const maxBytes = maxMb * 1024 * 1024
    if (file.size > maxBytes) {
      setLocalError(`حجم الملف أكبر من المسموح (الحد الأقصى ${maxMb} ميجا)`)
      if (inputRef.current) inputRef.current.value = ''
      return
    }

    setUploading(true)
    try {
      const uploadFile = await prepareUploadFile(file)

      if (uploadFile !== file && uploadFile.size > maxBytes) {
        setLocalError(`حجم الملف أكبر من المسموح (الحد الأقصى ${maxMb} ميجا)`)
        return
      }

      const fd = new FormData()
      fd.append('file', uploadFile)
      fd.append('collection', collection)
      if (purpose) fd.append('purpose', purpose)
      if (attachableType) fd.append('attachable_type', attachableType)
      if (attachableId) fd.append('attachable_id', String(attachableId))

      const res = await apiUpload<{ data: MediaUploadResult; message: string }>('/media', fd)
      const media = res.data
      const storePath = `/media/${media.id}/file`

      if (blobRef.current) URL.revokeObjectURL(blobRef.current)
      const local = URL.createObjectURL(uploadFile)
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
  const resolvedHint = hint ?? limits.hint ?? DEFAULT_MEDIA_LIMITS.hint
  const acceptAttr = acceptForKind(limits, accept)

  return (
    <FieldShell label={label} required={required} error={showError} hint={resolvedHint} htmlFor={inputId}>
      <div className={`rpm-media ${disabled ? 'is-disabled' : ''} ${uploading ? 'is-uploading' : ''}`}>
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
            accept={acceptAttr}
            capture={capture}
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
        {uploading ? (
          <p className="rpm-media__progress" role="status" aria-live="polite">
            جارٍ الرفع…
          </p>
        ) : null}
      </div>
    </FieldShell>
  )
}
