import type { ReactNode } from 'react'
import './DetailList.css'

export type DetailRow = {
  label: string
  value: ReactNode
  /** Long values (addresses, notes, vehicle names) take the full row */
  wide?: boolean
}

type Props = {
  rows: DetailRow[]
}

export function DetailList({ rows }: Props) {
  return (
    <dl className="rpm-detail">
      {rows.map((row) => (
        <div key={row.label} className={`rpm-detail__item ${row.wide ? 'is-wide' : ''}`.trim()}>
          <dt>{row.label}</dt>
          <dd>{row.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
