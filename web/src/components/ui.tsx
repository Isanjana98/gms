import { useState } from 'react'
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, Ref, SelectHTMLAttributes } from 'react'
import { Link } from 'react-router-dom'
import { openFile } from '../lib/api'
import { useToast } from './Toast'

export function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

/* ------------------------------------------------------------------ */
/* Layout                                                              */
/* ------------------------------------------------------------------ */

export function Card({
  title,
  subtitle,
  actions,
  children,
  className,
  padded = true,
}: {
  title?: ReactNode
  subtitle?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
  padded?: boolean
}) {
  return (
    <section className={cx('rounded-xl border border-ink-200 bg-white shadow-sm', className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-5 py-3.5">
          <div>
            {title && <h2 className="text-sm font-semibold text-ink-900">{title}</h2>}
            {subtitle && <p className="mt-0.5 text-xs text-ink-500">{subtitle}</p>}
          </div>
          {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={padded ? 'p-5' : ''}>{children}</div>
    </section>
  )
}

export function PageHeader({
  title,
  subtitle,
  actions,
}: {
  title: string
  subtitle?: ReactNode
  actions?: ReactNode
}) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      {/* min-w-0 so a long member name wraps instead of shouldering the
          action buttons off the screen. */}
      <div className="min-w-0">
        <h1 className="wrap-anywhere text-lg font-semibold tracking-tight text-ink-900 sm:text-xl">{title}</h1>
        {subtitle && <div className="mt-1 text-sm text-ink-500">{subtitle}</div>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2 no-print">{actions}</div>}
    </div>
  )
}

/**
 * The filter row above a list.
 *
 * Controls go full-width on a phone and sit side by side from `sm` up. Left
 * to themselves they end up as a row of 90px-wide selects that are unreadable
 * and hard to tap.
 */
export function FilterBar({ children }: { children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2 border-b border-ink-200 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-end sm:gap-3 sm:px-5">
      {children}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Controls                                                            */
/* ------------------------------------------------------------------ */

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
}

const BUTTON_VARIANTS = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600 disabled:bg-brand-300',
  secondary:
    'bg-white text-ink-700 ring-1 ring-inset ring-ink-300 hover:bg-ink-50 focus-visible:outline-ink-500 disabled:text-ink-400',
  ghost: 'text-ink-600 hover:bg-ink-100 focus-visible:outline-ink-500',
  danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600 disabled:bg-red-300',
} as const

const BUTTON_SIZES = {
  sm: 'px-2.5 py-1.5 text-xs',
  md: 'px-3.5 py-2 text-sm',
  lg: 'px-5 py-2.5 text-base',
} as const

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  children,
  className,
  ...props
}: ButtonProps) {
  return (
    <button
      {...props}
      disabled={disabled || loading}
      className={cx(
        'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition',
        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
        'disabled:cursor-not-allowed',
        BUTTON_VARIANTS[variant],
        BUTTON_SIZES[size],
        className,
      )}
    >
      {loading && <Spinner className="h-3.5 w-3.5" />}
      {children}
    </button>
  )
}

/**
 * A button that opens or saves a file from the API.
 *
 * Exists because a plain `<a href="/api/...">` silently fails: the session
 * token is in localStorage, so the browser sends no Authorization header when
 * it follows a link, and reception gets an error page instead of a receipt.
 * Everything that produces a PDF or CSV goes through here.
 */
