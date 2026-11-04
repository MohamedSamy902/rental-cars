import { lazy, Suspense, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@/shared/auth/AuthContext'
import { ThemeProvider } from '@/shared/theme/ThemeContext'
import { AppShell, ToastProvider, SkeletonBlock } from '@/shared/ui'
import { NewContractProvider } from '@/shared/contracts/NewContract'
import { LoginPage } from '@/pages/LoginPage'

const DashboardPage = lazy(() => import('@/pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const VehiclesPage = lazy(() => import('@/pages/VehiclesPage').then((m) => ({ default: m.VehiclesPage })))
const VehicleDetailPage = lazy(() => import('@/pages/VehicleDetailPage').then((m) => ({ default: m.VehicleDetailPage })))
const BookingsPage = lazy(() => import('@/pages/BookingsPage').then((m) => ({ default: m.BookingsPage })))
const ContractDetailPage = lazy(() => import('@/pages/ContractDetailPage').then((m) => ({ default: m.ContractDetailPage })))
const MonthLedgerPage = lazy(() => import('@/pages/MonthLedgerPage').then((m) => ({ default: m.MonthLedgerPage })))
const CalendarPage = lazy(() => import('@/pages/CalendarPage').then((m) => ({ default: m.CalendarPage })))
const MaintenancePage = lazy(() => import('@/pages/MaintenancePage').then((m) => ({ default: m.MaintenancePage })))
const CustomersPage = lazy(() => import('@/pages/CustomersPage').then((m) => ({ default: m.CustomersPage })))
const InventoryPage = lazy(() => import('@/pages/InventoryPage').then((m) => ({ default: m.InventoryPage })))
const ViolationsPage = lazy(() => import('@/pages/ViolationsPage').then((m) => ({ default: m.ViolationsPage })))
const ViolationInquiriesPage = lazy(() => import('@/pages/ViolationInquiriesPage').then((m) => ({ default: m.ViolationInquiriesPage })))
const NotificationsPage = lazy(() => import('@/pages/NotificationsPage').then((m) => ({ default: m.NotificationsPage })))
const ReportsPage = lazy(() => import('@/pages/ReportsPage').then((m) => ({ default: m.ReportsPage })))
const AccountsPage = lazy(() => import('@/pages/AccountsPage').then((m) => ({ default: m.AccountsPage })))
const SettingsPage = lazy(() => import('@/pages/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const StaffPage = lazy(() => import('@/pages/StaffPage').then((m) => ({ default: m.StaffPage })))
const ShowroomPage = lazy(() => import('@/pages/ShowroomPage').then((m) => ({ default: m.ShowroomPage })))
const DepartmentNoticePage = lazy(() => import('@/pages/DepartmentNoticePage').then((m) => ({ default: m.DepartmentNoticePage })))
const PettyCashPage = lazy(() => import('@/pages/PettyCashPage').then((m) => ({ default: m.PettyCashPage })))
const PettyCashDetailPage = lazy(() => import('@/pages/PettyCashDetailPage').then((m) => ({ default: m.PettyCashDetailPage })))
const IntegratorPage = lazy(() => import('@/pages/IntegratorPage').then((m) => ({ default: m.IntegratorPage })))
const AuditLogsPage = lazy(() => import('@/pages/AuditLogsPage').then((m) => ({ default: m.AuditLogsPage })))
const ExpensesPage = lazy(() => import('@/pages/ExpensesPage').then((m) => ({ default: m.ExpensesPage })))
const OwnersPage = lazy(() => import('@/pages/OwnersPage').then((m) => ({ default: m.OwnersPage })))
const OwnerDetailPage = lazy(() => import('@/pages/OwnerDetailPage').then((m) => ({ default: m.OwnerDetailPage })))
const HrPage = lazy(() => import('@/pages/HrPage').then((m) => ({ default: m.HrPage })))
const ChatPage = lazy(() => import('@/pages/ChatPage').then((m) => ({ default: m.ChatPage })))
const CustomerDetailPage = lazy(() => import('@/pages/CustomerDetailPage').then((m) => ({ default: m.CustomerDetailPage })))
const EmployeeDetailPage = lazy(() => import('@/pages/EmployeeDetailPage').then((m) => ({ default: m.EmployeeDetailPage })))
const ProfilePage = lazy(() => import('@/pages/ProfilePage').then((m) => ({ default: m.ProfilePage })))

function PageSuspense({ children }: { children: ReactNode }) {
  return (
    <Suspense
      fallback={
        <div style={{ padding: '1.5rem', maxWidth: 960, marginInline: 'auto' }}>
          <SkeletonBlock rows={8} />
        </div>
      }
    >
      {children}
    </Suspense>
  )
}

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
                    <NewContractProvider>
                      <AppShell />
                    </NewContractProvider>
                  </Protected>
                }
              >
                <Route index element={<PageSuspense><DashboardPage /></PageSuspense>} />
                <Route path="vehicles" element={<PageSuspense><VehiclesPage /></PageSuspense>} />
                <Route path="vehicles/:id" element={<PageSuspense><VehicleDetailPage /></PageSuspense>} />
                <Route path="bookings" element={<PageSuspense><BookingsPage /></PageSuspense>} />
                <Route path="bookings/:id" element={<PageSuspense><ContractDetailPage /></PageSuspense>} />
                <Route path="contracts/:id" element={<PageSuspense><ContractDetailPage /></PageSuspense>} />
                <Route path="calendar" element={<PageSuspense><CalendarPage /></PageSuspense>} />
                <Route path="maintenance" element={<PageSuspense><MaintenancePage /></PageSuspense>} />
                <Route path="inventory" element={<PageSuspense><InventoryPage /></PageSuspense>} />
                <Route path="customers" element={<PageSuspense><CustomersPage /></PageSuspense>} />
                <Route path="customers/:id" element={<PageSuspense><CustomerDetailPage /></PageSuspense>} />
                <Route path="violations" element={<PageSuspense><ViolationsPage /></PageSuspense>} />
                <Route path="violation-inquiries" element={<PageSuspense><ViolationInquiriesPage /></PageSuspense>} />
                <Route path="notifications" element={<PageSuspense><NotificationsPage /></PageSuspense>} />
                <Route path="reports" element={<PageSuspense><ReportsPage /></PageSuspense>} />
                <Route path="accounts" element={<PageSuspense><AccountsPage /></PageSuspense>} />
                <Route path="accounts/months/:monthId" element={<PageSuspense><MonthLedgerPage /></PageSuspense>} />
                <Route path="expenses" element={<PageSuspense><ExpensesPage /></PageSuspense>} />
                <Route path="owners" element={<PageSuspense><OwnersPage /></PageSuspense>} />
                <Route path="owners/:id" element={<PageSuspense><OwnerDetailPage /></PageSuspense>} />
                <Route path="petty-cash" element={<PageSuspense><PettyCashPage /></PageSuspense>} />
                <Route path="petty-cash/:id" element={<PageSuspense><PettyCashDetailPage /></PageSuspense>} />
                <Route path="hr" element={<PageSuspense><HrPage /></PageSuspense>} />
                <Route path="staff/:id" element={<PageSuspense><EmployeeDetailPage /></PageSuspense>} />
                <Route path="employees/:id" element={<PageSuspense><EmployeeDetailPage /></PageSuspense>} />
                <Route path="users/:id" element={<PageSuspense><EmployeeDetailPage /></PageSuspense>} />
                <Route path="chat" element={<PageSuspense><ChatPage /></PageSuspense>} />
                <Route path="showroom" element={<PageSuspense><ShowroomPage /></PageSuspense>} />
                <Route path="department-notice" element={<PageSuspense><DepartmentNoticePage /></PageSuspense>} />
                <Route path="audit-logs" element={<PageSuspense><AuditLogsPage /></PageSuspense>} />
                <Route path="staff" element={<PageSuspense><StaffPage /></PageSuspense>} />
                <Route path="settings" element={<PageSuspense><SettingsPage /></PageSuspense>} />
                <Route path="integrator" element={<PageSuspense><IntegratorPage /></PageSuspense>} />
                <Route path="profile" element={<PageSuspense><ProfilePage /></PageSuspense>} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Route>
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
