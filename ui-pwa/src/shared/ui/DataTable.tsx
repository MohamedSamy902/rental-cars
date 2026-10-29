import { useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { motion, type PanInfo } from 'framer-motion'
import { DropdownMenu, type MenuItem } from './DropdownMenu'
import { EmptyState } from './EmptyState'
import { Skeleton } from './Skeleton'
import { IconChevron, IconSortAsc, IconSortDesc, IconSortNone } from './Icons'
import { useIsMobile } from './useSheetDrag'
import './DataTable.css'

export type DataColumn<T> = {
  id: string
  header: string
  cell: (row: T) => ReactNode
  /** Enables sorting on this column */
  sortValue?: (row: T) => string | number | null | undefined
  align?: 'start' | 'end' | 'center'
  width?: string
  /** Role of the column in the phone card. Defaults to a key-value field. */
  mobile?: 'title' | 'subtitle' | 'badge' | 'field' | 'hidden'
  /** Spans both columns of the phone card grid */
  wide?: boolean
}

type SortState = { id: string; dir: 'asc' | 'desc' } | null

type Props<T> = {
  label: string
  rows: T[]
  columns: DataColumn<T>[]
  rowKey: (row: T) => string | number
  /** Plain-text name of a row, used to title its actions menu */
  rowLabel?: (row: T) => string
  loading?: boolean
  onRowClick?: (row: T) => void
  rowActions?: (row: T) => MenuItem[]
  emptyTitle?: string
  emptyDescription?: string
  emptyAction?: ReactNode
  pageSize?: number
  defaultSort?: SortState
}

const collator = new Intl.Collator('ar', { numeric: true, sensitivity: 'base' })
const SWIPE_OPEN = 72

function compare(a: unknown, b: unknown) {
  if (a == null && b == null) return 0
  if (a == null) return 1
  if (b == null) return -1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return collator.compare(String(a), String(b))
}

function pageWindow(page: number, count: number): number[] {
  const size = Math.min(5, count)
  const start = Math.max(0, Math.min(page - 2, count - size))
  return Array.from({ length: size }, (_, i) => start + i)
}

export function DataTable<T>({
  label,
  rows,
  columns,
  rowKey,
  rowLabel,
  loading = false,
  onRowClick,
  rowActions,
  emptyTitle = 'لا توجد بيانات',
  emptyDescription,
  emptyAction,
  pageSize = 10,
  defaultSort = null,
}: Props<T>) {
  const mobile = useIsMobile()
  const [sort, setSort] = useState<SortState>(defaultSort)
  const [page, setPage] = useState(0)

  const sorted = useMemo(() => {
    if (!sort) return rows
    const col = columns.find((c) => c.id === sort.id)
    if (!col?.sortValue) return rows
    const get = col.sortValue
    const out = [...rows].sort((a, b) => compare(get(a), get(b)))
    return sort.dir === 'desc' ? out.reverse() : out
  }, [rows, columns, sort])

  const pageCount = Math.max(1, Math.ceil(sorted.length / pageSize))
  const current = Math.min(page, pageCount - 1)
  const visible = sorted.slice(current * pageSize, current * pageSize + pageSize)
  const from = sorted.length === 0 ? 0 : current * pageSize + 1
  const to = Math.min(sorted.length, (current + 1) * pageSize)

  function toggleSort(id: string) {
    setPage(0)
    setSort((prev) => {
      if (!prev || prev.id !== id) return { id, dir: 'asc' }
      if (prev.dir === 'asc') return { id, dir: 'desc' }
      return null
    })
  }

  const hasActions = Boolean(rowActions)
  const footer =
    !loading && sorted.length > 0 ? (
      <footer className="rpm-dt__foot">
        <span className="rpm-dt__count">
          عرض <b>{from}</b>–<b>{to}</b> من <b>{sorted.length}</b>
        </span>
        {pageCount > 1 ? (
          <nav className="rpm-dt__pager" aria-label="التنقل بين الصفحات">
            <button
              type="button"
              className="rpm-dt__page rpm-dt__page--nav"
              aria-label="الصفحة السابقة"
              disabled={current === 0}
              onClick={() => setPage(current - 1)}
            >
              <IconChevron size={16} />
            </button>
            {pageWindow(current, pageCount).map((p) => (
              <button
                key={p}
                type="button"
                className={`rpm-dt__page ${p === current ? 'is-current' : ''}`.trim()}
                aria-current={p === current ? 'page' : undefined}
                onClick={() => setPage(p)}
              >
                {p + 1}
              </button>
            ))}
            <button
              type="button"
              className="rpm-dt__page rpm-dt__page--nav is-next"
              aria-label="الصفحة التالية"
              disabled={current >= pageCount - 1}
              onClick={() => setPage(current + 1)}
            >
              <IconChevron size={16} />
            </button>
          </nav>
        ) : null}
      </footer>
    ) : null

  const empty =
    !loading && sorted.length === 0 ? (
      <div className="rpm-dt__empty">
        <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
      </div>
    ) : null

  if (mobile) {
    return (
      <section className="rpm-dt rpm-dt--cards" aria-label={label} aria-busy={loading || undefined}>
        {loading ? (
          <div className="rpm-dt__cards">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="rpm-dt-card is-skeleton">
                <Skeleton height={18} width="55%" />
                <Skeleton height={12} width="35%" />
                <div className="rpm-dt-card__grid">
                  <Skeleton height={28} />
                  <Skeleton height={28} />
                </div>
              </div>
            ))}
          </div>
        ) : null}
        {empty}
        {!loading && visible.length > 0 ? (
          <div className="rpm-dt__cards" key={current}>
            {visible.map((row, i) => (
              <SwipeCard
                key={rowKey(row)}
                index={i}
                row={row}
                columns={columns}
                label={rowLabel?.(row) ?? label}
                onRowClick={onRowClick}
                actions={rowActions?.(row)}
              />
            ))}
          </div>
        ) : null}
        {footer}
      </section>
    )
  }

  const sortIcon = (id: string) => {
    if (sort?.id !== id) return <IconSortNone size={13} />
    return sort.dir === 'asc' ? <IconSortAsc size={13} /> : <IconSortDesc size={13} />
  }

  function onRowKey(e: KeyboardEvent<HTMLTableRowElement>, row: T) {
    if (!onRowClick || e.target !== e.currentTarget) return
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      onRowClick(row)
    }
  }

  return (
    <section className="rpm-dt" aria-label={label} aria-busy={loading || undefined}>
      <div className="rpm-dt__scroll">
        <table className="rpm-dt__table">
          <thead>
            <tr>
              {columns.map((col) => (
                <th
                  key={col.id}
                  scope="col"
                  style={{ width: col.width }}
                  className={col.align ? `is-${col.align}` : undefined}
                  aria-sort={
                    sort?.id === col.id ? (sort.dir === 'asc' ? 'ascending' : 'descending') : col.sortValue ? 'none' : undefined
                  }
                >
                  {col.sortValue ? (
                    <button
                      type="button"
                      className={`rpm-dt__sort ${sort?.id === col.id ? 'is-active' : ''}`.trim()}
                      onClick={() => toggleSort(col.id)}
                    >
                      {col.header}
                      <span className="rpm-dt__sort-icon">{sortIcon(col.id)}</span>
                    </button>
                  ) : (
                    col.header
                  )}
                </th>
              ))}
              {hasActions ? (
                <th scope="col" className="rpm-dt__actions-col">
                  <span className="rpm-sr-only">إجراءات</span>
                </th>
              ) : null}
            </tr>
          </thead>
          <tbody>
            {loading
              ? Array.from({ length: 6 }).map((_, i) => (
                  <tr key={`sk-${i}`} className="is-skeleton">
                    {columns.map((col) => (
                      <td key={col.id}>
                        <Skeleton height={12} width={`${55 + ((i * 17 + col.id.length * 7) % 40)}%`} />
                      </td>
                    ))}
                    {hasActions ? <td /> : null}
                  </tr>
                ))
              : visible.map((row, i) => {
                  const actions = rowActions?.(row) ?? []
                  return (
                    <motion.tr
                      key={rowKey(row)}
                      className={onRowClick ? 'is-clickable' : undefined}
                      tabIndex={onRowClick ? 0 : undefined}
                      onClick={onRowClick ? () => onRowClick(row) : undefined}
                      onKeyDown={(e) => onRowKey(e, row)}
                      initial={{ opacity: 0, y: 4 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.22, delay: Math.min(i, 10) * 0.025, ease: [0.22, 1, 0.36, 1] }}
                    >
                      {columns.map((col) => (
                        <td key={col.id} className={col.align ? `is-${col.align}` : undefined}>
                          {col.cell(row)}
                        </td>
                      ))}
                      {hasActions ? (
                        <td className="rpm-dt__actions-cell">
                          {actions.length > 0 ? <DropdownMenu label={`إجراءات ${rowLabel?.(row) ?? label}`} items={actions} /> : null}
                        </td>
                      ) : null}
                    </motion.tr>
                  )
                })}
          </tbody>
        </table>
        {empty}
      </div>
      {footer}
    </section>
  )
}

