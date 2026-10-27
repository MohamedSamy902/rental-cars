import { useEffect, useMemo, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { useNavigate } from 'react-router-dom'
import {
  IconAlert,
  IconBell,
  IconBox,
  IconCalendar,
  IconCar,
  IconChart,
  IconClipboard,
  IconHome,
  IconQueue,
  IconSearch,
  IconSettings,
  IconUsers,
  IconWallet,
  IconWrench,
} from './Icons'
import './CommandPalette.css'

type Item = { to: string; label: string; group: string; icon: React.ReactNode; keywords?: string }

const ITEMS: Item[] = [
  { to: '/', label: 'لوحة اليوم', group: 'التشغيل', icon: <IconHome />, keywords: 'dashboard home' },
  { to: '/vehicles', label: 'المركبات', group: 'التشغيل', icon: <IconCar />, keywords: 'fleet cars' },
  { to: '/bookings', label: 'العقود والحجوزات', group: 'التشغيل', icon: <IconClipboard />, keywords: 'contracts' },
  { to: '/calendar', label: 'التقويم', group: 'التشغيل', icon: <IconCalendar /> },
  { to: '/waitlist', label: 'قائمة الانتظار', group: 'التشغيل', icon: <IconQueue /> },
  { to: '/maintenance', label: 'أوامر الشغل', group: 'الأسطول', icon: <IconWrench /> },
  { to: '/inventory', label: 'المخزون', group: 'الأسطول', icon: <IconBox /> },
  { to: '/violations', label: 'المخالفات', group: 'الأسطول', icon: <IconAlert /> },
  { to: '/customers', label: 'العملاء', group: 'المالية', icon: <IconUsers /> },
  { to: '/accounts', label: 'الحسابات', group: 'المالية', icon: <IconWallet /> },
  { to: '/reports', label: 'التقارير', group: 'المالية', icon: <IconChart /> },
  { to: '/notifications', label: 'الإشعارات', group: 'النظام', icon: <IconBell /> },
  { to: '/settings', label: 'الإعدادات', group: 'النظام', icon: <IconSettings /> },
]

type Props = { open: boolean; onClose: () => void }

export function CommandPalette({ open, onClose }: Props) {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase()
    if (!needle) return ITEMS
    return ITEMS.filter(
      (i) =>
        i.label.includes(q.trim()) ||
        i.group.includes(q.trim()) ||
        (i.keywords || '').toLowerCase().includes(needle),
    )
  }, [q])

  useEffect(() => {
    if (!open) return
    setQ('')
    setActive(0)
    const t = window.setTimeout(() => inputRef.current?.focus(), 40)
    return () => window.clearTimeout(t)
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault()
        onClose()
      } else if (e.key === 'ArrowDown') {
        e.preventDefault()
        setActive((i) => Math.min(i + 1, Math.max(filtered.length - 1, 0)))
      } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        setActive((i) => Math.max(i - 1, 0))
      } else if (e.key === 'Enter' && filtered[active]) {
        e.preventDefault()
        navigate(filtered[active].to)
        onClose()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, filtered, active, navigate, onClose])

  return (
    <AnimatePresence>
      {open ? (
        <motion.div
          className="rpm-cmd"
          role="dialog"
          aria-modal="true"
          aria-label="لوحة الأوامر"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.18 }}
        >
          <button type="button" className="rpm-cmd__backdrop" aria-label="إغلاق" onClick={onClose} />
          <motion.div
            className="rpm-cmd__panel"
            initial={{ opacity: 0, y: 16, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 10, scale: 0.98 }}
            transition={{ type: 'spring', stiffness: 420, damping: 32 }}
          >
            <label className="rpm-cmd__search">
              <IconSearch size={18} />
              <input
                ref={inputRef}
                value={q}
                onChange={(e) => {
                  setQ(e.target.value)
                  setActive(0)
                }}
                placeholder="ابحث عن شاشة أو إجراء…"
                aria-label="بحث الأوامر"
              />
              <kbd>Esc</kbd>
            </label>
            <ul className="rpm-cmd__list" role="listbox">
              {filtered.length === 0 ? (
                <li className="rpm-cmd__empty">لا نتائج مطابقة</li>
              ) : (
                filtered.map((item, idx) => (
                  <li key={item.to}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={idx === active}
                      className={`rpm-cmd__item ${idx === active ? 'is-active' : ''}`}
                      onMouseEnter={() => setActive(idx)}
                      onClick={() => {
                        navigate(item.to)
                        onClose()
                      }}
                    >
                      <span className="rpm-cmd__icon">{item.icon}</span>
                      <span className="rpm-cmd__meta">
                        <strong>{item.label}</strong>
                        <span>{item.group}</span>
                      </span>
                    </button>
                  </li>
                ))
              )}
            </ul>
          </motion.div>
        </motion.div>
      ) : null}
    </AnimatePresence>
  )
}
