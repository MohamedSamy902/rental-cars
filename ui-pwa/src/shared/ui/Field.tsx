import { useId, useState, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react'
import {
  civilDateOnly,
  civilMonthOnly,
  isoToDisplayDate,
  isoToDisplayMonth,
  maskDisplayDateInput,
  maskDisplayMonthInput,
  monthToStorageDate,
  parseDisplayDate,
  parseDisplayMonth,
} from '@/shared/lib/dates'
import './Field.css'

export { SelectField } from './SelectField'

type FieldShellProps = {
  label: string
  required?: boolean
  error?: string
  hint?: string
  children: ReactNode
  htmlFor?: string
}

export function FieldShell({ label, required, error, hint, children, htmlFor }: FieldShellProps) {
  return (
    <label className={`rpm-field ${error ? 'has-error' : ''}`} htmlFor={htmlFor}>
      <span className="rpm-field__label">
        {label}
        {required ? <span className="rpm-field__req" aria-hidden>*</span> : null}
      </span>
      {children}
      {error ? <span className="rpm-field__error">{error}</span> : null}
      {!error && hint ? <span className="rpm-field__hint">{hint}</span> : null}
    </label>
  )
}

type TextProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string
  error?: string
  hint?: string
}

export function TextField({ label, error, hint, required, id, className = '', type, ...rest }: TextProps) {
  if (type === 'date') {
    return (
      <DateField
        label={label}
        error={error}
        hint={hint}
        required={required}
        id={id}
        className={className}
        {...rest}
      />
    )
  }
  if (type === 'month') {
    return (
      <MonthYearField
        label={label}
        error={error}
        hint={hint}
        required={required}
        id={id}
        className={className}
        {...rest}
      />
    )
  }

  const fieldId = id ?? rest.name
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <input id={fieldId} type={type} className={`rpm-input ${className}`.trim()} required={required} {...rest} />
    </FieldShell>
  )
}

type NumberProps = TextProps

export function NumberField(props: NumberProps) {
  return <TextField inputMode="decimal" {...props} type="number" />
}

/**
 * Date field: visible DD/MM/YYYY (day/month/year), storage/API YYYY-MM-DD.
 * Native calendar is a picker affordance only — never the visible format.
 */
export function DateField({
  label,
  error,
  hint,
  required,
  id,
  className = '',
  value,
  onChange,
  disabled,
  name,
  min,
  max,
}: TextProps) {
  const autoId = useId()
  const fieldId = id ?? name ?? autoId
  const iso = civilDateOnly(typeof value === 'string' ? value : '')
  const [draft, setDraft] = useState<string | null>(null)
  const text = draft !== null ? draft : isoToDisplayDate(iso)

  function emitIso(nextIso: string) {
    if (!onChange) return
    onChange({ target: { value: nextIso, name: name || '' } } as never)
  }

  function commitText(raw: string) {
    const trimmed = raw.trim()
    if (!trimmed) {
      setDraft(null)
      emitIso('')
      return
    }
    const parsed = parseDisplayDate(trimmed)
    if (parsed) {
      setDraft(null)
      emitIso(parsed)
      return
    }
    setDraft(null)
  }

  const minIso = typeof min === 'string' ? civilDateOnly(min) : undefined
  const maxIso = typeof max === 'string' ? civilDateOnly(max) : undefined

  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <div className={`rpm-date ${className}`.trim()}>
        <input
          id={fieldId}
          className="rpm-input rpm-input--date rpm-date__text"
          type="text"
          inputMode="numeric"
          autoComplete="off"
          placeholder="DD/MM/YYYY"
          disabled={disabled}
          required={required}
          name={name}
          value={text}
          aria-label={label}
          onFocus={() => setDraft(isoToDisplayDate(iso))}
          onChange={(e) => setDraft(maskDisplayDateInput(e.target.value))}
          onBlur={() => commitText(draft ?? text)}
        />
        <span className="rpm-date__cal" aria-hidden={false}>
          <svg className="rpm-date__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden>
            <path
              fill="currentColor"
              d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1.5A2.5 2.5 0 0 1 22 6.5v13A2.5 2.5 0 0 1 19.5 22h-15A2.5 2.5 0 0 1 2 19.5v-13A2.5 2.5 0 0 1 4.5 4H6V3a1 1 0 0 1 1-1Zm12.5 8h-15v9.5a.5.5 0 0 0 .5.5h14a.5.5 0 0 0 .5-.5V10Zm-15-2h15V6.5a.5.5 0 0 0-.5-.5H4.5a.5.5 0 0 0-.5.5V8Z"
            />
          </svg>
          <input
            className="rpm-date__native"
            type="date"
            tabIndex={-1}
            disabled={disabled}
            value={iso}
            min={minIso || undefined}
            max={maxIso || undefined}
            aria-label={`${label} — اختيار من التقويم`}
            onChange={(e) => {
              const next = e.target.value
              setDraft(null)
              emitIso(next)
            }}
          />
        </span>
      </div>
    </FieldShell>
  )
}

/**
 * Month/year field: visible MM/YYYY, storage/API YYYY-MM-01 (day always 01).
 * Native month picker is affordance only — never the visible format.
 */
