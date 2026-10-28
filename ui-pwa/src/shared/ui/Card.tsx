import type { HTMLAttributes, ReactNode } from 'react'
import './Card.css'

type CardProps = HTMLAttributes<HTMLElement> & {
  interactive?: boolean
  as?: 'article' | 'div' | 'section'
  children: ReactNode
}

export function Card({
  interactive = false,
  as: Tag = 'article',
  className = '',
  children,
  ...rest
}: CardProps) {
  return (
    <Tag
      className={`rpm-card ${interactive ? 'rpm-card--interactive' : ''} ${className}`.trim()}
      {...rest}
    >
      {children}
    </Tag>
  )
}

type HeaderProps = HTMLAttributes<HTMLDivElement> & {
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
}

export function CardHeader({ title, description, action, className = '', ...rest }: HeaderProps) {
  return (
    <div className={`rpm-card__header ${className}`.trim()} {...rest}>
      <div className="rpm-card__header-main">
        <h2 className="rpm-card__title">{title}</h2>
        {description ? <p className="rpm-card__description">{description}</p> : null}
      </div>
      {action ? <div className="rpm-card__action">{action}</div> : null}
    </div>
  )
}

type SlotProps = HTMLAttributes<HTMLDivElement> & {
  children: ReactNode
  flush?: boolean
  dense?: boolean
}

export function CardContent({ flush, dense, className = '', children, ...rest }: SlotProps) {
  const pad = flush ? 'rpm-card__content--flush' : dense ? 'rpm-card__content--dense' : ''
  return (
    <div className={`rpm-card__content ${pad} ${className}`.trim()} {...rest}>
      {children}
    </div>
  )
}

export function CardFooter({ className = '', children, ...rest }: SlotProps) {
  return (
    <div className={`rpm-card__footer ${className}`.trim()} {...rest}>
      {children}
    </div>
  )
}
