import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { lazy, Suspense, type ReactNode } from 'react'
import { AuthProvider, useAuth } from '@/shared/auth/AuthContext'
import { RequireRouteAccess } from '@/shared/auth/PermissionGate'
import { ThemeProvider } from '@/shared/theme/ThemeContext'
import { AppShell, ToastProvider, SkeletonBlock } from '@/shared/ui'
import { NewContractProvider } from '@/shared/contracts/NewContract'
import { LoginPage } from '@/pages/LoginPage'
import { VehicleQrPage } from '@/pages/VehicleQrPage'

const DashboardPage = lazy(() => import('@/pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const VehiclesPage = lazy(() => import('@/pages/VehiclesPage').then((m) => ({ default: m.VehiclesPage })))
const VehicleDetailPage = lazy(() => import('@/pages/VehicleDetailPage').then((m) => ({ default: m.VehicleDetailPage })))
const BookingsPage = lazy(() => import('@/pages/BookingsPage').then((m) => ({ default: m.BookingsPage })))
const ContractDetailPage = lazy(() => import('@/pages/ContractDetailPage').then((m) => ({ default: m.ContractDetailPage })))
const MonthLedgerPage = lazy(() => import('@/pages/MonthLedgerPage').then((m) => ({ default: m.MonthLedgerPage })))
const CalendarPage = lazy(() => import('@/pages/CalendarPage').then((m) => ({ default: m.CalendarPage })))
const MaintenancePage = lazy(() => import('@/pages/MaintenancePage').then((m) => ({ default: m.MaintenancePage })))
const MaintenanceVehiclePage = lazy(() => import('@/pages/MaintenanceVehiclePage').then((m) => ({ default: m.MaintenanceVehiclePage })))
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

function PageFallback() {
  return (
    <div style={{ padding: '1.5rem', maxWidth: 720, marginInline: 'auto' }}>
      <SkeletonBlock rows={6} />
    </div>
  )
}

function Protected({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) {
    return <PageFallback />
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
              <Route path="/qr/vehicles/:id" element={<VehicleQrPage />} />
              <Route
                element={
                  <Protected>
                    <RequireRouteAccess>
                      <NewContractProvider>
                        <AppShell />
                      </NewContractProvider>
                    </RequireRouteAccess>
                  </Protected>
                }
              >
                <Route
                  index
                  element={(
                    <Suspense fallback={<PageFallback />}>
                      <DashboardPage />
                    </Suspense>
                  )}
                />
                <Route path="vehicles" element={<Suspense fallback={<PageFallback />}><VehiclesPage /></Suspense>} />
                <Route path="vehicles/:id" element={<Suspense fallback={<PageFallback />}><VehicleDetailPage /></Suspense>} />
                <Route path="bookings" element={<Suspense fallback={<PageFallback />}><BookingsPage /></Suspense>} />
                <Route path="bookings/:id" element={<Suspense fallback={<PageFallback />}><ContractDetailPage /></Suspense>} />
                <Route path="contracts/:id" element={<Suspense fallback={<PageFallback />}><ContractDetailPage /></Suspense>} />
                <Route path="calendar" element={<Suspense fallback={<PageFallback />}><CalendarPage /></Suspense>} />
                <Route path="maintenance" element={<Suspense fallback={<PageFallback />}><MaintenancePage /></Suspense>} />
                <Route path="maintenance/vehicles/:id" element={<Suspense fallback={<PageFallback />}><MaintenanceVehiclePage /></Suspense>} />
                <Route path="inventory" element={<Suspense fallback={<PageFallback />}><InventoryPage /></Suspense>} />
                <Route path="customers" element={<Suspense fallback={<PageFallback />}><CustomersPage /></Suspense>} />
                <Route path="customers/:id" element={<Suspense fallback={<PageFallback />}><CustomerDetailPage /></Suspense>} />
                <Route path="violations" element={<Suspense fallback={<PageFallback />}><ViolationsPage /></Suspense>} />
                <Route path="violation-inquiries" element={<Suspense fallback={<PageFallback />}><ViolationInquiriesPage /></Suspense>} />
                <Route path="notifications" element={<Suspense fallback={<PageFallback />}><NotificationsPage /></Suspense>} />
                <Route path="reports" element={<Suspense fallback={<PageFallback />}><ReportsPage /></Suspense>} />
                <Route path="accounts" element={<Suspense fallback={<PageFallback />}><AccountsPage /></Suspense>} />
                <Route path="accounts/months/:monthId" element={<Suspense fallback={<PageFallback />}><MonthLedgerPage /></Suspense>} />
                <Route path="expenses" element={<Suspense fallback={<PageFallback />}><ExpensesPage /></Suspense>} />
                <Route path="owners" element={<Suspense fallback={<PageFallback />}><OwnersPage /></Suspense>} />
                <Route path="owners/:id" element={<Suspense fallback={<PageFallback />}><OwnerDetailPage /></Suspense>} />
                <Route path="petty-cash" element={<Suspense fallback={<PageFallback />}><PettyCashPage /></Suspense>} />
                <Route path="petty-cash/:id" element={<Suspense fallback={<PageFallback />}><PettyCashDetailPage /></Suspense>} />
                <Route path="hr" element={<Suspense fallback={<PageFallback />}><HrPage /></Suspense>} />
                <Route path="staff/:id" element={<Suspense fallback={<PageFallback />}><EmployeeDetailPage /></Suspense>} />
                <Route path="employees/:id" element={<Suspense fallback={<PageFallback />}><EmployeeDetailPage /></Suspense>} />
                <Route path="users/:id" element={<Suspense fallback={<PageFallback />}><EmployeeDetailPage /></Suspense>} />
                <Route path="chat" element={<Suspense fallback={<PageFallback />}><ChatPage /></Suspense>} />
                <Route path="showroom" element={<Suspense fallback={<PageFallback />}><ShowroomPage /></Suspense>} />
                <Route path="department-notice" element={<Suspense fallback={<PageFallback />}><DepartmentNoticePage /></Suspense>} />
                <Route path="audit-logs" element={<Suspense fallback={<PageFallback />}><AuditLogsPage /></Suspense>} />
                <Route path="staff" element={<Suspense fallback={<PageFallback />}><StaffPage /></Suspense>} />
                <Route path="settings" element={<Suspense fallback={<PageFallback />}><SettingsPage /></Suspense>} />
                <Route path="integrator" element={<Suspense fallback={<PageFallback />}><IntegratorPage /></Suspense>} />
                <Route path="profile" element={<Suspense fallback={<PageFallback />}><ProfilePage /></Suspense>} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Route>
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
