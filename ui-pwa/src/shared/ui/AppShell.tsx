import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '@/shared/auth/AuthContext'
import {
  IconHome, IconCar, IconClipboard, IconCalendar, IconUsers, IconWrench,
  IconBox, IconWallet, IconBell, IconSettings, IconAlert, IconChart,
  IconSearch, IconLogout, IconQueue,
} from './Icons'
import './AppShell.css'

type NavItem = { to: string; label: string; icon: React.ReactNode; end?: boolean }
type NavGroup = { title: string; items: NavItem[] }

const groups: NavGroup[] = [
  {
    title: 'التشغيل',
    items: [
      { to: '/', label: 'لوحة اليوم', icon: <IconHome />, end: true },
      { to: '/vehicles', label: 'المركبات', icon: <IconCar /> },
      { to: '/bookings', label: 'العقود والحجوزات', icon: <IconClipboard /> },
      { to: '/calendar', label: 'التقويم', icon: <IconCalendar /> },
      { to: '/waitlist', label: 'قائمة الانتظار', icon: <IconQueue /> },
    ],
  },
  {
    title: 'الأسطول والصيانة',
    items: [
      { to: '/maintenance', label: 'أوامر الشغل', icon: <IconWrench /> },
      { to: '/inventory', label: 'المخزون', icon: <IconBox /> },
      { to: '/violations', label: 'المخالفات', icon: <IconAlert /> },
    ],
  },
  {
    title: 'العملاء والمالية',
    items: [
      { to: '/customers', label: 'العملاء', icon: <IconUsers /> },
      { to: '/accounts', label: 'الحسابات', icon: <IconWallet /> },
      { to: '/reports', label: 'التقارير', icon: <IconChart /> },
    ],
  },
  {
    title: 'النظام',
    items: [
      { to: '/notifications', label: 'الإشعارات', icon: <IconBell /> },
      { to: '/settings', label: 'الإعدادات', icon: <IconSettings /> },
    ],
  },
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

  return (
    <div className="rpm-shell">
      <aside className="rpm-shell__side" aria-label="التنقّل الرئيسي">
        <div className="rpm-shell__brand">
          <div className="rpm-shell__logo-mark" aria-hidden>
            <IconCar size={20} />
          </div>
          <div>
            <span className="rpm-shell__logo">RPM</span>
            <span className="rpm-shell__tag">إدارة التأجير</span>
          </div>
        </div>

        <nav className="rpm-shell__nav">
          {groups.map((group) => (
            <div key={group.title} className="rpm-shell__group">
              <div className="rpm-shell__group-title">{group.title}</div>
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  className={({ isActive }) => `rpm-shell__link ${isActive ? 'is-active' : ''}`}
                >
                  <span className="rpm-shell__link-icon">{item.icon}</span>
                  {item.label}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="rpm-shell__user">
          <div>
            <strong>{user?.name}</strong>
            <span>{user?.role || 'مستخدم'}</span>
          </div>
          <button
            type="button"
            className="rpm-shell__icon-btn"
            aria-label="تسجيل الخروج"
            onClick={async () => {
              await logout()
              navigate('/login')
            }}
          >
            <IconLogout size={16} />
          </button>
        </div>
      </aside>

      <div className="rpm-shell__main">
        <header className="rpm-shell__top">
          <div className="rpm-shell__top-brand">
            <strong>RPM</strong>
            <span>مركز التأجير</span>
          </div>
          <label className="rpm-shell__search">
            <IconSearch size={16} />
            <input type="search" placeholder="بحث سريع في العقود والسيارات والعملاء…" />
          </label>
          <div className="rpm-shell__top-actions">
            <button type="button" className="rpm-shell__icon-btn" aria-label="إشعارات" onClick={() => navigate('/notifications')}>
              <IconBell size={18} />
            </button>
          </div>
        </header>

        <main className="rpm-shell__content">
          <div className="rpm-page-enter">
            <Outlet />
          </div>
        </main>
      </div>

      <nav className="rpm-shell__bottom" aria-label="تنقّل الموبايل">
        {mobileNav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) => `rpm-shell__tab ${isActive ? 'is-active' : ''}`}
          >
            {item.icon}
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
