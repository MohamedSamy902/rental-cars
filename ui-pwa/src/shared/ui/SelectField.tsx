import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type SelectHTMLAttributes,
} from 'react'
import { createPortal } from 'react-dom'
import { IconChevron, IconSearch } from './Icons'
import './Field.css'
import './SelectField.css'

type Option = { value: string; label: string }

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> & {
  label: string
  error?: string
  hint?: string
  options: Option[]
  placeholder?: string
  searchable?: boolean
  searchPlaceholder?: string
}

type PanelPos = {
  top: number
  left: number
  width: number
  maxHeight: number
  openUp: boolean
}

export function SelectField({
  label,
  error,
  hint,
  required,
  id,
  name,
  options,
  placeholder = 'اختر…',
  searchable = true,
  searchPlaceholder = 'بحث…',
  className = '',
  value,
  defaultValue,
  disabled,
  onChange,
  onBlur,
}: Props) {
  const reactId = useId()
  const fieldId = id ?? name ?? reactId
  const listId = `${fieldId}-list`
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const [pos, setPos] = useState<PanelPos | null>(null)
  const [uncontrolled, setUncontrolled] = useState(String(defaultValue ?? ''))
  const selectedValue = value !== undefined ? String(value) : uncontrolled
  const showSearch = searchable && options.length > 6

  const selected = useMemo(
    () => options.find((o) => o.value === selectedValue) || null,
    [options, selectedValue],
  )

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return options
    return options.filter((o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q))
  }, [options, query])

  function emit(next: string) {
    if (value === undefined) setUncontrolled(next)
    const event = {
      target: { value: next, name: name ?? '' },
      currentTarget: { value: next, name: name ?? '' },
    } as ChangeEvent<HTMLSelectElement>
    onChange?.(event)
  }

  function updatePosition() {
    const el = triggerRef.current
    if (!el) return
    const rect = el.getBoundingClientRect()
    const gap = 6
    const sidePad = 12
    const vv = window.visualViewport
    const viewH = vv?.height ?? window.innerHeight
    const viewTop = vv?.offsetTop ?? 0
    const viewBottom = viewTop + viewH
    const preferred = Math.min(280, viewH * 0.42)
    const spaceBelow = viewBottom - rect.bottom - gap - 10
    const spaceAbove = rect.top - viewTop - gap - 10
    const openUp = spaceBelow < 160 && spaceAbove > spaceBelow
    const maxHeight = Math.max(140, Math.min(preferred, openUp ? spaceAbove : spaceBelow))
    const width = Math.min(Math.max(rect.width, 200), window.innerWidth - sidePad * 2)
    const left = Math.min(Math.max(sidePad, rect.left), window.innerWidth - width - sidePad)
    setPos({
      top: openUp ? rect.top - gap : rect.bottom + gap,
      left,
      width,
      maxHeight,
      openUp,
    })
  }

  function openPanel() {
    if (disabled) return
    setQuery('')
    setOpen(true)
    const idx = Math.max(0, options.findIndex((o) => o.value === selectedValue))
    setActiveIndex(idx < 0 ? 0 : idx)
    // Keep the field visible above the keyboard / panel
    window.setTimeout(() => {
      triggerRef.current?.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' })
      updatePosition()
    }, 40)
  }

  function closePanel() {
    setOpen(false)
    setQuery('')
    setPos(null)
  }

  function pick(opt: Option) {
    emit(opt.value)
    closePanel()
    triggerRef.current?.focus()
  }

  useEffect(() => {
    if (!open) return
    updatePosition()
    // Do NOT autofocus search — that opens the keyboard and shoves the sheet away
    const onWin = () => updatePosition()
    const onDoc = (e: MouseEvent | TouchEvent) => {
      const target = e.target as Node
      if (rootRef.current?.contains(target)) return
      if (listRef.current?.contains(target)) return
      closePanel()
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault()
        closePanel()
        triggerRef.current?.focus()
      }
    }
    window.addEventListener('resize', onWin)
    window.addEventListener('scroll', onWin, true)
    window.visualViewport?.addEventListener('resize', onWin)
    window.visualViewport?.addEventListener('scroll', onWin)
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('touchstart', onDoc, { passive: true })
    document.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('resize', onWin)
      window.removeEventListener('scroll', onWin, true)
      window.visualViewport?.removeEventListener('resize', onWin)
      window.visualViewport?.removeEventListener('scroll', onWin)
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('touchstart', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const idx = filtered.findIndex((o) => o.value === selectedValue)
    setActiveIndex(idx >= 0 ? idx : 0)
  }, [filtered, open, selectedValue])

  useEffect(() => {
    if (!open || !listRef.current) return
    const item = listRef.current.querySelector<HTMLElement>(`[data-idx="${activeIndex}"]`)
    item?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, open])

  function onTriggerKey(e: ReactKeyboardEvent<HTMLButtonElement>) {
    if (disabled) return
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      openPanel()
    }
  }

  function onSearchKey(e: ReactKeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, Math.max(filtered.length - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const opt = filtered[activeIndex]
      if (opt) pick(opt)
    } else if (e.key === 'Escape') {
      e.preventDefault()
      closePanel()
      triggerRef.current?.focus()
    }
  }

  const panel = open && pos
    ? createPortal(
        <>
          <button
            type="button"
            className="rpm-select-backdrop"
            aria-label="إغلاق القائمة"
            onClick={closePanel}
          />
          <div
            ref={listRef}
            className={`rpm-select-panel ${pos.openUp ? 'is-up' : 'is-down'}`}
            style={{
              top: pos.openUp ? undefined : pos.top,
              bottom: pos.openUp ? window.innerHeight - pos.top : undefined,
              left: pos.left,
              width: pos.width,
              maxHeight: pos.maxHeight,
            }}
            role="listbox"
            id={listId}
            aria-label={label}
          >
            {showSearch ? (
              <div className="rpm-select-panel__search">
                <IconSearch size={15} />
                <input
                  ref={searchRef}
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  onKeyDown={onSearchKey}
                  placeholder={searchPlaceholder}
                  aria-label={searchPlaceholder}
                  enterKeyHint="search"
                />
              </div>
            ) : null}

            <div className="rpm-select-panel__list">
              {filtered.length === 0 ? (
                <div className="rpm-select-panel__empty">لا توجد نتائج</div>
              ) : (
                filtered.map((opt, idx) => {
                  const isSelected = opt.value === selectedValue
                  const isActive = idx === activeIndex
                  return (
                    <button
                      key={opt.value || `empty-${idx}`}
                      type="button"
                      role="option"
                      data-idx={idx}
                      aria-selected={isSelected}
                      className={`rpm-select-panel__option ${isSelected ? 'is-selected' : ''} ${isActive ? 'is-active' : ''}`}
                      onMouseEnter={() => setActiveIndex(idx)}
                      onClick={() => pick(opt)}
                    >
                      <span>{opt.label}</span>
                      {isSelected ? <em aria-hidden>✓</em> : null}
                    </button>
                  )
                })
              )}
            </div>
          </div>
        </>,
        document.body,
      )
    : null

  return (
    <div className={`rpm-field ${error ? 'has-error' : ''}`} ref={rootRef}>
      <span className="rpm-field__label" id={`${fieldId}-label`}>
        {label}
        {required ? <span className="rpm-field__req" aria-hidden>*</span> : null}
      </span>

      <button
        ref={triggerRef}
        id={fieldId}
        type="button"
        className={`rpm-input rpm-select-trigger ${open ? 'is-open' : ''} ${!selected ? 'is-placeholder' : ''} ${className}`.trim()}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-labelledby={`${fieldId}-label`}
        disabled={disabled}
        onClick={() => (open ? closePanel() : openPanel())}
        onKeyDown={onTriggerKey}
        onBlur={onBlur as never}
      >
        <span className="rpm-select-trigger__value">{selected?.label || placeholder}</span>
        <IconChevron size={16} className="rpm-select-trigger__chev" />
      </button>

      <select
        className="rpm-select-native"
        tabIndex={-1}
        aria-hidden
        name={name}
        required={required}
        disabled={disabled}
        value={selectedValue}
        onChange={(e) => emit(e.target.value)}
      >
        <option value="">{placeholder}</option>
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>

      {error ? <span className="rpm-field__error">{error}</span> : null}
      {!error && hint ? <span className="rpm-field__hint">{hint}</span> : null}
      {panel}
    </div>
  )
}
