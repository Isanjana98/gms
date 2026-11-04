/**
 * The member app's palette.
 *
 * The same near-black and red as the public website, and for the same reason:
 * a member who found the gym on its site and then installs the app should not
 * feel they have arrived somewhere else. The values are duplicated rather than
 * imported because the app cannot reach the website's stylesheet — when one
 * changes, change both.
 */
export const theme = {
  bg: '#08080A',
  bg2: '#0E0E11',
  surface: '#141418',
  surface2: '#1B1B21',
  line: 'rgba(255,255,255,0.09)',
  lineStrong: 'rgba(255,255,255,0.18)',

  text: '#F4F4F6',
  muted: '#9A9AA6',
  dim: '#6B6B76',

  accent: '#E11D2E',
  accentHot: '#FF3446',

  good: '#10B981',
  warn: '#F59E0B',
  bad: '#EF4444',

  radius: 14,
  gutter: 20,
} as const

/**
 * How urgent an expiry is.
 *
 * The single thing a member opens this app to find out, so it gets a colour
 * rather than a number they have to interpret. Seven days is the threshold
 * because that is roughly when renewing stops being "later" and starts being
 * "this week".
 */
export function expiryTone(daysRemaining: number | null | undefined) {
  if (daysRemaining == null) return theme.dim
  if (daysRemaining < 0) return theme.bad
  if (daysRemaining <= 7) return theme.warn
  return theme.good
}

/**
 * The colour of the member's own status.
 *
 * Kept separate from expiryTone, because the two can legitimately disagree —
 * a member suspended at reception still has days left on the term they paid
 * for. Colouring the status badge by the expiry produced a green pill reading
 * "inactive", which is worse than no colour at all.
 */
export function statusTone(status: string | null | undefined) {
  switch ((status ?? '').toUpperCase()) {
    case 'ACTIVE':
      return theme.good
    case 'EXPIRED':
    case 'BLACKLISTED':
      return theme.bad
    case 'SUSPENDED':
    case 'INACTIVE':
      return theme.warn
    default:
      return theme.dim
  }
}
