/**
 * Single source of truth for SPA route ↔ permission/feature access.
 * Keep aligned with backend `permission:` middleware on matching APIs.
 */

export type PermissionRef = string | readonly string[]

export type RouteAccess = {
  /** Path prefix (`/` is exact when `exact` is true). */
  path: string
  exact?: boolean
  /** Any-of when array. Omit only for integrator-only routes. */
  permission?: PermissionRef
  feature?: string
  integratorOnly?: boolean
}

/** Ordered preference for the post-login / forbidden-redirect landing page. */
export const HOME_CANDIDATES = [
  '/',
  '/maintenance',
  '/bookings',
  '/vehicles',
  '/calendar',
  '/customers',
  '/inventory',
  '/petty-cash',
  '/expenses',
  '/accounts',
  '/reports',
  '/hr',
  '/owners',
  '/violations',
  '/waitlist',
  '/showroom',
  '/notifications',
  '/settings',
  '/staff',
  '/chat',
] as const

/**
 * Longest-prefix wins (except exact `/`).
 * Detail routes inherit the parent entry (e.g. `/accounts/months/1` → `/accounts`).
 */
export const ROUTE_ACCESS: readonly RouteAccess[] = [
  { path: '/', exact: true, permission: ['reports.view', 'contracts.view', 'vehicles.view'] },
  { path: '/vehicles', permission: 'vehicles.view' },
  { path: '/bookings', permission: 'contracts.view' },
  { path: '/contracts', permission: 'contracts.view' },
  { path: '/calendar', permission: 'contracts.view' },
  { path: '/waitlist', permission: 'waitlist.view', feature: 'waitlist' },
  { path: '/showroom', permission: 'showroom.run', feature: 'showroom' },
  { path: '/maintenance', permission: 'maintenance.view' },
  { path: '/inventory', permission: 'inventory.view', feature: 'inventory' },
  { path: '/petty-cash', permission: 'petty_cash.view', feature: 'petty_cash' },
  { path: '/expenses', permission: ['expenses.view', 'hr.expenses'] },
  { path: '/owners', permission: 'owners.view' },
  { path: '/violations', permission: 'violations.view' },
  { path: '/customers', permission: 'customers.view' },
  { path: '/department-notice', permission: 'department_notice.print', feature: 'department_notice' },
  { path: '/accounts', permission: 'accounting.view' },
  { path: '/reports', permission: ['reports.view', 'accounting.view'] },
  { path: '/hr', permission: 'hr.view', feature: 'hr' },
  { path: '/chat', permission: 'chat.view', feature: 'chat' },
  { path: '/staff', permission: 'staff.view' },
  { path: '/audit-logs', permission: 'audit.view' },
  { path: '/notifications', permission: 'notifications.view' },
  { path: '/settings', permission: 'settings.view' },
  { path: '/integrator', integratorOnly: true },
]

export type AccessHelpers = {
  can: (permission: PermissionRef) => boolean
  featureEnabled: (flag: string) => boolean
  isIntegrator: boolean
}

type AccessUser = {
  permissions?: string[] | null
  is_integrator?: boolean
  role_name?: string | null
  feature_flags?: Record<string, boolean> | null
}

/** Build access helpers from a user payload (e.g. right after login). */
export function accessHelpersFromUser(user: AccessUser | null | undefined): AccessHelpers {
  const isIntegrator = Boolean(user?.is_integrator || user?.role_name === 'super_admin')
  const perms = user?.permissions || []
  const featureFlags = user?.feature_flags || {}
  return {
    isIntegrator,
    can(permission) {
      if (!user) return false
      if (isIntegrator) return true
      return normalizePermissions(permission).some((key) => perms.includes(key))
    },
    featureEnabled(flag) {
      if (!(flag in featureFlags)) return true
      return Boolean(featureFlags[flag])
    },
  }
}

export function normalizePermissions(permission: PermissionRef): string[] {
  if (typeof permission === 'string') return [permission]
  return [...permission]
}

export function matchRouteAccess(pathname: string): RouteAccess | null {
  const path = pathname.split('?')[0] || '/'
  if (path === '/') {
    return ROUTE_ACCESS.find((r) => r.path === '/' && r.exact) ?? null
  }

  let best: RouteAccess | null = null
  for (const rule of ROUTE_ACCESS) {
    if (rule.exact) continue
    if (path === rule.path || path.startsWith(`${rule.path}/`)) {
      if (!best || rule.path.length > best.path.length) best = rule
    }
  }
  return best
}

export function isRouteAllowed(pathname: string, helpers: AccessHelpers): boolean {
  const rule = matchRouteAccess(pathname)
  if (!rule) return true
  if (rule.integratorOnly) return helpers.isIntegrator
  if (rule.feature && !helpers.featureEnabled(rule.feature)) return false
  if (rule.permission && !helpers.can(rule.permission)) return false
  return true
}

/** First navigable home for the current user (never returns a forbidden path). */
export function firstAccessiblePath(helpers: AccessHelpers): string {
  for (const path of HOME_CANDIDATES) {
    if (isRouteAllowed(path, helpers)) return path
  }
  for (const rule of ROUTE_ACCESS) {
    if (rule.integratorOnly) continue
    if (isRouteAllowed(rule.path, helpers)) return rule.path
  }
  return '/login'
}

/** Nav/command-palette filter for a declared destination. */
export function canSeeNavTo(to: string, helpers: AccessHelpers): boolean {
  return isRouteAllowed(to, helpers)
}
