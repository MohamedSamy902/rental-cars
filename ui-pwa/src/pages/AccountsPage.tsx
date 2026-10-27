import { PageHeader, EmptyState } from '@/shared/ui'
import './pages.css'

export function AccountsPage() {
  return (
    <div className="rpm-stack">
      <PageHeader title="الحسابات" subtitle="توزيع الإيجار على الشهور · إقفال شهر · بلا رصيد دائن وبلا ترحيل مخالفات" />
      <EmptyState
        title="الترحيل المحاسبي مفعّل عبر Services"
        description="دليل الحسابات والقيد والتوزيع الشهري جاهزان في الباك. شاشات ميزان المراجعة وقائمة الدخل تُستكمل على نفس API."
      />
    </div>
  )
}