export function MonthYearField({
  label,
  error,
  hint,
  required,
  id,
  className = '',
  value,
  onChange,
  disabled,
  name,
  min,
  max,
}: TextProps) {
  const autoId = useId()
  const fieldId = id ?? name ?? autoId
  const month = civilMonthOnly(typeof value === 'string' ? value : '')
  const [draft, setDraft] = useState<string | null>(null)
  const text = draft !== null ? draft : isoToDisplayMonth(month)

  function emitStorage(nextMonth: string) {
    if (!onChange) return
    const next = nextMonth ? monthToStorageDate(nextMonth) : ''
    onChange({ target: { value: next, name: name || '' } } as never)
  }

  function commitText(raw: string) {
    const trimmed = raw.trim()
    if (!trimmed) {
      setDraft(null)
      emitStorage('')
      return
    }
    const parsed = parseDisplayMonth(trimmed)
    if (parsed) {
      setDraft(null)
      emitStorage(parsed)
      return
    }
    setDraft(null)
  }

  const minMonth = typeof min === 'string' ? civilMonthOnly(min) : undefined
  const maxMonth = typeof max === 'string' ? civilMonthOnly(max) : undefined

  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <div className={`rpm-date ${className}`.trim()}>
        <input
          id={fieldId}
          className="rpm-input rpm-input--date rpm-date__text"
          type="text"
          inputMode="numeric"
          autoComplete="off"
          placeholder="MM/YYYY"
          disabled={disabled}
          required={required}
          name={name}
          value={text}
          aria-label={label}
          onFocus={() => setDraft(isoToDisplayMonth(month))}
          onChange={(e) => setDraft(maskDisplayMonthInput(e.target.value))}
          onBlur={() => commitText(draft ?? text)}
        />
        <span className="rpm-date__cal" aria-hidden={false}>
          <svg className="rpm-date__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden>
            <path
              fill="currentColor"
              d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1.5A2.5 2.5 0 0 1 22 6.5v13A2.5 2.5 0 0 1 19.5 22h-15A2.5 2.5 0 0 1 2 19.5v-13A2.5 2.5 0 0 1 4.5 4H6V3a1 1 0 0 1 1-1Zm12.5 8h-15v9.5a.5.5 0 0 0 .5.5h14a.5.5 0 0 0 .5-.5V10Zm-15-2h15V6.5a.5.5 0 0 0-.5-.5H4.5a.5.5 0 0 0-.5.5V8Z"
            />
          </svg>
          <input
            className="rpm-date__native"
            type="month"
            tabIndex={-1}
            disabled={disabled}
            value={month}
            min={minMonth || undefined}
            max={maxMonth || undefined}
            aria-label={`${label} — اختيار الشهر`}
            onChange={(e) => {
              const next = e.target.value
              setDraft(null)
              emitStorage(next)
            }}
          />
        </span>
      </div>
    </FieldShell>
  )
}

/**
 * Date-only field (storage/input: YYYY-MM-DD · display: DD/MM/YYYY).
 * Kept as DateTimeField for call-site compatibility; time is always midnight.
 * Value format accepted: `YYYY-MM-DD` or `YYYY-MM-DDTHH:mm` (time ignored).
 */
export function DateTimeField({
  label,
  error,
  hint,
  required,
  id,
  value,
  onChange,
  disabled,
  name,
  min,
  max,
}: TextProps) {
  const raw = typeof value === 'string' ? value : ''
  const datePart = civilDateOnly(raw)

  return (
    <DateField
      label={label}
      error={error}
      hint={hint}
      required={required}
      id={id}
      name={name}
      disabled={disabled}
      min={min}
      max={max}
      value={datePart}
      onChange={(e) => {
        if (!onChange) return
        onChange({ target: { value: e.target.value, name: name || '' } } as never)
      }}
    />
  )
}

type MoneyProps = TextProps & {
  /** Currency unit rendered inside the input (inline-start / visual left in RTL). */
  unit?: string
}

export function MoneyField({
  label,
  error,
  hint,
  required,
  id,
  className = '',
  unit = 'ج.م',
  ...rest
}: MoneyProps) {
  const fieldId = id ?? rest.name
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <div className="rpm-money">
        <input
          id={fieldId}
          className={`rpm-input rpm-money__input ${className}`.trim()}
          required={required}
          inputMode="decimal"
          type="number"
          {...rest}
        />
        <span className="rpm-money__unit" aria-hidden>
          {unit}
        </span>
      </div>
    </FieldShell>
  )
}

type AreaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & {
  label: string
  error?: string
  hint?: string
}

export function TextAreaField({ label, error, hint, required, id, className = '', ...rest }: AreaProps) {
  const fieldId = id ?? rest.name
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <textarea id={fieldId} className={`rpm-input rpm-textarea ${className}`.trim()} required={required} {...rest} />
    </FieldShell>
  )
}

type SwitchProps = {
  label: string
  checked: boolean
  onChange: (next: boolean) => void
  name?: string
}

export function SwitchField({ label, checked, onChange, name }: SwitchProps) {
  return (
    <label className="rpm-switch">
      <input
        type="checkbox"
        name={name}
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
      />
      <span className="rpm-switch__track" aria-hidden />
      <span className="rpm-switch__label">{label}</span>
    </label>
  )
}
