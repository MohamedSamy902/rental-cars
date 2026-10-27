import type { ReactNode } from 'react'
import './EmptyState.css'

type Props = {
  title: string
  description?: string
  action?: ReactNode
}

export function EmptyState({ title, description, action }: Props) {
  return (
    <div className="rpm-empty">
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {action}
    </div>
  )
}

export function ErrorState({ title, description, action }: Props) {
  return (
    <div className="rpm-empty rpm-empty--error" role="alert">
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {action}
    </div>
  )
}
