/** مسار API لملف ميديا محفوظ (مسار/رقم/رابط جزئي). */
export function mediaFileUrl(stored: string): string | null {
  if (!stored) return null
  const value = stored.trim()
  if (!value) return null
  if (value.startsWith('http://') || value.startsWith('https://')) return value
  if (value.includes('/media/') && value.includes('/file')) {
    const match = value.match(/\/media\/(\d+)\/file/)
    return match ? `/api/media/${match[1]}/file` : null
  }
  if (/^\/media\/\d+\/file$/.test(value)) {
    return `/api${value}`
  }
  if (/^\/api\/media\/\d+\/file$/.test(value)) return value
  if (/^\d+$/.test(value)) return `/api/media/${value}/file`
  return null
}
