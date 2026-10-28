import type { ReactNode } from 'react'
import { IconAlert, IconSearch } from './Icons'
import './EmptyState.css'

type Props = {
  title: string
  description?: string
  action?: ReactNode
  icon?: ReactNode
}

export function EmptyState({ title, description, action, icon }: Props) {
  return (
    <div className="rpm-empty">
      <span className="rpm-empty__icon" aria-hidden>
        {icon ?? <IconSearch size={22} />}
      </span>
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {action ? <div className="rpm-empty__action">{action}</div> : null}
    </div>
  )
}

export function ErrorState({ title, description, action, icon }: Props) {
  return (
    <div className="rpm-empty rpm-empty--error" role="alert">
      <span className="rpm-empty__icon" aria-hidden>
        {icon ?? <IconAlert size={22} />}
      </span>
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {action ? <div className="rpm-empty__action">{action}</div> : null}
    </div>
  )
}
