import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@/shared/auth/AuthContext'
import { ThemeProvider } from '@/shared/theme/ThemeContext'
import { AppShell, ToastProvider, SkeletonBlock } from '@/shared/ui'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { VehiclesPage } from '@/pages/VehiclesPage'
import { BookingsPage } from '@/pages/BookingsPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { MaintenancePage } from '@/pages/MaintenancePage'
import { CustomersPage } from '@/pages/CustomersPage'
import { InventoryPage } from '@/pages/InventoryPage'
import { ViolationsPage } from '@/pages/ViolationsPage'
import { WaitlistPage } from '@/pages/WaitlistPage'
import { NotificationsPage } from '@/pages/NotificationsPage'
import { ReportsPage } from '@/pages/ReportsPage'
import { AccountsPage } from '@/pages/AccountsPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { StaffPage } from '@/pages/StaffPage'
import { ShowroomPage } from '@/pages/ShowroomPage'
import { DepartmentNoticePage } from '@/pages/DepartmentNoticePage'
import { PettyCashPage } from '@/pages/PettyCashPage'
import { IntegratorPage } from '@/pages/IntegratorPage'
import { AuditLogsPage } from '@/pages/AuditLogsPage'
import type { ReactNode } from 'react'

function Protected({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) {
    return (
      <div style={{ padding: '1.5rem', maxWidth: 720, marginInline: 'auto' }}>
        <SkeletonBlock rows={6} />
      </div>
    )
  }
  if (!user) return <Navigate to="/login" replace />
  return children
}

export default function App() {
  return (
    <ThemeProvider>
      <ToastProvider>
        <AuthProvider>
          <BrowserRouter>
            <Routes>
              <Route path="/login" element={<LoginPage />} />
              <Route
                element={
                  <Protected>
                    <AppShell />
                  </Protected>
                }
              >
                <Route index element={<DashboardPage />} />
                <Route path="vehicles" element={<VehiclesPage />} />
                <Route path="bookings" element={<BookingsPage />} />
                <Route path="calendar" element={<CalendarPage />} />
                <Route path="maintenance" element={<MaintenancePage />} />
                <Route path="inventory" element={<InventoryPage />} />
                <Route path="customers" element={<CustomersPage />} />
                <Route path="violations" element={<ViolationsPage />} />
                <Route path="waitlist" element={<WaitlistPage />} />
                <Route path="notifications" element={<NotificationsPage />} />
                <Route path="reports" element={<ReportsPage />} />
                <Route path="accounts" element={<AccountsPage />} />
                <Route path="petty-cash" element={<PettyCashPage />} />
                <Route path="showroom" element={<ShowroomPage />} />
                <Route path="department-notice" element={<DepartmentNoticePage />} />
                <Route path="audit-logs" element={<AuditLogsPage />} />
                <Route path="staff" element={<StaffPage />} />
                <Route path="settings" element={<SettingsPage />} />
                <Route path="integrator" element={<IntegratorPage />} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Route>
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
