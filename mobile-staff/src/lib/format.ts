// Copied from the member app (mobile/src/lib/format.ts). See src/lib/theme.ts for why the two
// apps keep their own copies of these primitives.
export const CURRENCY = 'LKR'

export function money(amount: number | string | null | undefined): string {
  const value = Number(amount ?? 0)
  if (Number.isNaN(value)) return `${CURRENCY} 0.00`

  return `${CURRENCY} ${value.toLocaleString('en-LK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function parse(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

/** Day-month-year: unambiguous, unlike a numeric format that differs by country. */
export function formatDate(value: string | null | undefined, fallback = '—'): string {
  const date = parse(value)
  if (!date) return fallback

  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function formatDateTime(value: string | null | undefined, fallback = '—'): string {
  const date = parse(value)
  if (!date) return fallback

  return `${formatDate(value)}, ${date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}

export function formatTime(value: string | null | undefined, fallback = '—'): string {
  const date = parse(value)
  if (!date) return fallback

  return date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

/** "1h 25m" reads faster than "85 minutes" when scanning a column of sessions. */
export function duration(minutes: number | null | undefined): string {
  if (minutes == null) return '—'
  if (minutes < 60) return `${minutes}m`

  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60

  return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`
}

/**
 * How an expiry should read to a member.
 *
 * The number of days is what matters — "in 3 days" prompts a renewal in a way
 * that a date on its own does not.
 */
export function expiryLabel(days: number | null | undefined): string {
  if (days == null) return 'No active membership'
  if (days < 0) return `Expired ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} ago`
  if (days === 0) return 'Expires today'
  if (days === 1) return 'Expires tomorrow'
  return `${days} days left`
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?'

  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}
