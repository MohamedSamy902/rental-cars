import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, getToken, setToken } from '@/shared/api/client'

export type AuthUser = {
  id: number
  name: string
  email: string
  phone?: string | null
  role?: string | null
  role_name?: string | null
  permissions?: string[]
  is_integrator?: boolean
  feature_flags?: Record<string, boolean>
}

type AuthApi = {
  user: AuthUser | null
  loading: boolean
  /** Login with email or Egyptian mobile phone. */
  login: (login: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Replace session user after self-profile save (keeps token; no logout). */
  applyUser: (next: AuthUser) => void
  /** Re-fetch GET /me into AuthContext. */
  refreshUser: () => Promise<AuthUser | null>
  can: (permission: string) => boolean
  featureEnabled: (flag: string) => boolean
  isIntegrator: boolean
  featureFlags: Record<string, boolean>
}

const AuthContext = createContext<AuthApi | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const token = getToken()
    if (!token) {
      setLoading(false)
      return
    }
    api<AuthUser>('/me')
      .then(setUser)
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    const onExpired = () => {
      setToken(null)
      setUser(null)
      setLoading(false)
    }
    window.addEventListener('rpm:auth-expired', onExpired)
    return () => window.removeEventListener('rpm:auth-expired', onExpired)
  }, [])

  const applyUser = useCallback((next: AuthUser) => {
    setUser(next)
  }, [])

  const refreshUser = useCallback(async () => {
    const token = getToken()
    if (!token) {
      setUser(null)
      return null
    }
    try {
      const next = await api<AuthUser>('/me')
      setUser(next)
      return next
    } catch {
      setToken(null)
      setUser(null)
      return null
    }
  }, [])

  const value = useMemo<AuthApi>(() => {
    const isIntegrator = Boolean(user?.is_integrator || user?.role_name === 'super_admin')
    const perms = user?.permissions || []
    const featureFlags = user?.feature_flags || {}
    return {
      user,
      loading,
      isIntegrator,
      featureFlags,
      applyUser,
      refreshUser,
      can(permission: string) {
        if (!user) return false
        if (isIntegrator) return true
        return perms.includes(permission)
      },
      featureEnabled(flag: string) {
        if (!(flag in featureFlags)) return true
        return Boolean(featureFlags[flag])
      },
      async login(login, password) {
        const identifier = login.trim()
        const payload = identifier.includes('@')
          ? { login: identifier, email: identifier, password }
          : { login: identifier, phone: identifier, password }
        const res = await api<{ token: string; user: AuthUser }>('/login', {
          method: 'POST',
          json: payload,
        })
        setToken(res.token)
        setUser(res.user)
      },
      async logout() {
        try {
          await api('/logout', { method: 'POST' })
        } catch {
          /* ignore */
        }
        setToken(null)
        setUser(null)
      },
    }
  }, [user, loading, applyUser, refreshUser])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
