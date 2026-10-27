import type { ReactNode } from 'react'
import './PageHeader.css'

type Props = {
  title: string
  subtitle?: string
  action?: ReactNode
  /** Hide header CTA on phone — use shared Fab instead */
  mobileFab?: boolean
}

export function PageHeader({ title, subtitle, action, mobileFab = false }: Props) {
  return (
    <header className={`rpm-page-header ${mobileFab ? 'has-mobile-fab' : ''}`.trim()}>
      <div>
        <h1>{title}</h1>
        {subtitle ? <p>{subtitle}</p> : null}
      </div>
      {action ? <div className="rpm-page-header__action">{action}</div> : null}
    </header>
  )
}
