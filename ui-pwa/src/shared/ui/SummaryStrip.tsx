import './SummaryStrip.css'

export type SummaryItem = {
  label: string
  value: string
  tone?: 'default' | 'success' | 'warning' | 'danger'
}

type Props = {
  items: SummaryItem[]
}

export function SummaryStrip({ items }: Props) {
  return (
    <div className="rpm-summary" role="group" aria-label="ملخص">
      {items.map((item) => (
        <div key={item.label} className={`rpm-summary__item tone-${item.tone ?? 'default'}`}>
          <span className="rpm-summary__value">{item.value}</span>
          <span className="rpm-summary__label">{item.label}</span>
        </div>
      ))}
    </div>
  )
}
