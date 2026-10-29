export function isHeicFile(file: File): boolean {
  const type = (file.type || '').toLowerCase()
  const name = file.name.toLowerCase()
  return (
    type === 'image/heic' ||
    type === 'image/heif' ||
    type === 'image/heic-sequence' ||
    type === 'image/heif-sequence' ||
    name.endsWith('.heic') ||
    name.endsWith('.heif')
  )
}

/** Convert HEIC/HEIF to JPEG for broader browser/server support. Throws on failure. */
export async function heicToJpeg(file: File): Promise<File> {
  const heic2any = (await import('heic2any')).default
  const result = await heic2any({
    blob: file,
    toType: 'image/jpeg',
    quality: 0.92,
  })
  const blob = Array.isArray(result) ? result[0] : result
  if (!blob) throw new Error('empty heic conversion')
  const base = file.name.replace(/\.(heic|heif)$/i, '') || 'photo'
  return new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() })
}
