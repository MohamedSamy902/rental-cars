import { NavLink, Outlet } from 'react-router-dom'
import './AppShell.css'

type NavItem = {
  to: string
  label: string
  icon?: string
  end?: boolean
}

const primaryNav: NavItem[] = [
  { to: '/', label: 'اليوم', icon: '◉', end: true },
  { to: '/vehicles', label: 'السيارات', icon: '▣' },
  { to: '/bookings', label: 'الحجوزات', icon: '▦' },
  { to: '/calendar', label: 'التقويم', icon: '▤' },
  { to: '/maintenance', label: 'الصيانة', icon: '⚙' },
]

const sideOnly: NavItem[] = [
  { to: '/customers', label: 'العملاء' },
  { to: '/accounts', label: 'الحسابات' },
  { to: '/settings', label: 'الإعدادات' },
]

export function AppShell() {
  return (
    <div className="rpm-shell">
      <aside className="rpm-shell__side" aria-label="التنقّل الرئيسي">
        <div className="rpm-shell__brand">
          <span className="rpm-shell__logo">RPM</span>
          <span className="rpm-shell__tag">إدارة التأجير</span>
        </div>
        <nav className="rpm-shell__nav">
          {[...primaryNav, ...sideOnly].map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => `rpm-shell__link ${isActive ? 'is-active' : ''}`}
            >
              {item.icon ? <span aria-hidden>{item.icon}</span> : null}
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="rpm-shell__main">
        <header className="rpm-shell__top">
          <div className="rpm-shell__top-brand">
            <strong>RPM</strong>
            <span>مركز التأجير</span>
          </div>
          <div className="rpm-shell__top-actions">
            <button type="button" className="rpm-shell__icon-btn" aria-label="إشعارات">
              جرس
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
        {primaryNav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) => `rpm-shell__tab ${isActive ? 'is-active' : ''}`}
          >
            <span aria-hidden>{item.icon}</span>
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
