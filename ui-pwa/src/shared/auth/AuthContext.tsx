import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, getToken, setToken } from '@/shared/api/client'
import { normalizePermissions, type PermissionRef } from './routeAccess'

export type AuthUser = {
  id: number
  name: string
  email: string
  role?: string | null
  role_name?: string | null
  permissions?: string[]
  is_integrator?: boolean
  feature_flags?: Record<string, boolean>
}

type AuthApi = {
  user: AuthUser | null
  loading: boolean
  login: (email: string, password: string) => Promise<AuthUser>
  logout: () => Promise<void>
  /** True if the user has any of the listed permissions (integrator bypasses). */
  can: (permission: PermissionRef) => boolean
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

  const value = useMemo<AuthApi>(() => {
    const isIntegrator = Boolean(user?.is_integrator || user?.role_name === 'super_admin')
    const perms = user?.permissions || []
    const featureFlags = user?.feature_flags || {}
    return {
      user,
      loading,
      isIntegrator,
      featureFlags,
      can(permission: PermissionRef) {
        if (!user) return false
        if (isIntegrator) return true
        return normalizePermissions(permission).some((key) => perms.includes(key))
      },
      featureEnabled(flag: string) {
        if (!(flag in featureFlags)) return true
        return Boolean(featureFlags[flag])
      },
      async login(email, password) {
        const res = await api<{ token: string; user: AuthUser }>('/login', {
          method: 'POST',
          json: { email, password },
        })
        setToken(res.token)
        setUser(res.user)
        return res.user
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
  }, [user, loading])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
