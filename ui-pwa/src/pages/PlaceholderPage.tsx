import { PageHeader, EmptyState } from '@/shared/ui'

type Props = {
  title: string
  subtitle?: string
}

export function PlaceholderPage({ title, subtitle }: Props) {
  return (
    <div className="rpm-stack">
      <PageHeader title={title} subtitle={subtitle} />
      <EmptyState
        title="الشاشة مخططة في المواصفة"
        description="الهيكل جاهز في التنقّل؛ التنفيذ التفصيلي للحقول والصلاحيات يتبع جداول RSD والمعايير الملزمة."
      />
    </div>
  )
}
