import './Skeleton.css'

type Props = {
  height?: number | string
  width?: number | string
  radius?: number | string
  className?: string
}

export function Skeleton({ height = 16, width = '100%', radius = 8, className = '' }: Props) {
  return (
    <span
      className={`rpm-skeleton ${className}`.trim()}
      style={{ height, width, borderRadius: radius }}
      aria-hidden
    />
  )
}

export function SkeletonBlock({ rows = 3 }: { rows?: number }) {
  return (
    <div className="rpm-skeleton-block" role="status" aria-label="جاري التحميل…">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} height={i === 0 ? 22 : 14} width={i === rows - 1 ? '70%' : '100%'} />
      ))}
    </div>
  )
}