export function FileButton({
  url,
  filename,
  mode = 'view',
  variant = 'secondary',
  size = 'sm',
  onError,
  children,
}: {
  url: string
  filename: string
  mode?: 'view' | 'download'
  variant?: ButtonProps['variant']
  size?: ButtonProps['size']
  onError?: (message: string) => void
  children: ReactNode
}) {
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  const handleClick = async () => {
    setBusy(true)

    try {
      await openFile(url, filename, mode)
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Could not open the file.'

      // Reported either way: a receipt that does nothing when clicked is the
      // worst outcome for the person at the counter.
      if (onError) onError(message)
      else toast.error('Could not open the file', message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant={variant} size={size} loading={busy} onClick={handleClick}>
      {children}
    </Button>
  )
}

export function Field({
  label,
  error,
  hint,
  required,
  children,
}: {
  label: string
  error?: string
  hint?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-ink-700">
        {label}
        {required && <span className="ml-0.5 text-red-600">*</span>}
      </span>
      {children}
      {error ? (
        <span className="mt-1 block text-xs text-red-600">{error}</span>
      ) : (
        hint && <span className="mt-1 block text-xs text-ink-400">{hint}</span>
      )}
    </label>
  )
}

const CONTROL_CLASS =
  'w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-ink-900 ring-1 ring-inset ring-ink-300 ' +
  'placeholder:text-ink-400 focus:ring-2 focus:ring-inset focus:ring-brand-600 disabled:bg-ink-100 disabled:text-ink-500'

// `ref` is declared explicitly: React 19 passes it as an ordinary prop to
// function components, but the type has to say so for callers that need to
// focus an input (the check-in desk keeps the cursor in the search box).
export function Input({
  className,
  invalid,
  ref,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean; ref?: Ref<HTMLInputElement> }) {
  return <input {...props} ref={ref} className={cx(CONTROL_CLASS, invalid && 'ring-red-500 focus:ring-red-500', className)} />
}

export function Select({
  className,
  invalid,
  children,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }) {
  return (
    <select {...props} className={cx(CONTROL_CLASS, invalid && 'ring-red-500 focus:ring-red-500', className)}>
      {children}
    </select>
  )
}

export function Textarea({
  className,
  rows = 3,
  ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} rows={rows} className={cx(CONTROL_CLASS, className)} />
}

/* ------------------------------------------------------------------ */
/* Status and feedback                                                 */
/* ------------------------------------------------------------------ */

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

const TONE_CLASS: Record<Tone, string> = {
  neutral: 'bg-ink-100 text-ink-700 ring-ink-200',
  success: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-800 ring-amber-200',
  danger: 'bg-red-50 text-red-800 ring-red-200',
  info: 'bg-brand-50 text-brand-800 ring-brand-200',
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span
      className={cx(
        'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
        TONE_CLASS[tone],
      )}
    >
      {children}
    </span>
  )
}

/** Maps the statuses in SRS 4, 5 and 12 onto a consistent colour language. */
export function statusTone(status?: string | null): Tone {
  switch (status) {
    case 'ACTIVE':
    case 'PAID':
    case 'COMPLETED':
    case 'ONLINE':
    case 'SYNCED':
    case 'ENROLLED':
    case 'SUCCESS':
      return 'success'
    case 'PENDING':
    case 'PARTIAL':
    case 'OPEN':
    case 'AUTO_CLOSED':
    case 'UNKNOWN':
      return 'warning'
    case 'EXPIRED':
    case 'BLACKLISTED':
    case 'CANCELLED':
    case 'SUSPENDED':
    case 'FAILED':
    case 'OFFLINE':
    case 'ERROR':
    case 'UNPAID':
      return 'danger'
    default:
      return 'neutral'
  }
}

export function StatusBadge({ status }: { status?: string | null }) {
  if (!status) return <span className="text-ink-400">—</span>

  return <Badge tone={statusTone(status)}>{status.replace(/_/g, ' ').toLowerCase()}</Badge>
}

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={cx('animate-spin', className ?? 'h-5 w-5')} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle className="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
  )
}

export function Alert({ tone = 'danger', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <div className={cx('rounded-lg px-3.5 py-2.5 text-sm ring-1 ring-inset', TONE_CLASS[tone])} role="alert">
      {children}
    </div>
  )
}

