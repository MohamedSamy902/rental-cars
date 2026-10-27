import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from '@/shared/ui/AppShell'
import { ToastProvider } from '@/shared/ui'
import { DashboardPage } from '@/pages/DashboardPage'
import { VehiclesPage } from '@/pages/VehiclesPage'
import { BookingsPage } from '@/pages/BookingsPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { MaintenancePage } from '@/pages/MaintenancePage'
import { PlaceholderPage } from '@/pages/PlaceholderPage'

export default function App() {
  return (
    <ToastProvider>
      <BrowserRouter>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<DashboardPage />} />
            <Route path="vehicles" element={<VehiclesPage />} />
            <Route path="bookings" element={<BookingsPage />} />
            <Route path="calendar" element={<CalendarPage />} />
            <Route path="maintenance" element={<MaintenancePage />} />
            <Route
              path="customers"
              element={<PlaceholderPage title="العملاء" subtitle="القائمة السوداء والمرفقات حسب RSD" />}
            />
            <Route
              path="accounts"
              element={<PlaceholderPage title="الحسابات" subtitle="توزيع الشهور وإقفال الشهر" />}
            />
            <Route
              path="settings"
              element={<PlaceholderPage title="الإعدادات" subtitle="إعدادات التشغيل اليومية للمعرض" />}
            />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </ToastProvider>
  )
}
