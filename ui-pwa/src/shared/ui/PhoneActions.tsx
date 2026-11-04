import { IconPhone } from './Icons'
import './PhoneActions.css'

type Props = {
  phone?: string | null
  /** Optional display name for aria labels */
  name?: string | null
  /** Compact = icon-only on narrow rows; default shows labels */
  compact?: boolean
  className?: string
}

/** Normalize Egyptian / international numbers for tel: and WhatsApp. */
export function normalizePhoneDigits(phone: string): string {
  const trimmed = phone.trim()
  if (!trimmed) return ''
  let digits = trimmed.replace(/[^\d+]/g, '')
  if (digits.startsWith('+')) digits = digits.slice(1)
  if (digits.startsWith('00')) digits = digits.slice(2)
  if (digits.startsWith('0') && digits.length === 11) digits = `20${digits.slice(1)}`
  return digits.replace(/\D/g, '')
}

export function telHref(phone: string): string {
  const digits = normalizePhoneDigits(phone)
  return digits ? `tel:+${digits}` : `tel:${phone.trim()}`
}

export function whatsappHref(phone: string): string {
  const digits = normalizePhoneDigits(phone)
  return digits ? `https://wa.me/${digits}` : '#'
}

/** Direct one-click call + WhatsApp for customer/owner phones. */
export function PhoneActions({ phone, name, compact = false, className = '' }: Props) {
  if (!phone?.trim()) {
    return <span className={`rpm-phone-actions rpm-phone-actions--empty ${className}`.trim()}>—</span>
  }

  const label = name ? `الاتصال بـ ${name}` : 'اتصال مباشر'
  const waLabel = name ? `واتساب ${name}` : 'واتساب'

  return (
    <span className={`rpm-phone-actions ${compact ? 'rpm-phone-actions--compact' : ''} ${className}`.trim()}>
      <a
        className="rpm-phone-actions__call"
        href={telHref(phone)}
        aria-label={label}
        title={label}
      >
        <IconPhone size={14} />
        {!compact ? <span>اتصال مباشر</span> : null}
        <span className="rpm-phone-actions__number" dir="ltr">{phone}</span>
      </a>
      <a
        className="rpm-phone-actions__wa"
        href={whatsappHref(phone)}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={waLabel}
        title={waLabel}
      >
        <IconWhatsApp size={14} />
        {!compact ? <span>واتساب</span> : null}
      </a>
    </span>
  )
}

function IconWhatsApp({ size = 18, className }: { size?: number; className?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      aria-hidden
    >
      <path d="M12.04 2c-5.46 0-9.91 4.43-9.91 9.88 0 1.74.46 3.45 1.34 4.95L2 22l5.33-1.39a9.9 9.9 0 0 0 4.71 1.2h.01c5.46 0 9.9-4.43 9.9-9.88A9.82 9.82 0 0 0 12.04 2zm5.78 14.06c-.24.68-1.42 1.26-1.97 1.34-.5.07-1.14.1-1.84-.12-.42-.13-.97-.28-1.67-.55-2.94-1.27-4.85-4.23-5-4.42-.14-.2-1.18-1.57-1.18-3 0-1.42.74-2.12 1-2.41.26-.29.57-.36.76-.36h.55c.18 0 .41-.07.64.49.24.58.82 2 .89 2.15.07.15.12.32.02.51-.1.2-.15.32-.3.49-.15.17-.31.38-.45.51-.15.15-.3.31-.13.6.17.29.76 1.25 1.63 2.03 1.12 1 2.07 1.31 2.36 1.46.29.15.46.12.63-.07.17-.2.74-.86.94-1.16.2-.29.4-.24.67-.15.27.1 1.72.81 2.01.96.29.15.49.22.56.34.08.13.08.74-.16 1.42z" />
    </svg>
  )
}
