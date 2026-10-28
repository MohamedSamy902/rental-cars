import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type MouseEvent as ReactMouseEvent,
  type ReactNode,
  type RefObject,
} from 'react'
import { createPortal } from 'react-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { IconMore } from './Icons'
import { useIsMobile, useSheetDrag } from './useSheetDrag'
import './DropdownMenu.css'

export type MenuAction = {
  id: string
  label: string
  icon?: ReactNode
  tone?: 'default' | 'danger'
  disabled?: boolean
  onSelect: () => void
}
export type MenuSeparator = { id: string; separator: true }
export type MenuItem = MenuAction | MenuSeparator

type Props = {
  items: MenuItem[]
  /** Accessible name of the trigger and title of the mobile action sheet */
  label: string
  /** Custom trigger content; defaults to a ⋯ icon button */
  trigger?: ReactNode
  triggerClassName?: string
  /** Controlled open state (e.g. opened by a swipe gesture) */
  open?: boolean
  onOpenChange?: (open: boolean) => void
}

type Pos = { top: number; left: number; origin: string }

const isAction = (item: MenuItem): item is MenuAction => !('separator' in item)
const spring = { type: 'spring', stiffness: 520, damping: 38, mass: 0.7 } as const

export function DropdownMenu({
  items,
  label,
  trigger,
  triggerClassName = 'rpm-icon-btn',
  open: openProp,
  onOpenChange,
}: Props) {
  const [openState, setOpenState] = useState(false)
  const open = openProp ?? openState
  const mobile = useIsMobile()
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuId = useId()

  const setOpen = useCallback(
    (next: boolean) => {
      if (openProp === undefined) setOpenState(next)
      onOpenChange?.(next)
    },
    [openProp, onOpenChange],
  )

  const close = useCallback(
    (refocus = true) => {
      setOpen(false)
      if (refocus && !mobile) triggerRef.current?.focus()
    },
    [setOpen, mobile],
  )

  function onTriggerClick(e: ReactMouseEvent<HTMLButtonElement>) {
    e.stopPropagation()
    setOpen(!open)
  }

  function onTriggerKey(e: ReactKeyboardEvent<HTMLButtonElement>) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      e.stopPropagation()
      setOpen(true)
    }
  }

  function select(item: MenuAction) {
    if (item.disabled) return
    close(false)
    item.onSelect()
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className={triggerClassName}
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        data-no-drag
        onClick={onTriggerClick}
        onKeyDown={onTriggerKey}
      >
        {trigger ?? <IconMore size={18} />}
      </button>
      {createPortal(
        <AnimatePresence>
          {open ? (
            mobile ? (
              <ActionSheet key="sheet" id={menuId} label={label} items={items} onClose={() => close(false)} onSelect={select} />
            ) : (
              <Popover
                key="popover"
                id={menuId}
                label={label}
                items={items}
                anchor={triggerRef}
                onClose={close}
                onSelect={select}
              />
            )
          ) : null}
        </AnimatePresence>,
        document.body,
      )}
    </>
  )
}

type LayerProps = {
  id: string
  label: string
  items: MenuItem[]
  onSelect: (item: MenuAction) => void
}

