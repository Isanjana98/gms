import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { BrandBanner, BrandMark, BrandPanel } from '../components/BrandPanel'
import { Alert, Button, Field, Input, Spinner } from '../components/ui'
import { errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useBranding } from '../lib/branding'

export function LoginPage() {
  const { user, loading, signIn } = useAuth()
  const branding = useBranding()
  const navigate = useNavigate()
  const location = useLocation() as { state?: { from?: string } }

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const expired = new URLSearchParams(window.location.search).has('expired')

  if (loading) {
    return (
      <div className="flex min-h-full items-center justify-center">
        <Spinner className="h-6 w-6 text-ink-400" />
      </div>
    )
  }

  if (user) return <Navigate to={location.state?.from ?? '/'} replace />

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await signIn(email, password)
      navigate(location.state?.from ?? '/', { replace: true })
    } catch (err) {
      setError(errorMessage(err, 'Could not sign you in.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    // Two columns from lg up, form only below it. The panel is decoration;
    // nothing in it is needed to sign in, so it is the part that goes.
    <div className="grid min-h-full lg:grid-cols-[1.1fr_1fr] xl:grid-cols-[1.25fr_1fr]">
      <BrandPanel branding={branding} />

      {/* Below lg the panel is replaced by a short banner at the top, so the
          screen still identifies itself without costing most of the fold. */}
      <div className="flex flex-col bg-white lg:justify-center">
        <BrandBanner branding={branding} />

        {/* Sits under the banner on a phone rather than floating in the
            middle of the remaining space; centred once the banner is gone. */}
        <div className="flex flex-1 items-start justify-center px-5 pb-10 pt-10 sm:px-10 lg:flex-none lg:items-center lg:pt-10">
          <div className="w-full max-w-sm">
            <BrandMark branding={branding} subtitle={`Sign in to ${branding.gym_name}`} />

          <form onSubmit={handleSubmit} className="space-y-5">
            {expired && !error && <Alert tone="warning">Your session has ended. Please sign in again.</Alert>}
            {error && <Alert>{error}</Alert>}

            <Field label="Email address" required>
              <Input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="username"
                placeholder="you@gym.lk"
                autoFocus
                required
              />
            </Field>

            <Field label="Password" required>
              <div className="relative">
                <Input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  className="pr-11"
                  required
                />
                {/*
                  A reveal toggle, because reception types this on a shared
                  terminal and a mistyped password they cannot see is the
                  most common reason to be locked out.
                */}
                <button
                  type="button"
                  onClick={() => setShowPassword((s) => !s)}
                  className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-ink-400 transition hover:text-ink-700"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff /> : <Eye />}
                </button>
              </div>
            </Field>

            <Button type="submit" size="lg" className="w-full" loading={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>
          </form>

            <p className="mt-8 text-xs text-ink-400">
              Trouble signing in? Contact your system administrator.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}

function Eye() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-[18px] w-[18px]">
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" strokeLinejoin="round" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  )
}

function EyeOff() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-[18px] w-[18px]">
      <path
        d="M4 4l16 16M9.9 5.9A9.6 9.6 0 0112 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 01-3 3.9M6.5 8.1A17 17 0 002.5 12S6 18.5 12 18.5c.9 0 1.7-.1 2.5-.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path d="M9.9 9.9a3 3 0 004.2 4.2" strokeLinecap="round" />
    </svg>
  )
}
