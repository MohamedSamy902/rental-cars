import { api } from '@/shared/api/client'

export type MediaLimits = {
  image_max_mb: number
  video_max_mb: number
  document_max_mb: number
  hint: string
  accept_image: string
  accept_video: string
  accept_both: string
  accept_document: string
}

export const DEFAULT_MEDIA_LIMITS: MediaLimits = {
  image_max_mb: 12,
  video_max_mb: 50,
  document_max_mb: 12,
  hint: 'المسموح: صور (jpg/png/webp/gif/heic) وفيديو (mp4/mov/webm) — الحد الأقصى للصورة 12 ميجا وللفيديو 50 ميجا.',
  accept_image: 'image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif,image/*',
  accept_video: 'video/mp4,video/webm,video/quicktime,video/3gpp,video/x-m4v',
  accept_both:
    'image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif,image/*,video/mp4,video/webm,video/quicktime,video/3gpp,video/x-m4v',
  accept_document: 'image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif,application/pdf',
}

let cache: MediaLimits | null = null
let inflight: Promise<MediaLimits> | null = null

/** Fetch `/media/limits` once per session; falls back to defaults on failure. */
export function getMediaLimits(): Promise<MediaLimits> {
  if (cache) return Promise.resolve(cache)
  if (!inflight) {
    inflight = api<{ data: MediaLimits }>('/media/limits')
      .then((res) => {
        cache = { ...DEFAULT_MEDIA_LIMITS, ...res.data }
        return cache
      })
      .catch(() => {
        cache = DEFAULT_MEDIA_LIMITS
        return cache
      })
      .finally(() => {
        inflight = null
      })
  }
  return inflight
}

export function acceptForKind(limits: MediaLimits, kind: 'image' | 'video' | 'both'): string {
  if (kind === 'video') return limits.accept_video || DEFAULT_MEDIA_LIMITS.accept_video
  if (kind === 'both') return limits.accept_both || DEFAULT_MEDIA_LIMITS.accept_both
  return limits.accept_image || DEFAULT_MEDIA_LIMITS.accept_image
}

export function maxMbForFile(limits: MediaLimits, file: File, kind: 'image' | 'video' | 'both'): number {
  const isVideo = file.type.startsWith('video/') || /\.(mp4|webm|mov|3gp|m4v)$/i.test(file.name)
  if (kind === 'video' || isVideo) return limits.video_max_mb
  return limits.image_max_mb
}
