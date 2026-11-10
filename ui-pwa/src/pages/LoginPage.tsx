import { useId, useState, type FormEvent, type ReactNode } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import { useAuth } from '@/shared/auth/AuthContext'
import { BrandLogo, Button, useToast } from '@/shared/ui'
import { IconCheck, IconEye, IconEyeOff, IconLock, IconPhone } from '@/shared/ui/Icons'
import type { ApiError } from '@/shared/api/client'
import './LoginPage.css'

const REMEMBER_KEY = 'rpm.login.identifier'

/**
 * Local-only prefill. Production builds drop this because import.meta.env.DEV is false.
 * It stays empty unless a developer opts in with VITE_ENABLE_DEMO_LOGIN and supplies
 * both values in an uncommitted env file. Nothing here is a default credential.
 */
function devDemoPrefill(): { login: string; password: string } | null {
  if (!import.meta.env.DEV) return null
  if (import.meta.env.VITE_ENABLE_DEMO_LOGIN !== 'true') return null
  const login = import.meta.env.VITE_DEMO_LOGIN
  const secret = import.meta.env.VITE_DEMO_PASSWORD
  if (typeof login !== 'string' || typeof secret !== 'string') return null
  const trimmedLogin = login.trim()
  if (trimmedLogin === '' || secret === '') return null
  return { login: trimmedLogin, password: secret }
}

function readRememberedLogin() {
  try {
    return localStorage.getItem(REMEMBER_KEY) ?? localStorage.getItem('rpm.login.email') ?? ''
  } catch {
    return ''
  }
}

function persistRememberedLogin(remember: boolean, login: string) {
  try {
    if (remember) localStorage.setItem(REMEMBER_KEY, login)
    else {
      localStorage.removeItem(REMEMBER_KEY)
      localStorage.removeItem('rpm.login.email')
    }
  } catch {
    /* private mode */
  }
}

type AuthInputProps = {
  label: string
  icon: ReactNode
  type: string
  value: string
  autoComplete: string
  error?: string
  onChange: (value: string) => void
  trailing?: ReactNode
  inputMode?: 'email' | 'text' | 'tel'
}

function AuthInput({ label, icon, type, value, autoComplete, error, onChange, trailing, inputMode }: AuthInputProps) {
  const id = useId()
  const errorId = `${id}-error`
  return (
    <div className={`auth-field ${error ? 'has-error' : ''}`}>
      <label className="auth-field__label" htmlFor={id}>
        {label}
      </label>
      <div className="auth-field__control">
        <span className="auth-field__icon" aria-hidden>
          {icon}
        </span>
        <input
          id={id}
          className="auth-field__input"
          type={type}
          dir="ltr"
          required
          value={value}
          autoComplete={autoComplete}
          inputMode={inputMode}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          onChange={(e) => onChange(e.target.value)}
        />
        {trailing}
      </div>
      {error ? (
        <span className="auth-field__error" id={errorId}>
          {error}
        </span>
      ) : null}
    </div>
  )
}

function safeNextPath(raw: string | null): string {
  if (!raw) return '/'
  const path = raw.trim()
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('://')) return '/'
  return path
}

export function LoginPage() {
  const { user, loading, login } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const nextPath = safeNextPath(params.get('next'))
  const toast = useToast()
  const demo = devDemoPrefill()
  const [loginId, setLoginId] = useState(() => readRememberedLogin() || demo?.login || '')
  const [password, setPassword] = useState(() => demo?.password || '')
  const [remember, setRemember] = useState(() => readRememberedLogin() !== '')
  const [showPassword, setShowPassword] = useState(false)
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState('')

  if (!loading && user) return <Navigate to={nextPath} replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError('')
    try {
      await login(loginId, password)
      persistRememberedLogin(remember, loginId.trim())
      toast.success('مرحباً بك في آر بي إم')
      navigate(nextPath)
    } catch (err) {
      const apiErr = err as ApiError
      const topMessage = apiErr.message || 'تعذّر الدخول. تحقق من الموبايل/البريد وكلمة المرور.'
      // Auth failures stay in the top alert only — avoid duplicating the same text under fields.
      const fieldErrors: Record<string, string> = {}
      if (apiErr.errors) {
        for (const [k, v] of Object.entries(apiErr.errors)) {
          if (v[0] && v[0] !== topMessage) {
            fieldErrors[k === 'email' || k === 'phone' ? 'login' : k] = v[0]
          }
        }
      }
      setErrors(fieldErrors)
      setFormError(topMessage)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="auth">
      <motion.form
        className="auth-card"
        onSubmit={onSubmit}
        noValidate={false}
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ type: 'spring', stiffness: 260, damping: 28 }}
      >
        <header className="auth-card__head">
          <BrandLogo variant="full" bare />
          <span className="auth-card__tagline">نظام إدارة تأجير السيارات</span>
        </header>

        <div className="auth-card__body">
          <div className="auth-card__intro">
            <h1>تسجيل الدخول</h1>
            <p>ادخل برقم الموبايل للوصول إلى لوحة التشغيل. البريد الإلكتروني مقبول كبديل اختياري.</p>
          </div>

          {formError ? (
            <div className="auth-alert" role="alert">
              {formError}
            </div>
          ) : null}

          <AuthInput
            label="رقم الموبايل"
            icon={<IconPhone size={18} />}
            type="text"
            inputMode="tel"
            autoComplete="tel"
            value={loginId}
            error={errors.login || errors.phone || errors.email}
            onChange={setLoginId}
          />

          <AuthInput
            label="كلمة المرور"
            icon={<IconLock size={18} />}
            type={showPassword ? 'text' : 'password'}
            autoComplete="current-password"
            value={password}
            error={errors.password}
            onChange={setPassword}
            trailing={
              <button
                type="button"
                className="auth-field__toggle"
                aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                aria-pressed={showPassword}
                onClick={() => setShowPassword((v) => !v)}
              >
                {showPassword ? <IconEyeOff size={18} /> : <IconEye size={18} />}
              </button>
            }
          />

          <div className="auth-row">
            <label className="auth-check">
              <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
              <span className="auth-check__box" aria-hidden>
                <IconCheck size={12} />
              </span>
              <span>تذكرني</span>
            </label>
          </div>

          <Button type="submit" variant="accent" size="lg" loading={saving} className="auth-submit">
            دخول
          </Button>
        </div>
      </motion.form>
    </div>
  )
}
