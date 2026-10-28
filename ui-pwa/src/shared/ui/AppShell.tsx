import { useCallback, useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { api } from '@/shared/api/client'
import { useAuth } from '@/shared/auth/AuthContext'
import { useTheme } from '@/shared/theme/ThemeContext'
import { BrandLogo } from './BrandLogo'
import { CommandPalette } from './CommandPalette'
import { useSheetDrag } from './useSheetDrag'
import {
  IconAlert, IconBell, IconBox, IconCalendar, IconCar, IconChart, IconChevron,
  IconClipboard, IconHome, IconLogout, IconMenu, IconMoon, IconPlus, IconQueue,
  IconSearch, IconSettings, IconShield, IconSun, IconUsers, IconWallet, IconWrench,
} from './Icons'
import './Button.css'
import './AppShell.css'

type NavItem = {
  to: string
  label: string
  icon: React.ReactNode
  end?: boolean
  feature?: string
  permission?: string
}

const railNav: NavItem[] = [
  { to: '/', label: 'لوحة اليوم', icon: <IconHome />, end: true },
  { to: '/vehicles', label: 'المركبات', icon: <IconCar />, permission: 'vehicles.view' },
  { to: '/bookings', label: 'العقود', icon: <IconClipboard />, permission: 'contracts.view' },
  { to: '/calendar', label: 'التقويم', icon: <IconCalendar />, permission: 'contracts.view' },
  { to: '/waitlist', label: 'الانتظار', icon: <IconQueue />, feature: 'waitlist', permission: 'waitlist.view' },
  { to: '/showroom', label: 'المعرض', icon: <IconCar />, feature: 'showroom', permission: 'showroom.run' },
  { to: '/maintenance', label: 'الصيانة', icon: <IconWrench />, permission: 'maintenance.view' },
  { to: '/inventory', label: 'المخزون', icon: <IconBox />, feature: 'inventory', permission: 'inventory.view' },
  { to: '/petty-cash', label: 'العهدة', icon: <IconWallet />, feature: 'petty_cash', permission: 'petty_cash.view' },
  { to: '/expenses', label: 'المصروفات', icon: <IconWallet />, permission: 'expenses.view' },
  { to: '/owners', label: 'ملاك العربيات', icon: <IconUsers />, permission: 'owners.view' },
  { to: '/violations', label: 'المخالفات', icon: <IconAlert />, permission: 'violations.view' },
  { to: '/customers', label: 'العملاء', icon: <IconUsers />, permission: 'customers.view' },
  { to: '/department-notice', label: 'إخطار القسم', icon: <IconClipboard />, feature: 'department_notice', permission: 'department_notice.print' },
  { to: '/accounts', label: 'الحسابات', icon: <IconWallet />, permission: 'accounting.view' },
  { to: '/reports', label: 'التقارير', icon: <IconChart />, permission: 'reports.view' },
  { to: '/hr', label: 'الموارد البشرية', icon: <IconUsers />, feature: 'hr', permission: 'hr.view' },
  { to: '/chat', label: 'المحادثة', icon: <IconBell />, feature: 'chat' },
  { to: '/staff', label: 'الموظفون', icon: <IconShield />, permission: 'staff.view' },
  { to: '/audit-logs', label: 'سجل التعديلات', icon: <IconShield />, permission: 'audit.view' },
  { to: '/notifications', label: 'الإشعارات', icon: <IconBell />, permission: 'notifications.view' },
  { to: '/settings', label: 'الإعدادات', icon: <IconSettings />, permission: 'settings.view' },
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
  const { user, logout, can, featureEnabled } = useAuth()
  const { theme, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  const [cmdOpen, setCmdOpen] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [unread, setUnread] = useState(0)

  const visibleRail = railNav.filter((item) => {
    if (item.feature && !featureEnabled(item.feature)) return false
    if (item.permission && !can(item.permission)) return false
    return true
  })
  const visibleMobile = mobileNav.filter((item) => {
    const full = railNav.find((r) => r.to === item.to)
    if (full?.feature && !featureEnabled(full.feature)) return false
    if (full?.permission && !can(full.permission)) return false
    return true
  })
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

  const closeDrawer = useCallback(() => setDrawer(false), [])
  const { startDrag: startDrawerDrag, dragProps: drawerDragProps } = useSheetDrag(closeDrawer, true)

  useEffect(() => {
    if (!drawer) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') closeDrawer()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [drawer, closeDrawer])

  useEffect(() => {
    if (!user) {
      setUnread(0)
      return
    }
    let alive = true
    const pull = () => {
      api<{ unread: number }>('/notifications/unread-count')
        .then((res) => {
          if (alive) setUnread(res.unread || 0)
        })
        .catch(() => {
          /* ignore badge errors */
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
  }, [user, location.pathname])

  return (
    <div className={`shell ${expanded ? 'is-expanded' : 'is-collapsed'}`}>
      <aside className="shell-rail" aria-label="التنقّل الرئيسي">
        <div className="shell-rail__inner">
          <div className="shell-rail__brand-row">
            <NavLink to="/" className="shell-rail__brand" title="RPM Rent A Car" aria-label="لوحة اليوم">
              <BrandLogo variant="mark" />
            </NavLink>
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
            {visibleRail.map((item) => (
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

            <NavLink to="/" className="shell-top__brand-mobile" aria-label="لوحة اليوم">
              <BrandLogo variant="mark" />
            </NavLink>

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
                className="shell-icon shell-icon--bell"
                aria-label={unread > 0 ? `إشعارات · ${unread} غير مقروء` : 'إشعارات'}
                onClick={() => navigate('/notifications')}
              >
                <IconBell size={18} />
                {unread > 0 ? (
                  <span className="shell-badge" aria-hidden>
                    {unread > 99 ? '99+' : unread}
                  </span>
                ) : null}
              </button>
              <button
                type="button"
                className="rpm-btn rpm-btn--primary rpm-btn--md shell-cta--desk"
                onClick={() => navigate('/bookings?new=1')}
              >
                <span className="rpm-btn__icon" aria-hidden>
                  <IconPlus size={16} />
                </span>
                <span className="rpm-btn__label">عقد جديد</span>
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
        {visibleMobile.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) => `shell-dock__item ${isActive ? 'is-active' : ''}`}
          >
            <span className="shell-dock__icon" aria-hidden="true">{item.icon}</span>
            <span className="shell-dock__label">{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <AnimatePresence>
        {drawer ? (
          <motion.div className="shell-drawer" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
            <button type="button" className="shell-drawer__scrim" aria-label="إغلاق" onClick={closeDrawer} />
            <motion.div
              className="shell-drawer__sheet"
              initial={{ y: '110%' }}
              animate={{ y: 0 }}
              exit={{ y: '110%' }}
              transition={{ type: 'spring', stiffness: 420, damping: 38 }}
              role="dialog"
              aria-modal="true"
              aria-label="كل الأقسام"
              {...drawerDragProps}
            >
              <div className="shell-drawer__grab" onPointerDown={startDrawerDrag}>
                <div className="shell-drawer__handle" aria-hidden />
                <div className="shell-drawer__head">
                  <BrandLogo variant="mark" />
                  <span className="shell-drawer__title">كل الأقسام</span>
                  <button type="button" className="shell-icon" onClick={toggleTheme} aria-label="تبديل المظهر">
                    {theme === 'dark' ? <IconSun size={16} /> : <IconMoon size={16} />}
                  </button>
                </div>
              </div>
              <div className="shell-drawer__grid">
                {visibleRail.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className={({ isActive }) => `shell-drawer__link ${isActive ? 'is-active' : ''}`}
                    onClick={closeDrawer}
                  >
                    <span className="shell-drawer__ico">{item.icon}</span>
                    <span>{item.label}</span>
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