/**
 * Shown instead of a table when there is genuinely nothing, not while loading.
 *
 * The mark is drawn, not illustrated. A decorative graphic here would be seen
 * hundreds of times a week by people trying to get work done, and the useful
 * signal — *is this empty because nothing matched, or because nothing exists
 * yet?* — is carried by the words. The mark exists so the panel does not read
 * as a rendering failure.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="px-6 py-12 text-center">
      <span
        className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-ink-100 text-ink-400"
        aria-hidden="true"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" className="h-5 w-5">
          <path d="M4 7h16M4 12h10M4 17h6" strokeLinecap="round" />
        </svg>
      </span>
      <p className="text-sm font-medium text-ink-700">{title}</p>
      {description && <p className="mx-auto mt-1 max-w-md text-sm text-ink-500">{description}</p>}
      {action && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  )
}

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 px-6 py-12 text-sm text-ink-500">
      <Spinner className="h-4 w-4" />
      {label}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Tables                                                              */
/* ------------------------------------------------------------------ */

export function Table({ children }: { children: ReactNode }) {
  // Horizontal scrolling is confined to the table so the page itself never
  // scrolls sideways on a narrow screen.
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-ink-200 text-sm">{children}</table>
    </div>
  )
}

export function Th({ children, align = 'left' }: { children?: ReactNode; align?: 'left' | 'right' | 'center' }) {
  return (
    <th
      scope="col"
      className={cx(
        'whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-500',
        align === 'right' && 'text-right',
        align === 'center' && 'text-center',
        align === 'left' && 'text-left',
      )}
    >
      {children}
    </th>
  )
}

export function Td({
  children,
  align = 'left',
  className,
}: {
  children?: ReactNode
  align?: 'left' | 'right' | 'center'
  className?: string
}) {
  return (
    <td
      className={cx(
        'px-4 py-2.5 text-ink-800',
        align === 'right' && 'text-right tabular',
        align === 'center' && 'text-center',
        className,
      )}
    >
      {children}
    </td>
  )
}

export function MemberCell({
  member,
}: {
  member?: { id: number; full_name: string; membership_number: string; photo_url?: string | null } | null
}) {
  if (!member) return <span className="text-ink-400">—</span>

  return (
    <Link to={`/members/${member.id}`} className="group flex items-center gap-2.5">
      <Avatar name={member.full_name} url={member.photo_url} />
      <span>
        <span className="block font-medium text-ink-900 group-hover:text-brand-700">{member.full_name}</span>
        <span className="block text-xs text-ink-500">{member.membership_number}</span>
      </span>
    </Link>
  )
}

export function Avatar({ name, url, size = 'md' }: { name?: string | null; url?: string | null; size?: 'md' | 'lg' }) {
  const dimension = size === 'lg' ? 'h-16 w-16 text-lg' : 'h-8 w-8 text-xs'

  if (url) {
    return <img src={url} alt="" className={cx('shrink-0 rounded-full object-cover', dimension)} />
  }

  const letters = (name ?? '?')
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')

  return (
    <span
      className={cx(
        'flex shrink-0 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-700',
        dimension,
      )}
      aria-hidden="true"
    >
      {letters}
    </span>
  )
}

/* ------------------------------------------------------------------ */
/* Modal                                                               */
/* ------------------------------------------------------------------ */

export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  wide = false,
  elevated = false,
}: {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
  wide?: boolean
  /**
   * Sits above another modal.
   *
   * For a confirmation raised from inside a dialog — "record this refund?"
   * over the refund form. At the same depth the two backdrops fight, and the
   * confirmation can end up behind the thing it is confirming.
   */
  elevated?: boolean
}) {
  if (!open) return null

  return (
    <div
      className={cx(
        'fixed inset-0 flex items-start justify-center overflow-y-auto bg-ink-900/40 p-4 sm:p-8',
        elevated ? 'z-[70]' : 'z-50',
      )}
    >
      <div
        className={cx('w-full rounded-xl bg-white shadow-xl', wide ? 'max-w-3xl' : 'max-w-lg')}
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <header className="flex items-center justify-between border-b border-ink-200 px-5 py-3.5">
          <h2 className="text-sm font-semibold text-ink-900">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-700"
            aria-label="Close"
          >
            <svg viewBox="0 0 20 20" className="h-4 w-4" fill="currentColor">
              <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
            </svg>
          </button>
        </header>
        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>
        {footer && <footer className="flex justify-end gap-2 border-t border-ink-200 px-5 py-3">{footer}</footer>}
      </div>
    </div>
  )
}
