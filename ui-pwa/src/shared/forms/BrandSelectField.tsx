import { useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { Button, SelectField, TextField, useToast } from '@/shared/ui'

const NEW_VALUE = '__new_brand__'

type Brand = { id: number; name: string }

type Props = {
  brands: Brand[]
  value: string
  required?: boolean
  error?: string
  disabled?: boolean
  onChange: (brandName: string) => void
  onBrandsChange: (brands: Brand[]) => void
}

/**
 * Master-list brand dropdown with inline “add brand” (stay on the vehicle form).
 */
export function BrandSelectField({
  brands,
  value,
  required,
  error,
  disabled,
  onChange,
  onBrandsChange,
}: Props) {
  const toast = useToast()
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [fieldError, setFieldError] = useState('')
  const [saving, setSaving] = useState(false)

  const selectValue = adding ? NEW_VALUE : value
  const options = [
    ...brands.map((b) => ({ value: b.name, label: b.name })),
    { value: NEW_VALUE, label: '＋ إضافة ماركة جديدة…' },
  ]

  async function saveBrand() {
    const trimmed = name.trim()
    if (!trimmed) {
      setFieldError('أدخل اسم الماركة.')
      return
    }
    setSaving(true)
    setFieldError('')
    try {
      const res = await api<{ data: Brand; message?: string }>('/vehicles/brands', {
        method: 'POST',
        json: { name: trimmed },
      })
      const brand = res.data
      const next = brands.some((b) => b.id === brand.id)
        ? brands.map((b) => (b.id === brand.id ? brand : b))
        : [...brands, brand].sort((a, b) => a.name.localeCompare(b.name, 'ar'))
      onBrandsChange(next)
      onChange(brand.name)
      setAdding(false)
      setName('')
      toast.success(res.message || 'تم إضافة الماركة للقائمة.')
    } catch (e) {
      const err = e as ApiError
      setFieldError(err.errors?.name?.[0] || err.message)
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <SelectField
        label="الماركة"
        required={required}
        error={error}
        disabled={disabled}
        value={selectValue}
        hint={adding ? undefined : 'إن لم تجد الماركة اختر «إضافة ماركة جديدة»'}
        options={options}
        onChange={(e) => {
          const next = e.target.value
          if (next === NEW_VALUE) {
            setAdding(true)
            setName('')
            setFieldError('')
            return
          }
          setAdding(false)
          setName('')
          setFieldError('')
          onChange(next)
        }}
      />
      {adding ? (
        <>
          <TextField
            label="اسم الماركة الجديدة"
            required
            autoFocus
            value={name}
            error={fieldError}
            hint="تُحفظ في قائمة الماركات الرئيسية وتظهر فوراً للاختيار"
            onChange={(e) => {
              setName(e.target.value)
              setFieldError('')
            }}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                void saveBrand()
              }
            }}
          />
          <div className="rpm-card__actions" style={{ gridColumn: '1 / -1' }}>
            <Button size="sm" loading={saving} onClick={() => void saveBrand()}>حفظ الماركة</Button>
            <Button
              size="sm"
              variant="secondary"
              disabled={saving}
              onClick={() => {
                setAdding(false)
                setName('')
                setFieldError('')
              }}
            >
              إلغاء
            </Button>
          </div>
        </>
      ) : null}
    </>
  )
}
