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
import { AnimatePresence, motion } from 'framer-motion'
import { IconCheck, IconChevron, IconSearch } from './Icons'
import { AuthMediaImage } from './AuthMediaImage'
import './Field.css'
import './SelectField.css'

export type SelectOption = {
  value: string
  label: string
  description?: string
  /** صورة مصغّرة (مثل صورة العربية) — مسار ميديا محمي */
  imageUrl?: string | null
}

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> & {
  label: string
  error?: string
  hint?: string
  options: SelectOption[]
  placeholder?: string
  searchable?: boolean
  searchPlaceholder?: string
  /** بحث خادم مؤجّل — عند التمرير يحدّث الأب `options` */
  onRemoteSearch?: (query: string) => void | Promise<void>
  remoteSearching?: boolean
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
  onRemoteSearch,
  remoteSearching = false,
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
  const remoteTimer = useRef<number | null>(null)
  const onRemoteSearchRef = useRef(onRemoteSearch)
  onRemoteSearchRef.current = onRemoteSearch
  /** Ignore outside/backdrop dismiss for the opening gesture (iOS/Android ghost click). */
  const ignoreOutsideUntilRef = useRef(0)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const [pos, setPos] = useState<PanelPos | null>(null)
  const [backdropArmed, setBackdropArmed] = useState(false)
  const [uncontrolled, setUncontrolled] = useState(String(defaultValue ?? ''))
  const selectedValue = value !== undefined ? String(value) : uncontrolled
  const showSearch = searchable && (Boolean(onRemoteSearch) || options.length > 6)
  const hasRemoteSearch = Boolean(onRemoteSearch)

  const selected = useMemo(
    () => options.find((o) => o.value === selectedValue) || null,
    [options, selectedValue],
  )

  const filtered = useMemo(() => {
    if (hasRemoteSearch) return options
    const q = query.trim().toLowerCase()
    if (!q) return options
    return options.filter((o) =>
      o.label.toLowerCase().includes(q)
      || (o.description || '').toLowerCase().includes(q)
      || o.value.toLowerCase().includes(q))
  }, [options, query, hasRemoteSearch])

  useEffect(() => {
    if (!hasRemoteSearch || !open) return
    if (remoteTimer.current != null) window.clearTimeout(remoteTimer.current)
    remoteTimer.current = window.setTimeout(() => {
      remoteTimer.current = null
      void onRemoteSearchRef.current?.(query.trim())
    }, 280)
    return () => {
      if (remoteTimer.current != null) window.clearTimeout(remoteTimer.current)
    }
  }, [query, open, hasRemoteSearch])

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
    const viewW = vv?.width ?? window.innerWidth
    const viewH = vv?.height ?? window.innerHeight
    const viewLeft = vv?.offsetLeft ?? 0
    const viewTop = vv?.offsetTop ?? 0
    const viewRight = viewLeft + viewW
    const viewBottom = viewTop + viewH
    const inSheet = Boolean(el.closest('.rpm-sheet__body'))
    const preferred = Math.min(inSheet ? 220 : 280, viewH * (inSheet ? 0.34 : 0.42))
    const spaceBelow = viewBottom - rect.bottom - gap - 10
    const spaceAbove = rect.top - viewTop - gap - 10
    const openUp = spaceBelow < 160 && spaceAbove > spaceBelow
    const maxHeight = Math.max(140, Math.min(preferred, openUp ? spaceAbove : spaceBelow))
    const availableW = Math.max(160, viewW - sidePad * 2)
    const width = Math.min(Math.max(rect.width, Math.min(200, availableW)), availableW)
    const rawLeft = Math.max(viewLeft + sidePad, rect.left)
    const left = Math.min(rawLeft, viewRight - width - sidePad)
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
    setBackdropArmed(false)
    // Block the same tap/click that opened us from dismissing via backdrop/outside listeners.
    ignoreOutsideUntilRef.current = Date.now() + 500
    setOpen(true)
    const idx = Math.max(0, options.findIndex((o) => o.value === selectedValue))
    setActiveIndex(idx < 0 ? 0 : idx)
    // Position immediately so the panel is visible without waiting on sheet scroll.
    updatePosition()
    // Only nudge the sheet if the trigger is clipped — avoid scroll races that
    // move the field under a delayed synthetic click.
    window.setTimeout(() => {
      const el = triggerRef.current
      if (el) {
        const sheetBody = el.closest('.rpm-sheet__body') as HTMLElement | null
        if (sheetBody) {
          const bodyRect = sheetBody.getBoundingClientRect()
          const elRect = el.getBoundingClientRect()
          const pad = 24
          if (elRect.top < bodyRect.top + pad || elRect.bottom > bodyRect.bottom - pad) {
            sheetBody.scrollTop += elRect.top - bodyRect.top - bodyRect.height * 0.28
          }
        }
      }
      updatePosition()
    }, 40)
  }

  function closePanel() {
    setOpen(false)
    setQuery('')
    setPos(null)
    setBackdropArmed(false)
  }

  function closeFromOutside() {
    if (Date.now() < ignoreOutsideUntilRef.current) return
    closePanel()
  }

  function pick(opt: SelectOption) {
    emit(opt.value)
    closePanel()
    triggerRef.current?.focus()
  }

  useEffect(() => {
    if (!open) return
    updatePosition()
    // Arm backdrop after the opening gesture finishes (covers ~300ms iOS ghost click).
    const armTimer = window.setTimeout(() => setBackdropArmed(true), 420)
    // Do NOT autofocus search — that opens the keyboard and shoves the sheet away
    const onWin = () => updatePosition()
    const onDoc = (e: MouseEvent | TouchEvent) => {
      const target = e.target as Node
      if (rootRef.current?.contains(target)) return
      if (listRef.current?.contains(target)) return
      // Backdrop handles its own dismiss; still guard other outside targets.
      if ((target as Element)?.closest?.('.rpm-select-backdrop')) return
      closeFromOutside()
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
      window.clearTimeout(armTimer)
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

  const panel = createPortal(
    <AnimatePresence>
      {open && pos ? (
        <motion.button
          key="backdrop"
          type="button"
          className={`rpm-select-backdrop ${backdropArmed ? 'is-armed' : 'is-guarding'}`}
          aria-label="إغلاق القائمة"
          tabIndex={-1}
          // Swallow the opening gesture; only dismiss after the guard window.
          onMouseDown={(e) => { e.preventDefault(); e.stopPropagation() }}
          onTouchStart={(e) => { e.stopPropagation() }}
          onClick={(e) => {
            e.preventDefault()
            e.stopPropagation()
            closeFromOutside()
          }}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.15 }}
        />
      ) : null}
      {open && pos ? (
        <motion.div
          key="panel"
          ref={listRef}
          className={`rpm-select-panel ${pos.openUp ? 'is-up' : 'is-down'}`}
          style={{
            top: pos.openUp ? undefined : pos.top,
            bottom: pos.openUp ? window.innerHeight - pos.top : undefined,
            left: pos.left,
            width: pos.width,
            maxHeight: pos.maxHeight,
            transformOrigin: pos.openUp ? 'center bottom' : 'center top',
          }}
          initial={{ opacity: 0, scale: 0.96, y: pos.openUp ? 6 : -6 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.98, transition: { duration: 0.12 } }}
          transition={{ type: 'spring', stiffness: 520, damping: 38, mass: 0.7 }}
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
            {remoteSearching ? (
              <div className="rpm-select-panel__empty">جاري البحث…</div>
            ) : filtered.length === 0 ? (
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
                    className={`rpm-select-panel__option ${isSelected ? 'is-selected' : ''} ${isActive ? 'is-active' : ''} ${opt.imageUrl ? 'has-thumb' : ''}`}
                    onMouseEnter={() => setActiveIndex(idx)}
                    onClick={() => pick(opt)}
                  >
                    <span className="rpm-select-panel__check" aria-hidden>
                      {isSelected ? <IconCheck size={15} /> : null}
                    </span>
                    {opt.imageUrl ? (
                      <AuthMediaImage
                        src={opt.imageUrl}
                        alt=""
                        className="rpm-select-panel__thumb"
                      />
                    ) : null}
                    <span className="rpm-select-panel__copy">
                      <span className="rpm-select-panel__text">{opt.label}</span>
                      {opt.description ? (
                        <span className="rpm-select-panel__desc">{opt.description}</span>
                      ) : null}
                    </span>
                  </button>
                )
              })
            )}
          </div>
        </motion.div>
      ) : null}
    </AnimatePresence>,
    document.body,
  )

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
        <span className={`rpm-select-trigger__value ${selected?.imageUrl ? 'has-thumb' : ''}`}>
          {selected ? (
            <>
              {selected.imageUrl ? (
                <AuthMediaImage
                  src={selected.imageUrl}
                  alt=""
                  className="rpm-select-trigger__thumb"
                />
              ) : null}
              <span className="rpm-select-trigger__copy">
                <span className="rpm-select-trigger__primary">{selected.label}</span>
                {selected.description ? (
                  <span className="rpm-select-trigger__secondary">{selected.description}</span>
                ) : null}
              </span>
            </>
          ) : placeholder}
        </span>
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
