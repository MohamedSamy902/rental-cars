import { type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { SkeletonBlock } from '@/shared/ui/Skeleton'
import { useAuth } from './AuthContext'
import {
  firstAccessiblePath,
  isRouteAllowed,
  type PermissionRef,
} from './routeAccess'

type GateProps = {
  permission?: PermissionRef
  feature?: string
  integratorOnly?: boolean
  /** Rendered when access is denied (default: hide). */
  fallback?: ReactNode
  children: ReactNode
}

/** Soft UI hide — API remains the authority. */
export function PermissionGate({
  permission,
  feature,
  integratorOnly,
  fallback = null,
  children,
}: GateProps) {
  const { can, featureEnabled, isIntegrator } = useAuth()
  if (integratorOnly && !isIntegrator) return <>{fallback}</>
  if (feature && !featureEnabled(feature)) return <>{fallback}</>
  if (permission && !can(permission)) return <>{fallback}</>
  return <>{children}</>
}

/**
 * Route-level guard: forbidden deep-links redirect to the first allowed page
 * instead of rendering a 403 ErrorState card.
 */
export function RequireRouteAccess({ children }: { children: ReactNode }) {
  const { can, featureEnabled, isIntegrator, loading, user } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div style={{ padding: '1.5rem', maxWidth: 720, marginInline: 'auto' }}>
        <SkeletonBlock rows={6} />
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace />

  const helpers = { can, featureEnabled, isIntegrator }
  if (!isRouteAllowed(location.pathname, helpers)) {
    const home = firstAccessiblePath(helpers)
    if (home === location.pathname) return null
    return <Navigate to={home} replace />
  }

  return <>{children}</>
}
