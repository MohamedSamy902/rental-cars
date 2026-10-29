import { useEffect, useMemo, useState } from 'react'
import { api } from '@/shared/api/client'
import { resolveAttentionTone, type AttentionTone } from '@/shared/ui/AttentionDot'

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
}

export type NavAttention = {
  tone: AttentionTone
  ping?: boolean
  label: string
}

/**
 * Map route → attention signal for rail / dock / drawer.
 */
export function attentionForPath(path: string, c: AttentionCounts): NavAttention | null {
  const map: Record<string, { critical: number; warning: number; label: string; ping?: boolean }> = {
    '/bookings': {
      critical: c.overdue_returns,
      warning: c.pending_approvals + c.returning_today + c.pickups_today,
      label: c.overdue_returns
        ? `${c.overdue_returns} عقد متأخر عن الاستلام`
        : 'عقود تحتاج انتباهاً',
      ping: true,
    },
    '/calendar': {
      critical: c.overdue_returns,
      warning: c.returning_today + c.pickups_today,
      label: 'مواعيد اليوم تحتاج متابعة',
    },
    '/maintenance': {
      critical: c.blocking_work_orders,
      warning: Math.max(0, c.open_work_orders - c.blocking_work_orders),
      label: c.blocking_work_orders
        ? `${c.blocking_work_orders} أمر صيانة يوقف الأسطول`
        : 'أوامر شغل مفتوحة',
      ping: true,
    },
    '/vehicles': {
      critical: c.blocking_work_orders,
      warning: 0,
      label: 'مركبات في صيانة مانعة',
    },
    '/violations': {
      critical: 0,
      warning: c.open_violations,
      label: `${c.open_violations} مخالفة مفتوحة`,
    },
    '/inventory': {
      critical: 0,
      warning: c.low_stock,
      label: `${c.low_stock} صنف تحت حد إعادة الطلب`,
    },
    '/notifications': {
      critical: 0,
      warning: c.unread_notifications,
      label: `${c.unread_notifications} إشعار غير مقروء`,
    },
    '/': {
      critical: c.overdue_returns + c.blocking_work_orders,
      warning: c.pending_approvals + c.returning_today + c.pickups_today + c.open_violations,
      label: 'لوحة اليوم تحتاج متابعة',
      ping: true,
    },
  }

  const hit = map[path]
  if (!hit) return null
  const tone = resolveAttentionTone({ critical: hit.critical, warning: hit.warning })
  if (!tone) return null
  return { tone, ping: tone === 'critical' ? hit.ping : false, label: hit.label }
}

export function useAttentionSignals(enabled: boolean) {
  const [counts, setCounts] = useState<AttentionCounts>(EMPTY)

  useEffect(() => {
    if (!enabled) {
      setCounts(EMPTY)
      return
    }
    let alive = true
    const pull = () => {
      api<{ data: AttentionCounts }>('/attention')
        .then((res) => {
          if (alive && res.data) setCounts({ ...EMPTY, ...res.data })
        })
        .catch(() => {
          /* ignore — badge is non-blocking */
        })
    }
    pull()
    const t = window.setInterval(pull, 60000)
    const onFocus = () => pull()
    window.addEventListener('focus', onFocus)
    return () => {
      alive = false
      window.clearInterval(t)
      window.removeEventListener('focus', onFocus)
    }
  }, [enabled])

  const byPath = useMemo(() => {
    const out: Record<string, NavAttention> = {}
    for (const path of [
      '/',
      '/bookings',
      '/calendar',
      '/maintenance',
      '/vehicles',
      '/violations',
      '/inventory',
      '/notifications',
    ]) {
      const signal = attentionForPath(path, counts)
      if (signal) out[path] = signal
    }
    return out
  }, [counts])

  return { counts, byPath }
}
