import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { useAuth } from '@/shared/auth/AuthContext'
import { CommandPalette } from './CommandPalette'
import {
  IconAlert,
  IconBell,
  IconBox,
  IconCalendar,
  IconCar,
  IconChart,
  IconClipboard,
  IconHome,
  IconLogout,
  IconMenu,
  IconPlus,
  IconQueue,
  IconSearch,
  IconSettings,
  IconUsers,
  IconWallet,
  IconWrench,
} from './Icons'
import './AppShell.css'

type NavItem = { to: string; label: string; icon: React.ReactNode; end?: boolean }

const primaryNav: NavItem[] = [
  { to: '/', label: 'لوحة اليوم', icon: <IconHome />, end: true },
  { to: '/vehicles', label: 'المركبات', icon: <IconCar /> },
  { to: '/bookings', label: 'العقود', icon: <IconClipboard /> },
  { to: '/calendar', label: 'التقويم', icon: <IconCalendar /> },
  { to: '/waitlist', label: 'الانتظار', icon: <IconQueue /> },
  { to: '/maintenance', label: 'الصيانة', icon: <IconWrench /> },
  { to: '/inventory', label: 'المخزون', icon: <IconBox /> },
  { to: '/violations', label: 'المخالفات', icon: <IconAlert /> },
  { to: '/customers', label: 'العملاء', icon: <IconUsers /> },
  { to: '/accounts', label: 'الحسابات', icon: <IconWallet /> },
  { to: '/reports', label: 'التقارير', icon: <IconChart /> },
  { to: '/notifications', label: 'الإشعارات', icon: <IconBell /> },
  { to: '/settings', label: 'الإعدادات', icon: <IconSettings /> },
]

const mobileNav: NavItem[] = [
  { to: '/', label: 'اليوم', icon: <IconHome />, end: true },
  { to: '/vehicles', label: 'أسطول', icon: <IconCar /> },
  { to: '/bookings', label: 'عقود', icon: <IconClipboard /> },
  { to: '/calendar', label: 'تقويم', icon: <IconCalendar /> },
  { to: '/maintenance', label: 'صيانة', icon: <IconWrench /> },
]

export function AppShell() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [expanded, setExpanded] = useState(false)
  const [cmdOpen, setCmdOpen] = useState(false)
  const [drawer, setDrawer] = useState(false)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setCmdOpen((v) => !v)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    setDrawer(false)
  }, [location.pathname])

  return (
    <div className={`rpm-app ${expanded ? 'is-expanded' : ''}`}>
      <aside
        className="rpm-rail"
        aria-label="التنقّل الرئيسي"
        onMouseEnter={() => setExpanded(true)}
        onMouseLeave={() => setExpanded(false)}
      >
        <div className="rpm-rail__brand">
          <span className="rpm-rail__mark" aria-hidden>
            <IconCar size={18} />
          </span>
          <span className="rpm-rail__name">RPM</span>
        </div>

        <nav className="rpm-rail__nav">
          {primaryNav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              title={item.label}
              className={({ isActive }) => `rpm-rail__link ${isActive ? 'is-active' : ''}`}
            >
              <span className="rpm-rail__ico">{item.icon}</span>
              <span className="rpm-rail__txt">{item.label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="rpm-rail__foot">
          <div className="rpm-rail__user" title={user?.name}>
            <span className="rpm-rail__avatar" aria-hidden>
              {(user?.name || 'م').slice(0, 1)}
            </span>
            <span className="rpm-rail__who">
              <strong>{user?.name}</strong>
              <em>{user?.role || 'مستخدم'}</em>
            </span>
          </div>
          <button
            type="button"
            className="rpm-rail__quit"
            aria-label="تسجيل الخروج"
            onClick={async () => {
              await logout()
              navigate('/login')
            }}
          >
            <IconLogout size={16} />
            <span>خروج</span>
          </button>
        </div>
      </aside>

      <div className="rpm-stage">
        <header className="rpm-top">
          <button
            type="button"
            className="rpm-top__burger"
            aria-label="القائمة"
            onClick={() => setDrawer(true)}
          >
            <IconMenu size={18} />
          </button>

          <button type="button" className="rpm-top__search" onClick={() => setCmdOpen(true)}>
            <IconSearch size={16} />
            <span>بحث أو انتقال سريع…</span>
            <kbd>⌘K</kbd>
          </button>

          <div className="rpm-top__end">
            <button type="button" className="rpm-top__ghost" onClick={() => setCmdOpen(true)} aria-label="بحث">
              <IconSearch size={18} />
            </button>
            <button type="button" className="rpm-top__ghost" aria-label="إشعارات" onClick={() => navigate('/notifications')}>
              <IconBell size={18} />
            </button>
            <button type="button" className="rpm-top__cta" onClick={() => navigate('/bookings')}>
              <IconPlus size={16} />
              <span>عقد جديد</span>
            </button>
          </div>
        </header>

        <main className="rpm-main">
          <AnimatePresence mode="wait">
            <motion.div
              key={location.pathname}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -4 }}
              transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
            >
              <Outlet />
            </motion.div>
          </AnimatePresence>
        </main>
      </div>

      <nav className="rpm-dock" aria-label="تنقّل الموبايل">
        {mobileNav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) => `rpm-dock__item ${isActive ? 'is-active' : ''}`}
          >
            {item.icon}
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <AnimatePresence>
        {drawer ? (
          <motion.div className="rpm-drawer" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
            <button type="button" className="rpm-drawer__scrim" aria-label="إغلاق" onClick={() => setDrawer(false)} />
            <motion.div
              className="rpm-drawer__sheet"
              role="dialog"
              aria-label="كل الأقسام"
              initial={{ y: '110%' }}
              animate={{ y: 0 }}
              exit={{ y: '110%' }}
              transition={{ type: 'spring', stiffness: 420, damping: 36 }}
            >
              <div className="rpm-drawer__handle" aria-hidden />
              <header className="rpm-drawer__head">
                <div>
                  <strong>RPM</strong>
                  <span>كل الأقسام</span>
                </div>
                <button type="button" onClick={() => setDrawer(false)}>
                  إغلاق
                </button>
              </header>
              <div className="rpm-drawer__grid">
                {primaryNav.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.end} className="rpm-drawer__link" onClick={() => setDrawer(false)}>
                    {item.icon}
                    {item.label}
                  </NavLink>
                ))}
              </div>
            </motion.div>
          </motion.div>
        ) : null}
      </AnimatePresence>

      <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} />
    </div>
  )
}
