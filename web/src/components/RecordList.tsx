import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { cx } from './ui'

/**
 * A list that is a table on a desktop and cards on a phone.
 *
 * Horizontal scrolling is the usual answer for a wide table on a small screen,
 * and it is the wrong one here: the columns that fall off the right edge are
 * status and expiry — exactly what someone checks a member for. A card shows
 * every field at once and needs no sideways scroll at all.
 *
 * The two renderers are deliberately separate rather than one layout that
 * reflows. A phone row and a desk row want different information in a
 * different order, and pretending otherwise produces something that suits
 * neither.
 */
export function RecordList<T>({
  items,
  keyOf,
  table,
  card,
}: {
  items: T[]
  keyOf: (item: T) => string | number
  /** Rendered at `md` and above. Supply a complete <table> body. */
  table: ReactNode
  /** Rendered below `md`, once per item. */
  card: (item: T) => ReactNode
}) {
  return (
    <>
      <div className="hidden md:block">{table}</div>

      <ul className="divide-y divide-ink-100 md:hidden">
        {items.map((item) => (
          <li key={keyOf(item)} className="px-4 py-3">
            {card(item)}
          </li>
        ))}
      </ul>
    </>
  )
}

/**
 * The card body: a title line, a stack of labelled facts, and actions.
 *
 * Kept as one component so every list reads the same way on a phone — the
 * name always top-left, the status always top-right, the actions always last.
 */
export function RecordCard({
  title,
  subtitle,
  to,
  badge,
  facts,
  actions,
  note,
}: {
  title: ReactNode
  subtitle?: ReactNode
  to?: string
  badge?: ReactNode
  facts?: Array<{ label: string; value: ReactNode }>
  actions?: ReactNode
  note?: ReactNode
}) {
  const heading = (
    <>
      <span className="block font-medium text-ink-900">{title}</span>
      {subtitle && <span className="block text-xs text-ink-500">{subtitle}</span>}
    </>
  )

  return (
    <div className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 wrap-anywhere">
          {to ? (
            <Link to={to} className="block">
              {heading}
            </Link>
          ) : (
            heading
          )}
        </div>
        {badge && <div className="flex shrink-0 flex-wrap justify-end gap-1">{badge}</div>}
      </div>

      {facts && facts.length > 0 && (
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5">
          {facts.map((fact) => (
            <div key={fact.label} className="min-w-0">
              <dt className="text-[11px] uppercase tracking-wide text-ink-400">{fact.label}</dt>
              <dd className="wrap-anywhere text-sm text-ink-800">{fact.value ?? '—'}</dd>
            </div>
          ))}
        </dl>
      )}

      {note && <p className="text-xs text-ink-500">{note}</p>}

      {actions && <div className="flex flex-wrap gap-1.5 pt-0.5">{actions}</div>}
    </div>
  )
}

/** Pagination that stays legible when the buttons have to wrap. */
export function Pager({
  page,
  lastPage,
  onChange,
  className,
}: {
  page: number
  lastPage: number
  onChange: (page: number) => void
  className?: string
}) {
  if (lastPage <= 1) return null

  return (
    <div
      className={cx(
        'flex flex-wrap items-center justify-between gap-2 border-t border-ink-200 px-4 py-3 text-sm',
        className,
      )}
    >
      <span className="text-ink-500">
        Page {page} of {lastPage}
      </span>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={page <= 1}
          onClick={() => onChange(page - 1)}
          className="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink-700 ring-1 ring-inset ring-ink-300 disabled:text-ink-400"
        >
          Previous
        </button>
        <button
          type="button"
          disabled={page >= lastPage}
          onClick={() => onChange(page + 1)}
          className="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink-700 ring-1 ring-inset ring-ink-300 disabled:text-ink-400"
        >
          Next
        </button>
      </div>
    </div>
  )
}
