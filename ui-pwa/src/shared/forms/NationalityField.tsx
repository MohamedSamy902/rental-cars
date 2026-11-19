import { useId, type InputHTMLAttributes } from 'react'
import { TextField } from '@/shared/ui'
import {
  DEFAULT_NATIONALITY,
  NATIONALITY_DATALIST_ID,
  NATIONALITY_SUGGESTIONS,
} from '@/shared/lib/nationalities'

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'list' | 'value' | 'onChange'> & {
  label?: string
  value: string
  error?: string
  hint?: string
  required?: boolean
  onChange: (value: string) => void
}

/**
 * Free-text nationality with optional suggestion list (write any nationality).
 */
export function NationalityField({
  label = 'الجنسية',
  value,
  error,
  required = true,
  onChange,
  hint = 'اكتب الجنسية كما هي — أو اختر من الاقتراحات',
  id,
  ...rest
}: Props) {
  const autoId = useId()
  const fieldId = id || autoId
  const listId = `${NATIONALITY_DATALIST_ID}-${fieldId}`

  return (
    <>
      <TextField
        id={fieldId}
        label={label}
        required={required}
        error={error}
        hint={hint}
        value={value}
        list={listId}
        autoComplete="off"
        placeholder={DEFAULT_NATIONALITY}
        onChange={(e) => onChange(e.target.value)}
        {...rest}
      />
      <datalist id={listId}>
        {NATIONALITY_SUGGESTIONS.map((n) => (
          <option key={n} value={n} />
        ))}
      </datalist>
    </>
  )
}
