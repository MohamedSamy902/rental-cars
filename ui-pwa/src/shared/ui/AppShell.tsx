import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { useAuth } from '@/shared/auth/AuthContext'
import { useTheme } from '@/shared/theme/ThemeContext'
import { CommandPalette } from './CommandPalette'
import {
  IconAlert, IconBell, IconBox, IconCalendar, IconCar, IconChart, IconChevron,
  IconClipboard, IconHome, IconLogout, IconMenu, IconMoon, IconPlus, IconQueue,
  IconSearch, IconSettings, IconShield, IconSun, IconUsers, IconWallet, IconWrench,
} from './Icons'
import './AppShell.css'

type NavItem = { to: string; label: string; icon: React.ReactNode; end?: boolean }

const railNav: NavItem[] = [
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
  { to: '/staff', label: 'الموظفون', icon: <IconShield /> },
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

const RAIL_KEY = 'rpm.shell.expanded'
const WA_URL = 'https://wa.me/201150100104'

export function AppShell() {
  const { user, logout } = useAuth()
  const { theme, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const [cmdOpen, setCmdOpen] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [expanded, setExpanded] = useState(() => {
    try {
      return localStorage.getItem(RAIL_KEY) === '1'
    } catch {
      return false
    }
  })

  useEffect(() => {
    try {
      localStorage.setItem(RAIL_KEY, expanded ? '1' : '0')
    } catch {
      /* ignore */
    }
  }, [expanded])

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
    <div className={`shell ${expanded ? 'is-expanded' : 'is-collapsed'}`}>
      <aside className="shell-rail" aria-label="التنقّل الرئيسي">
        <div className="shell-rail__inner">
          <div className="shell-rail__brand-row">
            <div className="shell-rail__brand" title="RPM">
              <IconCar size={18} />
            </div>
            <div className="shell-rail__brand-copy">
              <strong>RPM</strong>
              <span>إدارة التأجير</span>
            </div>
            <button
              type="button"
              className="shell-rail__toggle"
              aria-label={expanded ? 'طي القائمة' : 'فتح القائمة'}
              aria-expanded={expanded}
              onClick={() => setExpanded((v) => !v)}
            >
              <IconChevron size={16} />
            </button>
          </div>

          <nav className="shell-rail__nav">
            {railNav.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                title={item.label}
                className={({ isActive }) => `shell-rail__link ${isActive ? 'is-active' : ''}`}
              >
                <span className="shell-rail__ico">{item.icon}</span>
                <span className="shell-rail__label">{item.label}</span>
              </NavLink>
            ))}
          </nav>

          <div className="shell-rail__foot">
            <button
              type="button"
              className="shell-rail__theme"
              aria-label={theme === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
              onClick={toggleTheme}
            >
              {theme === 'dark' ? <IconSun size={16} /> : <IconMoon size={16} />}
              <span>المظهر</span>
            </button>
            <button
              type="button"
              className="shell-rail__quit"
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
        </div>
      </aside>

      <div className="shell-stage">
        <header className="shell-top">
          <div className="shell-top__bar">
            <button
              type="button"
              className="shell-icon shell-icon--mobile"
              aria-label="القائمة"
              onClick={() => setDrawer(true)}
            >
              <IconMenu size={18} />
            </button>

            <div className="shell-top__brand-mobile">
              <strong>RPM</strong>
            </div>

            <button type="button" className="shell-search" onClick={() => setCmdOpen(true)}>
              <IconSearch size={16} />
              <span>بحث أو انتقال سريع…</span>
              <kbd>⌘K</kbd>
            </button>

            <div className="shell-top__end">
              <button
                type="button"
                className="shell-icon shell-icon--mobile"
                aria-label="بحث"
                onClick={() => setCmdOpen(true)}
              >
                <IconSearch size={18} />
              </button>
              <button
                type="button"
                className="shell-icon shell-icon--desk"
                aria-label={theme === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
                onClick={toggleTheme}
              >
                {theme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
              </button>
              <button
                type="button"
                className="shell-icon"
                aria-label="إشعارات"
                onClick={() => navigate('/notifications')}
              >
                <IconBell size={18} />
              </button>
              <button type="button" className="shell-cta shell-cta--desk" onClick={() => navigate('/bookings?new=1')}>
                <IconPlus size={16} />
                <span>عقد جديد</span>
              </button>
              <div className="shell-user shell-user--desk">
                <span className="shell-user__avatar" aria-hidden>
                  {(user?.name || 'م').slice(0, 1)}
                </span>
                <span className="shell-user__meta">
                  <strong>{user?.name}</strong>
                  <em>{user?.role || 'مستخدم'}</em>
                </span>
              </div>
            </div>
          </div>
        </header>

        <main className="shell-main">
          <AnimatePresence mode="wait">
            <motion.div
              key={location.pathname}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -4 }}
              transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
            >
              <Outlet />
            </motion.div>
          </AnimatePresence>
        </main>

        <footer className="shell-credit">
          تم الإنشاء بواسطة{' '}
          <a href={WA_URL} target="_blank" rel="noreferrer noopener">
            Mohamed Samy
          </a>
        </footer>
      </div>

      <nav className="shell-dock" aria-label="تنقّل الموبايل">
        {mobileNav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) => `shell-dock__item ${isActive ? 'is-active' : ''}`}
          >
            {item.icon}
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <AnimatePresence>
        {drawer ? (
          <motion.div className="shell-drawer" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
            <button type="button" className="shell-drawer__scrim" aria-label="إغلاق" onClick={() => setDrawer(false)} />
            <motion.div
              className="shell-drawer__sheet"
              initial={{ y: '110%' }}
              animate={{ y: 0 }}
              exit={{ y: '110%' }}
              transition={{ type: 'spring', stiffness: 420, damping: 36 }}
              role="dialog"
              aria-label="كل الأقسام"
            >
              <div className="shell-drawer__handle" aria-hidden />
              <div className="shell-drawer__head">
                <strong>RPM</strong>
                <button type="button" className="shell-icon" onClick={toggleTheme} aria-label="تبديل المظهر">
                  {theme === 'dark' ? <IconSun size={16} /> : <IconMoon size={16} />}
                </button>
              </div>
              <div className="shell-drawer__grid">
                {railNav.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className="shell-drawer__link"
                    onClick={() => setDrawer(false)}
                  >
                    {item.icon}
                    {item.label}
                  </NavLink>
                ))}
              </div>
              <p className="shell-drawer__credit">
                تم الإنشاء بواسطة{' '}
                <a href={WA_URL} target="_blank" rel="noreferrer noopener">
                  Mohamed Samy
                </a>
              </p>
            </motion.div>
          </motion.div>
        ) : null}
      </AnimatePresence>

      <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} />
    </div>
  )
}
