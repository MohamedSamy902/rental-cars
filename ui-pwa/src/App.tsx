import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@/shared/auth/AuthContext'
import { ThemeProvider } from '@/shared/theme/ThemeContext'
import { AppShell, ToastProvider, SkeletonBlock } from '@/shared/ui'
import { NewContractProvider } from '@/shared/contracts/NewContract'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { VehiclesPage } from '@/pages/VehiclesPage'
import { VehicleDetailPage } from '@/pages/VehicleDetailPage'
import { BookingsPage } from '@/pages/BookingsPage'
import { ContractDetailPage } from '@/pages/ContractDetailPage'
import { MonthLedgerPage } from '@/pages/MonthLedgerPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { MaintenancePage } from '@/pages/MaintenancePage'
import { CustomersPage } from '@/pages/CustomersPage'
import { InventoryPage } from '@/pages/InventoryPage'
import { ViolationsPage } from '@/pages/ViolationsPage'
import { ViolationInquiriesPage } from '@/pages/ViolationInquiriesPage'
import { NotificationsPage } from '@/pages/NotificationsPage'
import { ReportsPage } from '@/pages/ReportsPage'
import { AccountsPage } from '@/pages/AccountsPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { StaffPage } from '@/pages/StaffPage'
import { ShowroomPage } from '@/pages/ShowroomPage'
import { DepartmentNoticePage } from '@/pages/DepartmentNoticePage'
import { PettyCashPage } from '@/pages/PettyCashPage'
import { PettyCashDetailPage } from '@/pages/PettyCashDetailPage'
import { IntegratorPage } from '@/pages/IntegratorPage'
import { AuditLogsPage } from '@/pages/AuditLogsPage'
import { ExpensesPage } from '@/pages/ExpensesPage'
import { OwnersPage } from '@/pages/OwnersPage'
import { OwnerDetailPage } from '@/pages/OwnerDetailPage'
import { HrPage } from '@/pages/HrPage'
import { ChatPage } from '@/pages/ChatPage'
import { CustomerDetailPage } from '@/pages/CustomerDetailPage'
import { EmployeeDetailPage } from '@/pages/EmployeeDetailPage'
import { ProfilePage } from '@/pages/ProfilePage'
import { VehicleQrPage } from '@/pages/VehicleQrPage'
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
              <Route path="/qr/vehicles/:id" element={<VehicleQrPage />} />
              <Route
                element={
                  <Protected>
                    <NewContractProvider>
                      <AppShell />
                    </NewContractProvider>
                  </Protected>
                }
              >
                <Route index element={<DashboardPage />} />
                <Route path="vehicles" element={<VehiclesPage />} />
                <Route path="vehicles/:id" element={<VehicleDetailPage />} />
                <Route path="bookings" element={<BookingsPage />} />
                <Route path="bookings/:id" element={<ContractDetailPage />} />
                <Route path="contracts/:id" element={<ContractDetailPage />} />
                <Route path="calendar" element={<CalendarPage />} />
                <Route path="maintenance" element={<MaintenancePage />} />
                <Route path="inventory" element={<InventoryPage />} />
                <Route path="customers" element={<CustomersPage />} />
                <Route path="customers/:id" element={<CustomerDetailPage />} />
                <Route path="violations" element={<ViolationsPage />} />
                <Route path="violation-inquiries" element={<ViolationInquiriesPage />} />
                <Route path="notifications" element={<NotificationsPage />} />
                <Route path="reports" element={<ReportsPage />} />
                <Route path="accounts" element={<AccountsPage />} />
                <Route path="accounts/months/:monthId" element={<MonthLedgerPage />} />
                <Route path="expenses" element={<ExpensesPage />} />
                <Route path="owners" element={<OwnersPage />} />
                <Route path="owners/:id" element={<OwnerDetailPage />} />
                <Route path="petty-cash" element={<PettyCashPage />} />
                <Route path="petty-cash/:id" element={<PettyCashDetailPage />} />
                <Route path="hr" element={<HrPage />} />
                <Route path="staff/:id" element={<EmployeeDetailPage />} />
                <Route path="employees/:id" element={<EmployeeDetailPage />} />
                <Route path="users/:id" element={<EmployeeDetailPage />} />
                <Route path="chat" element={<ChatPage />} />
                <Route path="showroom" element={<ShowroomPage />} />
                <Route path="department-notice" element={<DepartmentNoticePage />} />
                <Route path="audit-logs" element={<AuditLogsPage />} />
                <Route path="staff" element={<StaffPage />} />
                <Route path="settings" element={<SettingsPage />} />
                <Route path="integrator" element={<IntegratorPage />} />
                {/* Self-profile: any authenticated user — no permission gate */}
                <Route path="profile" element={<ProfilePage />} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Route>
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
