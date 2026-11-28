/** طيّ عربي خفيف للبحث المحلي (همزات · ة/ه · ى/ي · بلا مسافات). */
export function smartSearchCompact(value: string): string {
  let s = value
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/هـ/g, 'ه')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .replace(/ؤ/g, 'و')
    .replace(/ئ/g, 'ي')
    .replace(/[ـء]/g, '')
  s = s.replace(/[\s\-_/.]+/g, '')
  return s
}

export function smartSearchIncludes(haystack: string, needle: string): boolean {
  const n = smartSearchCompact(needle)
  if (!n) return true
  return smartSearchCompact(haystack).includes(n)
}
