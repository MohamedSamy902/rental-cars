import { useRef, type KeyboardEvent } from 'react'
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

/** Egyptian traffic-portal plate: up to 3 letter cells + numeric block. */
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
  const letterRefs = useRef<Array<HTMLInputElement | null>>([null, null, null])
  const numberRef = useRef<HTMLInputElement | null>(null)
  const cells = [0, 1, 2].map((i) => letters[i] || '')

  function setLetterAt(index: number, raw: string) {
    const ch = raw.replace(/[^\p{L}]/gu, '').slice(-1)
    const next = [letters[0] || '', letters[1] || '', letters[2] || '']
    next[index] = ch
    // Compact trailing empties but keep gaps only if middle cleared intentionally
    const compact = next.join('').slice(0, 3)
    onLettersChange(compact)
    if (ch) {
      if (index < 2) letterRefs.current[index + 1]?.focus()
      else numberRef.current?.focus()
    }
  }

  function onLetterKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !cells[index] && index > 0) {
      e.preventDefault()
      const next = [letters[0] || '', letters[1] || '', letters[2] || '']
      next[index - 1] = ''
      onLettersChange(next.join('').replace(/\s+/g, ''))
      letterRefs.current[index - 1]?.focus()
    }
  }

  return (
    <FieldShell label={label} required={required} error={error} hint={hint || 'حرف لكل خانة (٢–٣) ثم الأرقام'}>
      {/* RTL: letters sit on the inline-start (right), numbers after (left) — Egyptian portal order */}
      <div className="rpm-plate" dir="rtl">
        <div className="rpm-plate__letters" role="group" aria-label="حروف اللوحة" dir="rtl">
          {[0, 1, 2].map((i) => (
            <input
              key={i}
              ref={(el) => { letterRefs.current[i] = el }}
              className="rpm-input rpm-plate__cell"
              value={cells[i]}
              maxLength={1}
              inputMode="text"
              autoComplete="off"
              aria-label={`حرف اللوحة ${i + 1}`}
              placeholder="ـ"
              onChange={(e) => setLetterAt(i, e.target.value)}
              onKeyDown={(e) => onLetterKeyDown(i, e)}
            />
          ))}
        </div>
        <span className="rpm-plate__sep" aria-hidden>
          ·
        </span>
        <input
          ref={numberRef}
          className="rpm-input rpm-plate__numbers"
          dir="ltr"
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
