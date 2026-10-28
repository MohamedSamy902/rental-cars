import { useId, useState, type FormEvent, type ReactNode } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { useAuth } from '@/shared/auth/AuthContext'
import { BrandLogo, Button, useToast } from '@/shared/ui'
import { IconCheck, IconEye, IconEyeOff, IconLock, IconMail, IconZap } from '@/shared/ui/Icons'
import type { ApiError } from '@/shared/api/client'
import './LoginPage.css'

const REMEMBER_KEY = 'rpm.login.email'
const DEMO = { email: 'admin@rpm.local', password: 'password' }

function readRememberedEmail() {
  try {
    return localStorage.getItem(REMEMBER_KEY) ?? ''
  } catch {
    return ''
  }
}

function persistRememberedEmail(remember: boolean, email: string) {
  try {
    if (remember) localStorage.setItem(REMEMBER_KEY, email)
    else localStorage.removeItem(REMEMBER_KEY)
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
  inputMode?: 'email' | 'text'
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

export function LoginPage() {
  const { user, loading, login } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [email, setEmail] = useState(readRememberedEmail)
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(() => readRememberedEmail() !== '')
  const [showPassword, setShowPassword] = useState(false)
  const [forgotOpen, setForgotOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  if (!loading && user) return <Navigate to="/" replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      await login(email, password)
      persistRememberedEmail(remember, email)
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

  function fillDemo() {
    setEmail(DEMO.email)
    setPassword(DEMO.password)
    setErrors({})
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
            <p>أدخل بيانات حسابك للوصول إلى لوحة التشغيل.</p>
          </div>

          <button type="button" className="auth-demo" onClick={fillDemo}>
            <span className="auth-demo__icon" aria-hidden>
              <IconZap size={14} />
            </span>
            <span className="auth-demo__text">
              <strong>تعبئة الحساب التجريبي</strong>
              <span dir="ltr">{DEMO.email}</span>
            </span>
          </button>

          <AuthInput
            label="البريد الإلكتروني"
            icon={<IconMail size={18} />}
            type="email"
            inputMode="email"
            autoComplete="username"
            value={email}
            error={errors.email}
            onChange={setEmail}
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
            <button
              type="button"
              className="auth-link"
              aria-expanded={forgotOpen}
              aria-controls="auth-forgot"
              onClick={() => setForgotOpen((v) => !v)}
            >
              نسيت كلمة المرور؟
            </button>
          </div>

          <AnimatePresence initial={false}>
            {forgotOpen ? (
              <motion.p
                id="auth-forgot"
                className="auth-note"
                role="status"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: 0.18 }}
              >
                لإعادة تعيين كلمة المرور تواصل مع مدير النظام في فرعك.
              </motion.p>
            ) : null}
          </AnimatePresence>

          <Button type="submit" variant="accent" size="lg" loading={saving} className="auth-submit">
            دخول
          </Button>
        </div>
      </motion.form>

      <p className="auth-foot">
        RPM Rent A Car · <span>منصة التشغيل</span>
      </p>
    </div>
  )
}
