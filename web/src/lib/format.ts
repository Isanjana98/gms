import { format, formatDistanceToNowStrict, isValid, parseISO } from 'date-fns'

export const CURRENCY = 'LKR'

/** Money, always with two decimals and grouped thousands. */
export function money(amount: number | string | null | undefined, currency = CURRENCY): string {
  const value = Number(amount ?? 0)
  if (Number.isNaN(value)) return `${currency} 0.00`

  return `${currency} ${value.toLocaleString('en-LK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

export function number(value: number | null | undefined): string {
  return Number(value ?? 0).toLocaleString('en-LK')
}

function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  const date = typeof value === 'string' ? parseISO(value) : value
  return isValid(date) ? date : null
}

/** Day-month-year: unambiguous, unlike a numeric format that differs by country. */
export function date(value: string | Date | null | undefined, fallback = '—'): string {
  const parsed = toDate(value)
  return parsed ? format(parsed, 'dd MMM yyyy') : fallback
}

export function dateTime(value: string | Date | null | undefined, fallback = '—'): string {
  const parsed = toDate(value)
  return parsed ? format(parsed, 'dd MMM yyyy, HH:mm') : fallback
}

export function time(value: string | Date | null | undefined, fallback = '—'): string {
  const parsed = toDate(value)
  return parsed ? format(parsed, 'HH:mm') : fallback
}

export function relative(value: string | Date | null | undefined, fallback = 'never'): string {
  const parsed = toDate(value)
  return parsed ? `${formatDistanceToNowStrict(parsed)} ago` : fallback
}

/** "1h 25m" reads faster than "85 minutes" when scanning a column of sessions. */
export function duration(minutes: number | null | undefined): string {
  if (minutes == null) return '—'
  if (minutes < 60) return `${minutes}m`

  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60

  return remainder === 0 ? `${hours}h` : `${hours}h ${remainder}m`
}

/**
 * How a membership expiry should read to reception.
 *
 * The number of days is what matters at the counter - "in 3 days" prompts a
 * renewal conversation in a way that a date alone does not.
 */
export function expiryLabel(days: number | null | undefined): string {
  if (days == null) return '—'
  if (days < 0) return `expired ${Math.abs(days)}d ago`
  if (days === 0) return 'expires today'
  if (days === 1) return 'expires tomorrow'
  return `in ${days} days`
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?'

  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/** Today in the local timezone, as the API expects it. */
export function todayIso(): string {
  return format(new Date(), 'yyyy-MM-dd')
}

export function isoDaysAgo(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return format(d, 'yyyy-MM-dd')
}
