import type { ReactNode } from 'react'
import './PageHeader.css'

type Props = {
  title: string
  subtitle?: string
  action?: ReactNode
}

export function PageHeader({ title, subtitle, action }: Props) {
  return (
    <header className="rpm-page-header">
      <div>
        <h1>{title}</h1>
        {subtitle ? <p>{subtitle}</p> : null}
      </div>
      {action ? <div className="rpm-page-header__action">{action}</div> : null}
    </header>
  )
}
