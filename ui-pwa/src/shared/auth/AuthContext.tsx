import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, getToken, setToken } from '@/shared/api/client'

export type AuthUser = {
  id: number
  name: string
  email: string
  role?: string | null
  role_name?: string | null
  permissions?: string[]
  is_integrator?: boolean
}

type AuthApi = {
  user: AuthUser | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  can: (permission: string) => boolean
  isIntegrator: boolean
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
    return {
      user,
      loading,
      isIntegrator,
      can(permission: string) {
        if (!user) return false
        if (isIntegrator) return true
        return perms.includes(permission)
      },
      async login(email, password) {
        const res = await api<{ token: string; user: AuthUser }>('/login', {
          method: 'POST',
          json: { email, password },
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
  }, [user, loading])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
