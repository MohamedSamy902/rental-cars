import { useEffect, useState } from 'react'
import { api } from '@/shared/api/client'
import { PageHeader, SkeletonBlock } from '@/shared/ui'
import './pages.css'

export function SettingsPage() {
  const [data, setData] = useState<any>(null)

  useEffect(() => {
    api<{ data: any }>('/settings/tenant').then((r) => setData(r.data))
  }, [])

  if (!data) return <SkeletonBlock rows={5} />

  return (
    <div className="rpm-stack">
      <PageHeader title="الإعدادات" subtitle="إعدادات التشغيل اليومية — لوحة المُدمج منفصلة وغير ظاهرة للمعرض" />
      <section className="rpm-panel">
        <div className="rpm-panel__head"><h3>المركز</h3></div>
        <div className="rpm-dense-list">
          <div className="rpm-dense-row"><div><strong>اسم المركز</strong><p className="muted">{data.center_name}</p></div></div>
          <div className="rpm-dense-row"><div><strong>تعدد الفروع</strong><p className="muted">{data.multi_branch ? 'مفعّل' : 'فرع واحد (الافتراضي)'}</p></div></div>
          <div className="rpm-dense-row"><div><strong>وضع المخزن</strong><p className="muted">{data.inventory_mode === 'unified' ? 'موحّد' : 'لكل فرع'}</p></div></div>
          <div className="rpm-dense-row"><div><strong>فيتشر فلاج</strong><p className="muted">{Object.entries(data.feature_flags || {}).map(([k, v]) => `${k}:${v ? '✓' : '✗'}`).join(' · ')}</p></div></div>
        </div>
      </section>
    </div>
  )
}
