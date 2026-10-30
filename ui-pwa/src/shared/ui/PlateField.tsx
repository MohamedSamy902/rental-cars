import { FieldShell } from './Field'
import './Field.css'
import './PlateField.css'

type Props = {
  label?: string
  required?: boolean
  error?: string
  hint?: string
  letters: string
  numbers: string
  onLettersChange: (value: string) => void
  onNumbersChange: (value: string) => void
}

/** Egyptian-style plate: 2–3 letters + numeric block. */
export function PlateField({
  label = 'رقم اللوحة',
  required,
  error,
  hint,
  letters,
  numbers,
  onLettersChange,
  onNumbersChange,
}: Props) {
  return (
    <FieldShell label={label} required={required} error={error} hint={hint || 'حرفان أو ثلاثة + الأرقام'}>
      <div className="rpm-plate" dir="ltr">
        <input
          className="rpm-input rpm-plate__letters"
          value={letters}
          maxLength={3}
          inputMode="text"
          autoComplete="off"
          aria-label="حروف اللوحة"
          placeholder="أ ب ج"
          onChange={(e) => {
            const next = e.target.value.replace(/[^\p{L}]/gu, '').slice(0, 3)
            onLettersChange(next)
          }}
        />
        <span className="rpm-plate__sep" aria-hidden>
          ·
        </span>
        <input
          className="rpm-input rpm-plate__numbers"
          value={numbers}
          maxLength={4}
          inputMode="numeric"
          autoComplete="off"
          aria-label="أرقام اللوحة"
          placeholder="1234"
          onChange={(e) => {
            const next = e.target.value.replace(/\D+/g, '').slice(0, 4)
            onNumbersChange(next)
          }}
        />
      </div>
    </FieldShell>
  )
}

export function splitPlate(plate: string): { letters: string; numbers: string } {
  const raw = (plate || '').trim()
  if (!raw) return { letters: '', numbers: '' }
  const numbers = (raw.match(/\d+/g) || []).join('')
  const letters = raw.replace(/\d+/g, '').replace(/[^\p{L}]+/gu, '')
  return { letters, numbers }
}

export function joinPlate(letters: string, numbers: string): string {
  const l = letters.trim()
  const n = numbers.replace(/\D+/g, '')
  if (!l && !n) return ''
  return `${l} ${n}`.trim()
}
