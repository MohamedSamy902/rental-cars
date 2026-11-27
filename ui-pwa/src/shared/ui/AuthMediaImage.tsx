import { useEffect, useState } from 'react'
import { apiBlob } from '@/shared/api/client'

type Props = {
  /** مسار API مثل `/media/12/file` أو رابط كامل ينتهي بـ `/media/{id}/file` */
  src: string
  alt: string
  className?: string
}

function toApiPath(src: string): string | null {
  if (!src) return null
  if (src.startsWith('/media/')) return src
  if (src.startsWith('/api/media/')) return src.replace(/^\/api/, '')
  const match = src.match(/\/media\/(\d+)\/file/)
  return match ? `/media/${match[1]}/file` : null
}

/** معاينة صورة ميديا محمية بـ Bearer عبر blob URL. */
export function AuthMediaImage({ src, alt, className }: Props) {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    let revoked: string | null = null
    let cancelled = false
    const apiPath = toApiPath(src)
    if (!apiPath) {
      setUrl(null)
      return undefined
    }
    void (async () => {
      try {
        const blob = await apiBlob(apiPath)
        if (cancelled) return
        const objectUrl = URL.createObjectURL(blob)
        revoked = objectUrl
        setUrl(objectUrl)
      } catch {
        if (!cancelled) setUrl(null)
      }
    })()
    return () => {
      cancelled = true
      if (revoked) URL.revokeObjectURL(revoked)
    }
  }, [src])

  if (!url) {
    return <div className={className} aria-label={alt} role="img" />
  }

  return <img className={className} src={url} alt={alt} loading="lazy" />
}
