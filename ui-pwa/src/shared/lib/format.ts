const egp = new Intl.NumberFormat('ar-EG', {
  style: 'currency',
  currency: 'EGP',
  maximumFractionDigits: 0,
})

const numberAr = new Intl.NumberFormat('ar-EG')

export function formatMoney(value: number): string {
  return egp.format(value)
}

export function formatNumber(value: number): string {
  return numberAr.format(value)
}

export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat('ar-EG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(iso))
}
