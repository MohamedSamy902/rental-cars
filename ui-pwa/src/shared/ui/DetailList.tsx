import type { ReactNode } from 'react'
import './DetailList.css'

export type DetailRow = {
  label: string
  value: ReactNode
}

type Props = {
  rows: DetailRow[]
}

export function DetailList({ rows }: Props) {
  return (
    <dl className="rpm-detail">
      {rows.map((row) => (
        <div key={row.label} className="rpm-detail__row">
          <dt>{row.label}</dt>
          <dd>{row.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
