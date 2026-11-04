import { useEffect, useState } from 'react'
import { api, type ApiError } from '@/shared/api/client'
import { useAuth, type AuthUser } from '@/shared/auth/AuthContext'
import { Badge, Button, Sheet, TextField, useToast } from '@/shared/ui'

type Props = {
  open: boolean
  onClose: () => void
}

type FormState = {
  name: string
  phone: string
  email: string
  current_password: string
  password: string
  password_confirmation: string
}

const emptyForm = (): FormState => ({
  name: '',
  phone: '',
  email: '',
  current_password: '',
  password: '',
  password_confirmation: '',
})

function formFromUser(user: AuthUser | null): FormState {
  return {
    name: user?.name || '',
    phone: user?.phone || '',
    email: user?.email || '',
    current_password: '',
    password: '',
    password_confirmation: '',
  }
}

export function ProfileSheet({ open, onClose }: Props) {
  const { user, applyUser } = useAuth()
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!open) return
    setForm(formFromUser(user))
    setErrors({})
  }, [open, user])

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      const payload: Record<string, string | null> = {
        name: form.name.trim(),
        phone: form.phone.trim(),
        email: form.email.trim() || null,
      }
      if (form.password) {
        payload.current_password = form.current_password
        payload.password = form.password
        payload.password_confirmation = form.password_confirmation
      }
      const res = await api<{ data: AuthUser; message?: string }>('/auth/profile', {
        method: 'PUT',
        json: payload,
      })
      applyUser(res.data)
      toast.success(res.message || 'تم تحديث بيانات حسابك بنجاح')
      onClose()
    } catch (e) {
      const err = e as ApiError
      const fieldErrors: Record<string, string> = {}
      if (err.errors) {
        for (const [key, msgs] of Object.entries(err.errors)) {
          fieldErrors[key] = msgs[0] || err.message
        }
      }
      setErrors(fieldErrors)
      toast.error(err.message || 'تعذّر تحديث بيانات الحساب.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Sheet
      open={open}
      title="تعديل بيانات حسابي"
      onClose={onClose}
      footer={(
        <>
          <Button loading={saving} onClick={() => void save()}>حفظ التعديلات</Button>
          <Button variant="secondary" onClick={onClose}>إلغاء</Button>
        </>
      )}
    >
      <div className="rpm-form-grid">
        <div className="rpm-field" style={{ gridColumn: '1 / -1' }}>
          <span className="rpm-field__label">الدور الوظيفي</span>
          <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '0.5rem', marginBlockStart: '0.35rem' }}>
            <Badge tone="steel" dot={false}>{user?.role || 'مستخدم'}</Badge>
            <span style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>
              للقراءة فقط — لا يمكن تغيير الدور من هنا
            </span>
          </div>
        </div>

        <TextField
          label="الاسم الكامل"
          required
          value={form.name}
          error={errors.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
        />
        <TextField
          label="رقم الموبايل"
          required
          type="tel"
          autoComplete="tel"
          inputMode="tel"
          value={form.phone}
          error={errors.phone}
          hint="الهوية الأساسية لتسجيل الدخول"
          onChange={(e) => setForm({ ...form, phone: e.target.value })}
        />
        <TextField
          label="البريد الإلكتروني"
          type="email"
          autoComplete="email"
          value={form.email}
          error={errors.email}
          hint="اختياري — يمكن تركه فارغاً"
          onChange={(e) => setForm({ ...form, email: e.target.value })}
        />
        <TextField
          label="كلمة المرور الحالية"
          type="password"
          autoComplete="current-password"
          value={form.current_password}
          error={errors.current_password}
          hint={form.password ? 'مطلوبة عند تغيير كلمة المرور' : 'اتركها فارغة إن لم تغيّر كلمة المرور'}
          onChange={(e) => setForm({ ...form, current_password: e.target.value })}
        />
        <TextField
          label="كلمة المرور الجديدة"
          type="password"
          autoComplete="new-password"
          value={form.password}
          error={errors.password}
          hint="اختيارية — ٨ أحرف على الأقل"
          onChange={(e) => setForm({ ...form, password: e.target.value })}
        />
        <TextField
          label="تأكيد كلمة المرور الجديدة"
          type="password"
          autoComplete="new-password"
          value={form.password_confirmation}
          error={errors.password_confirmation}
          onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
        />
      </div>
    </Sheet>
  )
}
