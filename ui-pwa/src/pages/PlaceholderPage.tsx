import { PageHeader, EmptyState } from '@/shared/ui'

type Props = {
  title: string
  subtitle?: string
}

export function PlaceholderPage({ title, subtitle }: Props) {
  return (
    <div className="rpm-stack">
      <PageHeader title={title} subtitle={subtitle} />
      <EmptyState title="الشاشة ضمن نطاق المواصفة" description="الوحدة مربوطة في التنقّل والمعايير." />
    </div>
  )
}