type CardProps<T> = {
  row: T
  index: number
  columns: DataColumn<T>[]
  label: string
  onRowClick?: (row: T) => void
  actions?: MenuItem[]
}

function SwipeCard<T>({ row, index, columns, label, onRowClick, actions = [] }: CardProps<T>) {
  const [menuOpen, setMenuOpen] = useState(false)
  const dragged = useRef(false)
  const role = (c: DataColumn<T>) => c.mobile ?? 'field'
  const title = columns.find((c) => role(c) === 'title')
  const subtitle = columns.filter((c) => role(c) === 'subtitle')
  const badge = columns.find((c) => role(c) === 'badge')
  const fields = columns.filter((c) => role(c) === 'field')
  const canSwipe = actions.length > 0

  function onDragEnd(_: unknown, info: PanInfo) {
    if (Math.abs(info.offset.x) > SWIPE_OPEN) setMenuOpen(true)
    window.setTimeout(() => {
      dragged.current = false
    }, 0)
  }

  return (
    <div className="rpm-dt-swipe">
      {canSwipe ? (
        <div className="rpm-dt-swipe__hint" aria-hidden>
          <span>الإجراءات</span>
          <span>الإجراءات</span>
        </div>
      ) : null}
      <motion.article
        className={`rpm-dt-card ${onRowClick ? 'is-clickable' : ''}`.trim()}
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.24, delay: Math.min(index, 8) * 0.035, ease: [0.22, 1, 0.36, 1] }}
        drag={canSwipe ? 'x' : false}
        dragDirectionLock
        dragConstraints={{ left: 0, right: 0 }}
        dragElastic={0.35}
        dragSnapToOrigin
        onDragStart={() => {
          dragged.current = true
        }}
        onDragEnd={onDragEnd}
        onClick={() => {
          if (dragged.current) return
          onRowClick?.(row)
        }}
        role={onRowClick ? 'button' : undefined}
        tabIndex={onRowClick ? 0 : undefined}
        onKeyDown={(e) => {
          if (onRowClick && e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault()
            onRowClick(row)
          }
        }}
      >
        <header className="rpm-dt-card__head">
          <div className="rpm-dt-card__titles">
            {title ? <strong className="rpm-dt-card__title">{title.cell(row)}</strong> : null}
            {subtitle.map((c) => (
              <span key={c.id} className="rpm-dt-card__sub">
                {c.cell(row)}
              </span>
            ))}
          </div>
          {badge ? <div className="rpm-dt-card__badge">{badge.cell(row)}</div> : null}
          {canSwipe ? (
            <DropdownMenu label={label} items={actions} open={menuOpen} onOpenChange={setMenuOpen} />
          ) : null}
        </header>
        {fields.length > 0 ? (
          <dl className="rpm-dt-card__grid">
            {fields.map((c) => (
              <div key={c.id} className={`rpm-dt-card__field ${c.wide ? 'is-wide' : ''}`.trim()}>
                <dt>{c.header}</dt>
                <dd>{c.cell(row)}</dd>
              </div>
            ))}
          </dl>
        ) : null}
      </motion.article>
    </div>
  )
}
