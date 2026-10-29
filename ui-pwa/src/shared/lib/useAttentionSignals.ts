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
  }
}

export type NavAttention = {
  tone: AttentionTone
  ping?: boolean
  label: string
}

/**
 * Nav dots ONLY for sections with a direct unhandled count.
 * Never decorate dashboard / fleet / calendar with aggregate noise.
 *
 * Tone rules (live counts):
 * - العقود: red when overdue OR pending > 0
 * - maintenance: red when open work orders > 0
 * - inventory: amber when low stock > 0
 * - violations / notifications: amber when open/unread > 0
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
      label: c.open_work_orders
        ? `${c.open_work_orders} أمر شغل مفتوح`
        : 'أوامر شغل مفتوحة',
      ping: true,
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
  }

  const hit = map[path]
  if (!hit) return null
  if (hit.critical < 1 && hit.warning < 1) return null
  const tone = resolveAttentionTone({ critical: hit.critical, warning: hit.warning })
  if (!tone) return null
  return { tone, ping: tone === 'critical' ? Boolean(hit.ping) : false, label: hit.label }
}

export function useAttentionSignals(enabled: boolean, refreshKey?: string) {
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
          if (alive) setCounts(normalizeCounts(res.data))
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
  }, [enabled, refreshKey])

  const byPath = useMemo(() => {
    const out: Record<string, NavAttention> = {}
    for (const path of ['/bookings', '/maintenance', '/violations', '/inventory', '/notifications']) {
      const signal = attentionForPath(path, counts)
      if (signal) out[path] = signal
    }
    return out
  }, [counts])

  return { counts, byPath }
}
