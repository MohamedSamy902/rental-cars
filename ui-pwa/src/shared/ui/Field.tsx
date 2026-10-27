import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import './Field.css'

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

type MoneyProps = TextProps

export function MoneyField({ hint = 'ج.م', ...props }: MoneyProps) {
  return <NumberField hint={hint} {...props} />
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

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & {
  label: string
  error?: string
  hint?: string
  options: { value: string; label: string }[]
  placeholder?: string
}

export function SelectField({
  label,
  error,
  hint,
  required,
  id,
  options,
  placeholder = 'اختر…',
  className = '',
  ...rest
}: SelectProps) {
  const fieldId = id ?? rest.name
  return (
    <FieldShell label={label} required={required} error={error} hint={hint} htmlFor={fieldId}>
      <select id={fieldId} className={`rpm-input rpm-select ${className}`.trim()} required={required} {...rest}>
        <option value="">{placeholder}</option>
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
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
