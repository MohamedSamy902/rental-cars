import type { InputHTMLAttributes, ReactNode, TextareaHTMLAttributes } from 'react'
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

export function TextField({ label, error, hint, required, id, className = '', ...rest }: TextProps) {
  const fieldId = id ?? rest.name
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <input id={fieldId} className={`rpm-input ${className}`.trim()} required={required} {...rest} />
    </FieldShell>
  )
}

type NumberProps = TextProps

export function NumberField(props: NumberProps) {
  return <TextField inputMode="decimal" {...props} type="number" />
}

/** Native date input styled like other dashboard fields. */
export function DateField(props: TextProps) {
  return <TextField {...props} type="date" className={`rpm-input--date ${props.className || ''}`.trim()} />
}

/**
 * Date-only field (platform standard: YYYY-MM-DD).
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
}: TextProps) {
  const fieldId = id ?? name
  const raw = typeof value === 'string' ? value : ''
  const datePart = raw.slice(0, 10)

  function emit(nextDate: string) {
    if (!onChange) return
    onChange({ target: { value: nextDate, name: name || '' } } as never)
  }

  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <input
        id={fieldId}
        className="rpm-input rpm-input--date"
        type="date"
        disabled={disabled}
        required={required}
        value={datePart}
        aria-label={label}
        onChange={(e) => emit(e.target.value)}
      />
    </FieldShell>
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
