import { useEffect, useMemo, useState } from 'react'
import { api } from '@/shared/api/client'
import { resolveAttentionTone, type AttentionTone } from '@/shared/ui/AttentionDot'

export const ATTENTION_INVALIDATE_EVENT = 'rpm:attention-invalidate'

/** Call after local mutations so sidebar/tab dots refresh immediately. */
export function invalidateAttentionSignals(): void {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent(ATTENTION_INVALIDATE_EVENT))
}

export type AttentionCounts = {
  overdue_returns: number
  pending_approvals: number
  returning_today: number
  pickups_today: number
  blocking_work_orders: number
  open_work_orders: number
  open_violations: number
  low_stock: number
  unread_notifications: number
  pending_hr_acks: number
  draft_payroll_runs: number
  blacklisted_customers: number
}

const EMPTY: AttentionCounts = {
  overdue_returns: 0,
  pending_approvals: 0,
  returning_today: 0,
  pickups_today: 0,
  blocking_work_orders: 0,
  open_work_orders: 0,
  open_violations: 0,
  low_stock: 0,
  unread_notifications: 0,
  pending_hr_acks: 0,
  draft_payroll_runs: 0,
  blacklisted_customers: 0,
}

function n(value: unknown): number {
  const v = Number(value)
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 0
}

function normalizeCounts(raw: Partial<AttentionCounts> | null | undefined): AttentionCounts {
  return {
    overdue_returns: n(raw?.overdue_returns),
    pending_approvals: n(raw?.pending_approvals),
    returning_today: n(raw?.returning_today),
    pickups_today: n(raw?.pickups_today),
    blocking_work_orders: n(raw?.blocking_work_orders),
    open_work_orders: n(raw?.open_work_orders),
    open_violations: n(raw?.open_violations),
    low_stock: n(raw?.low_stock),
    unread_notifications: n(raw?.unread_notifications),
    pending_hr_acks: n(raw?.pending_hr_acks),
    draft_payroll_runs: n(raw?.draft_payroll_runs),
    blacklisted_customers: n(raw?.blacklisted_customers),
  }
}

export type NavAttention = {
  tone: AttentionTone
  ping?: boolean
  label: string
}

/**
 * Sidebar dots ONLY for sections with a direct unhandled count.
 * Never decorate dashboard / fleet / calendar with aggregate noise.
 */
export function attentionForPath(path: string, c: AttentionCounts): NavAttention | null {
  const map: Record<string, { critical: number; warning: number; label: string; ping?: boolean }> = {
    '/bookings': {
      critical: c.overdue_returns + c.pending_approvals,
      warning: 0,
      label: c.overdue_returns
        ? `${c.overdue_returns} عقد متأخر عن الاستلام`
        : c.pending_approvals
          ? `${c.pending_approvals} عقد بانتظار الاعتماد`
          : 'عقود تحتاج انتباهاً',
      ping: true,
    },
    '/maintenance': {
      critical: c.open_work_orders,
      warning: 0,
      label: c.open_work_orders ? `${c.open_work_orders} أمر شغل مفتوح` : 'أوامر شغل مفتوحة',
      ping: true,
    },
    '/inventory': {
      critical: 0,
      warning: c.low_stock,
      label: `${c.low_stock} صنف تحت حد إعادة الطلب`,
    },
    '/violations': {
      critical: 0,
      warning: c.open_violations,
      label: `${c.open_violations} مخالفة مفتوحة`,
    },
    '/notifications': {
      critical: 0,
      warning: c.unread_notifications,
      label: `${c.unread_notifications} إشعار غير مقروء`,
    },
    '/hr': {
      critical: c.pending_hr_acks,
      warning: c.draft_payroll_runs,
      label: c.pending_hr_acks
        ? `${c.pending_hr_acks} إسناد بانتظار تأكيد الاستلام`
        : `${c.draft_payroll_runs} مسير بانتظار الاعتماد`,
      ping: true,
    },
    '/customers': {
      critical: 0,
      warning: c.blacklisted_customers,
      label: `${c.blacklisted_customers} عميل في القائمة السوداء`,
    },
  }

  const hit = map[path]
  if (!hit) return null
  if (hit.critical < 1 && hit.warning < 1) return null
  const tone = resolveAttentionTone({ critical: hit.critical, warning: hit.warning })
  if (!tone) return null
  return { tone, ping: tone === 'critical' ? Boolean(hit.ping) : false, label: hit.label }
}

const NAV_PATHS = [
  '/bookings',
  '/maintenance',
  '/inventory',
  '/violations',
  '/notifications',
  '/hr',
  '/customers',
] as const

export function useAttentionSignals(enabled: boolean, refreshKey?: string) {
  const [counts, setCounts] = useState<AttentionCounts>(EMPTY)

  useEffect(() => {
    if (!enabled) {
      setCounts(EMPTY)
      return
    }
    let alive = true
    let debounceTimer: number | null = null
    const pull = () => {
      api<{ data: AttentionCounts }>('/attention')
        .then((res) => {
          if (alive) setCounts(normalizeCounts(res.data))
        })
        .catch(() => {
          /* ignore — badge is non-blocking */
        })
    }
    const pullSoon = () => {
      if (debounceTimer != null) window.clearTimeout(debounceTimer)
      debounceTimer = window.setTimeout(() => {
        debounceTimer = null
        pull()
      }, 120)
    }
    pull()
    const interval = window.setInterval(pull, 30000)
    const onFocus = () => pull()
    const onVisible = () => {
      if (document.visibilityState === 'visible') pull()
    }
    const onInvalidate = () => pullSoon()
    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener(ATTENTION_INVALIDATE_EVENT, onInvalidate)
    return () => {
      alive = false
      if (debounceTimer != null) window.clearTimeout(debounceTimer)
      window.clearInterval(interval)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener(ATTENTION_INVALIDATE_EVENT, onInvalidate)
    }
  }, [enabled, refreshKey])

  const byPath = useMemo(() => {
    const out: Record<string, NavAttention> = {}
    for (const path of NAV_PATHS) {
      const signal = attentionForPath(path, counts)
      if (signal) out[path] = signal
    }
    return out
  }, [counts])

  return { counts, byPath }
}
