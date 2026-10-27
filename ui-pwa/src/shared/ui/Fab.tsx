import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { IconPlus } from './Icons'
import './Fab.css'

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  label: string
  icon?: ReactNode
}

export function Fab({ label, icon, className = '', ...rest }: Props) {
  return (
    <button type="button" className={`rpm-fab ${className}`.trim()} aria-label={label} {...rest}>
      {icon ?? <IconPlus size={22} />}
    </button>
  )
}
