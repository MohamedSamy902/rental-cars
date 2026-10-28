import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '@/shared/auth/AuthContext'
import { BrandLogo, Button, TextField, useToast } from '@/shared/ui'
import type { ApiError } from '@/shared/api/client'
import './pages.css'

export function LoginPage() {
  const { user, loading, login } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [email, setEmail] = useState('admin@rpm.local')
  const [password, setPassword] = useState('password')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  if (!loading && user) return <Navigate to="/" replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      await login(email, password)
      toast.success('مرحباً بك في آر بي إم')
      navigate('/')
    } catch (err) {
      const apiErr = err as ApiError
      const fieldErrors: Record<string, string> = {}
      if (apiErr.errors) {
        for (const [k, v] of Object.entries(apiErr.errors)) fieldErrors[k] = v[0]
      }
      setErrors(fieldErrors)
      toast.error(apiErr.message || 'تعذّر الدخول')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rpm-login">
      <form className="rpm-login__card" onSubmit={onSubmit}>
        <div className="rpm-login__brand">
          <BrandLogo variant="full" />
          <span>نظام إدارة تأجير السيارات</span>
        </div>
        <h1>تسجيل الدخول</h1>
        <p className="muted">واجهة التشغيل العربية — فرع واحد افتراضياً</p>
        <TextField
          label="البريد الإلكتروني"
          required
          type="email"
          value={email}
          error={errors.email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <TextField
          label="كلمة المرور"
          required
          type="password"
          value={password}
          error={errors.password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <Button type="submit" size="lg" loading={saving}>
          دخول
        </Button>
        <p className="rpm-login__hint">تجريبي: admin@rpm.local / password</p>
      </form>
    </div>
  )
}