function Popover({
  id,
  label,
  items,
  anchor,
  onClose,
  onSelect,
}: LayerProps & { anchor: RefObject<HTMLButtonElement | null>; onClose: (refocus?: boolean) => void }) {
  const menuRef = useRef<HTMLDivElement>(null)
  const [pos, setPos] = useState<Pos | null>(null)
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useLayoutEffect(() => {
    const trigger = anchor.current
    const menu = menuRef.current
    if (!trigger || !menu) return
    const rect = trigger.getBoundingClientRect()
    const w = menu.offsetWidth
    const h = menu.offsetHeight
    const pad = 8
    const gap = 6
    const rtl = getComputedStyle(trigger).direction === 'rtl'
    // Align the menu's inline-end edge with the trigger's inline-end edge so it opens inward
    let left = rtl ? rect.left : rect.right - w
    left = Math.min(Math.max(pad, left), window.innerWidth - w - pad)
    const below = rect.bottom + gap
    const up = below + h > window.innerHeight - pad && rect.top - gap - h > pad
    const top = up ? rect.top - gap - h : below
    setPos({ top, left, origin: `${rtl ? 'left' : 'right'} ${up ? 'bottom' : 'top'}` })
  }, [anchor])

  useEffect(() => {
    const first = menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])')
    first?.focus({ preventScroll: true })
    const onDoc = (e: PointerEvent) => {
      const t = e.target as Node
      if (menuRef.current?.contains(t) || anchor.current?.contains(t)) return
      onCloseRef.current(false)
    }
    const onViewport = () => onCloseRef.current(false)
    document.addEventListener('pointerdown', onDoc)
    window.addEventListener('resize', onViewport)
    window.addEventListener('scroll', onViewport, true)
    return () => {
      document.removeEventListener('pointerdown', onDoc)
      window.removeEventListener('resize', onViewport)
      window.removeEventListener('scroll', onViewport, true)
    }
  }, [anchor])

  function onKeyDown(e: ReactKeyboardEvent<HTMLDivElement>) {
    const nodes = Array.from(
      menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])') ?? [],
    )
    const idx = nodes.indexOf(document.activeElement as HTMLElement)
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      nodes[(idx + 1) % nodes.length]?.focus()
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      nodes[(idx - 1 + nodes.length) % nodes.length]?.focus()
    } else if (e.key === 'Home') {
      e.preventDefault()
      nodes[0]?.focus()
    } else if (e.key === 'End') {
      e.preventDefault()
      nodes[nodes.length - 1]?.focus()
    } else if (e.key === 'Escape') {
      e.preventDefault()
      onClose(true)
    } else if (e.key === 'Tab') {
      onClose(false)
    }
  }

  return (
    <motion.div
      ref={menuRef}
      id={id}
      role="menu"
      aria-label={label}
      className="rpm-menu"
      style={{
        top: pos?.top ?? 0,
        left: pos?.left ?? 0,
        transformOrigin: pos?.origin,
        visibility: pos ? 'visible' : 'hidden',
      }}
      initial={{ opacity: 0, scale: 0.95, y: -4 }}
      animate={{ opacity: 1, scale: 1, y: 0 }}
      exit={{ opacity: 0, scale: 0.97, y: -2, transition: { duration: 0.12 } }}
      transition={spring}
      onKeyDown={onKeyDown}
      // Portals still bubble React events — keep clicks away from clickable rows
      onClick={(e) => e.stopPropagation()}
    >
      <MenuItems items={items} onSelect={onSelect} />
    </motion.div>
  )
}

function ActionSheet({ id, label, items, onClose, onSelect }: LayerProps & { onClose: () => void }) {
  const { startDrag, dragProps } = useSheetDrag(onClose, true)
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onCloseRef.current()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  return (
    <div className="rpm-asheet-root" onClick={(e) => e.stopPropagation()}>
      <motion.button
        type="button"
        className="rpm-asheet-backdrop"
        aria-label="إغلاق"
        onClick={onClose}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }}
      />
      <motion.div
        className="rpm-asheet"
        initial={{ y: '100%' }}
        animate={{ y: 0 }}
        exit={{ y: '100%' }}
        transition={{ type: 'spring', stiffness: 420, damping: 40 }}
        {...dragProps}
      >
        <div className="rpm-asheet__grab" onPointerDown={startDrag}>
          <span className="rpm-asheet__handle" aria-hidden />
          <p className="rpm-asheet__title">{label}</p>
        </div>
        <div id={id} role="menu" aria-label={label} className="rpm-asheet__list">
          <MenuItems items={items} onSelect={onSelect} />
        </div>
        <button type="button" className="rpm-asheet__cancel" onClick={onClose}>
          إلغاء
        </button>
      </motion.div>
    </div>
  )
}

function MenuItems({ items, onSelect }: { items: MenuItem[]; onSelect: (item: MenuAction) => void }) {
  return (
    <>
      {items.map((item) =>
        isAction(item) ? (
          <button
            key={item.id}
            type="button"
            role="menuitem"
            tabIndex={-1}
            aria-disabled={item.disabled || undefined}
            className={`rpm-menu__item ${item.tone === 'danger' ? 'is-danger' : ''}`.trim()}
            onClick={() => onSelect(item)}
          >
            {item.icon ? <span className="rpm-menu__icon">{item.icon}</span> : null}
            <span className="rpm-menu__label">{item.label}</span>
          </button>
        ) : (
          <div key={item.id} role="separator" className="rpm-menu__sep" />
        ),
      )}
    </>
  )
}
