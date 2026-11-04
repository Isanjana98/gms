import { useQuery } from '@tanstack/react-query'
import { api } from './api'

export interface Branding {
  gym_name: string
  tagline: string | null
  logo_url: string | null
  login_images: Array<{ url: string; caption: string | null }>
}

const FALLBACK: Branding = {
  gym_name: 'Gym Manager',
  tagline: 'Every member, every visit, in one place.',
  logo_url: null,
  login_images: [],
}

/**
 * The gym's own name, logo and imagery.
 *
 * Read from a public endpoint, because the sign-in screen needs it before
 * anyone has a token. Cached for an hour: it changes when somebody edits it,
 * not on a timer, and every screen in the app asks for it.
 *
 * Always resolves — a failed request falls back to the neutral defaults rather
 * than leaving the sidebar and sign-in screen blank. Branding is decoration;
 * it must never be the reason someone cannot sign in.
 */
export function useBranding(): Branding {
  const { data } = useQuery({
    queryKey: ['branding'],
    queryFn: async () => (await api.get<Branding>('/branding')).data,
    staleTime: 60 * 60_000,
    retry: 1,
  })

  return data ?? FALLBACK
}

/**
 * The mark shown wherever the gym identifies itself.
 *
 * Falls back to the first letter of the gym's name when no logo is uploaded,
 * so a fresh installation still looks deliberate rather than unfinished.
 */
export function BrandLogo({
  branding,
  className = 'h-8 w-8 text-sm',
  onDark = false,
}: {
  branding: Branding
  className?: string
  onDark?: boolean
}) {
  if (branding.logo_url) {
    return (
      <img
        src={branding.logo_url}
        alt={branding.gym_name}
        className={`shrink-0 rounded-lg object-contain ${className}`}
      />
    )
  }

  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-lg font-bold ${
        onDark ? 'bg-white/15 text-white backdrop-blur' : 'bg-brand-600 text-white'
      } ${className}`}
      aria-hidden="true"
    >
      {branding.gym_name.trim().charAt(0).toUpperCase() || 'G'}
    </span>
  )
}
