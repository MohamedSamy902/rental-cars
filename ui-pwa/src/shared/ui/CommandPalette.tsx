import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { useNavigate } from 'react-router-dom'
import {
  IconAlert, IconBell, IconBox, IconBriefcase, IconCalendar, IconCar, IconChart, IconChat,
  IconClipboard, IconClose, IconFileText, IconHistory, IconHome, IconKey, IconLandmark,
  IconQueue, IconReceipt, IconSearch, IconSettings, IconStore, IconUserCog, IconUsers,
  IconWallet, IconWrench,
} from './Icons'
import { useIsMobile, useSheetDrag } from './useSheetDrag'
import './CommandPalette.css'

type Item = { to: string; label: string; group: string; icon: React.ReactNode }

const ITEMS: Item[] = [
  { to: '/', label: 'لوحة اليوم', group: 'التشغيل', icon: <IconHome /> },
  { to: '/vehicles', label: 'المركبات', group: 'التشغيل', icon: <IconCar /> },
  { to: '/bookings', label: 'العقود والحجوزات', group: 'التشغيل', icon: <IconClipboard /> },
  { to: '/calendar', label: 'التقويم', group: 'التشغيل', icon: <IconCalendar /> },
  { to: '/waitlist', label: 'قائمة الانتظار', group: 'التشغيل', icon: <IconQueue /> },
  { to: '/showroom', label: 'وضع عرض المعرض', group: 'التشغيل', icon: <IconStore /> },
  { to: '/department-notice', label: 'إخطار القسم', group: 'التشغيل', icon: <IconFileText /> },
  { to: '/maintenance', label: 'أوامر الشغل', group: 'الأسطول', icon: <IconWrench /> },
  { to: '/inventory', label: 'المخزون', group: 'الأسطول', icon: <IconBox /> },
  { to: '/petty-cash', label: 'العهدة النقدية', group: 'الأسطول', icon: <IconWallet /> },
  { to: '/violations', label: 'المخالفات', group: 'الأسطول', icon: <IconAlert /> },
  { to: '/customers', label: 'العملاء', group: 'المالية', icon: <IconUsers /> },
  { to: '/accounts', label: 'الحسابات', group: 'المالية', icon: <IconLandmark /> },
  { to: '/reports', label: 'التقارير', group: 'المالية', icon: <IconChart /> },
  { to: '/expenses', label: 'المصروفات', group: 'المالية', icon: <IconReceipt /> },
  { to: '/owners', label: 'ملاك العربيات', group: 'المالية', icon: <IconKey /> },
  { to: '/staff', label: 'الموظفون والصلاحيات', group: 'النظام', icon: <IconUserCog /> },
  { to: '/audit-logs', label: 'سجل التعديلات', group: 'النظام', icon: <IconHistory /> },
  { to: '/notifications', label: 'الإشعارات', group: 'النظام', icon: <IconBell /> },
  { to: '/hr', label: 'الموارد البشرية', group: 'النظام', icon: <IconBriefcase /> },
  { to: '/chat', label: 'محادثة التشغيل', group: 'النظام', icon: <IconChat /> },
  { to: '/settings', label: 'الإعدادات', group: 'النظام', icon: <IconSettings /> },
]

type Props = { open: boolean; onClose: () => void }

export function CommandPalette({ open, onClose }: Props) {
  return createPortal(
    <AnimatePresence>{open ? <PaletteLayer key="rpm-cmd" onClose={onClose} /> : null}</AnimatePresence>,
    document.body,
  )
}

function PaletteLayer({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const mobile = useIsMobile()
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLUListElement>(null)
  const { startDrag, dragProps } = useSheetDrag(onClose, mobile)

  const filtered = useMemo(() => {
    const needle = q.trim()
    if (!needle) return ITEMS
    return ITEMS.filter((i) => i.label.includes(needle) || i.group.includes(needle))
  }, [q])

  useEffect(() => {
    // On touch, focusing immediately pops the keyboard over a sheet that is still animating in
    const t = window.setTimeout(() => inputRef.current?.focus({ preventScroll: true }), mobile ? 260 : 30)
    return () => window.clearTimeout(t)
  }, [mobile])

  useEffect(() => {
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = prev
    }
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      else if (e.key === 'ArrowDown') {
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
  }, [filtered, active, navigate, onClose])

  useEffect(() => {
    const el = listRef.current?.querySelector<HTMLElement>(`[data-idx="${active}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  }, [active])

  const panelMotion = mobile
    ? { initial: { y: '100%' }, animate: { y: 0 }, exit: { y: '100%' } }
    : { initial: { opacity: 0, y: 12, scale: 0.98 }, animate: { opacity: 1, y: 0, scale: 1 }, exit: { opacity: 0, y: 8, scale: 0.98 } }

  return (
    <div className={`rpm-cmd ${mobile ? 'is-mobile' : ''}`} role="presentation">
      <motion.button
        type="button"
        className="rpm-cmd__backdrop"
        aria-label="إغلاق البحث"
        onClick={onClose}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.18 }}
      />
      <motion.div
        className="rpm-cmd__panel"
        role="dialog"
        aria-modal="true"
        aria-label="بحث وانتقال سريع"
        {...panelMotion}
        transition={{ type: 'spring', stiffness: 420, damping: 38 }}
        {...dragProps}
      >
        {mobile ? (
          <div className="rpm-cmd__grab" onPointerDown={startDrag}>
            <span className="rpm-cmd__handle" aria-hidden />
          </div>
        ) : null}
        <div className="rpm-cmd__bar" onPointerDown={mobile ? startDrag : undefined}>
          <label className="rpm-cmd__search">
            <IconSearch size={18} />
            <input
              ref={inputRef}
              type="search"
              enterKeyHint="go"
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setActive(0)
              }}
              placeholder="ابحث عن شاشة أو أمر…"
              aria-controls="rpm-cmd-list"
              aria-activedescendant={filtered[active] ? `rpm-cmd-${active}` : undefined}
            />
            {q ? (
              <button type="button" className="rpm-cmd__clear" aria-label="مسح البحث" onClick={() => { setQ(''); inputRef.current?.focus() }}>
                <IconClose size={14} />
              </button>
            ) : null}
          </label>
          {mobile ? (
            <button type="button" className="rpm-cmd__cancel" onClick={onClose}>
              إلغاء
            </button>
          ) : (
            <kbd className="rpm-cmd__kbd">Esc</kbd>
          )}
        </div>
        <ul className="rpm-cmd__list" id="rpm-cmd-list" role="listbox" ref={listRef}>
          {filtered.length === 0 ? (
            <li className="rpm-cmd__empty">لا توجد نتائج لـ «{q}»</li>
          ) : (
            filtered.map((item, idx) => (
              <li key={item.to} role="presentation">
                <button
                  type="button"
                  id={`rpm-cmd-${idx}`}
                  data-idx={idx}
                  role="option"
                  aria-selected={idx === active}
                  className={`rpm-cmd__item ${idx === active ? 'is-active' : ''}`}
                  onMouseEnter={() => setActive(idx)}
                  onClick={() => {
                    navigate(item.to)
                    onClose()
                  }}
                >
                  <span className="rpm-cmd__icon" aria-hidden>{item.icon}</span>
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
    </div>
  )
}
