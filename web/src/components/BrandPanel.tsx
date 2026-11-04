import { useEffect, useState } from 'react'
import { BrandLogo, type Branding } from '../lib/branding'

/**
 * The branded panel beside the sign-in form.
 *
 * With images uploaded it becomes a slow cross-fade of the gym's own
 * photographs. With none, it falls back to a drawn gradient and SVG — about
 * 2 KB, instant, sharp at any size. A fresh installation therefore looks
 * deliberate rather than broken, and an installation with photographs looks
 * like the gym it belongs to.
 *
 * The gradient sits over any photograph regardless, so white text stays
 * readable whether the image is a dark weights room or a bright studio.
 */
export function BrandPanel({ branding }: { branding: Branding }) {
  const images = branding.login_images
  const current = useRotatingIndex(images.length, 7000)

  return (
    <div className="relative hidden overflow-hidden bg-brand-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
      {images.map((image, index) => (
        <div
          key={image.url}
          className="absolute inset-0 bg-cover bg-center transition-opacity duration-1000"
          style={{ backgroundImage: `url(${image.url})`, opacity: index === current ? 1 : 0 }}
          aria-hidden="true"
        />
      ))}

      {/*
        Two layers rather than one flat wash.

        A uniform overlay heavy enough to guarantee readable text also erases
        the photograph — which defeats the point of uploading one. So: a light
        brand tint across the whole panel, and a stronger scrim only at the
        bottom where the text actually sits. The photo stays visible; the
        words stay legible.
      */}
      <div
        className="absolute inset-0"
        style={{
          background: images.length
            ? 'linear-gradient(135deg, rgba(12,20,38,0.55) 0%, rgba(26,79,180,0.34) 60%, rgba(49,130,246,0.22) 100%)'
            : 'linear-gradient(135deg, #14294f 0%, #1c3b75 45%, #1a4fb4 100%)',
        }}
        aria-hidden="true"
      />

      {images.length > 0 && (
        <div
          className="absolute inset-x-0 bottom-0 h-2/3"
          style={{ background: 'linear-gradient(to top, rgba(8,13,26,0.88) 0%, rgba(8,13,26,0.35) 55%, transparent 100%)' }}
          aria-hidden="true"
        />
      )}

      {images.length === 0 && <Rings />}

      <div className="relative">
        <BrandLogo branding={branding} className="h-11 w-11 text-lg" onDark />
      </div>

      <div className="relative max-w-md">
        <h2 className="text-[2.1rem] font-semibold leading-tight tracking-tight text-white drop-shadow-sm">
          {branding.tagline || 'Every member, every visit, in one place.'}
        </h2>
        <p className="mt-4 text-base leading-relaxed text-brand-100">
          Memberships, payments and fingerprint attendance — from the front desk to the monthly report.
        </p>

        <dl className="mt-10 grid grid-cols-3 gap-6 border-t border-white/20 pt-7">
          {[
            ['Fingerprint', 'attendance'],
            ['Automatic', 'renewals'],
            ['Every change', 'audited'],
          ].map(([lead, tail]) => (
            <div key={lead}>
              <dt className="text-sm font-semibold text-white">{lead}</dt>
              <dd className="text-sm text-brand-200">{tail}</dd>
            </div>
          ))}
        </dl>
      </div>

      <div className="relative flex items-center justify-between gap-4">
        <p className="text-xs text-brand-200">{branding.gym_name}</p>

        {images.length > 1 && (
          <div className="flex gap-1.5" aria-hidden="true">
            {images.map((image, index) => (
              <span
                key={image.url}
                className={`h-1 rounded-full transition-all duration-500 ${
                  index === current ? 'w-6 bg-white/80' : 'w-1.5 bg-white/30'
                }`}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

/** The banner above the form below `lg`, where the panel is hidden. */
export function BrandBanner({ branding }: { branding: Branding }) {
  const images = branding.login_images
  const current = useRotatingIndex(images.length, 7000)

  return (
    <div className="relative overflow-hidden bg-brand-900 px-6 py-9 lg:hidden">
      {images.map((image, index) => (
        <div
          key={image.url}
          className="absolute inset-0 bg-cover bg-center transition-opacity duration-1000"
          style={{ backgroundImage: `url(${image.url})`, opacity: index === current ? 1 : 0 }}
          aria-hidden="true"
        />
      ))}

      <div
        className="absolute inset-0"
        style={{
          background: images.length
            ? 'linear-gradient(to top, rgba(8,13,26,0.90) 0%, rgba(12,20,38,0.55) 70%, rgba(26,79,180,0.30) 100%)'
            : 'linear-gradient(135deg, #14294f 0%, #1c3b75 55%, #1a4fb4 100%)',
        }}
        aria-hidden="true"
      />

      {images.length === 0 && (
        <svg
          className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 opacity-[0.18]"
          viewBox="0 0 400 400"
          fill="none"
          aria-hidden="true"
        >
          {[190, 152, 114, 76].map((r, i) => (
            <circle key={r} cx="200" cy="200" r={r} stroke="white" strokeWidth={i % 2 ? 1.5 : 0.75} />
          ))}
        </svg>
      )}

      <div className="relative">
        <BrandLogo branding={branding} className="mb-3 h-11 w-11 text-lg" onDark />
        <p className="text-lg font-semibold leading-snug text-white">
          {branding.tagline || branding.gym_name}
        </p>
      </div>
    </div>
  )
}

/** The heading above the form itself. */
export function BrandMark({ branding, subtitle }: { branding: Branding; subtitle?: string }) {
  return (
    <div className="mb-8">
      <div className="mb-4 hidden lg:block">
        <BrandLogo branding={branding} className="h-12 w-12 text-xl" />
      </div>
      <h1 className="text-2xl font-semibold tracking-tight text-ink-900">Welcome back</h1>
      {subtitle && <p className="mt-1.5 text-sm text-ink-500">{subtitle}</p>}
    </div>
  )
}

/**
 * Steps through the images on a timer, pausing when the tab is hidden.
 *
 * A cross-fade that keeps running in a background tab burns battery on a phone
 * left on the sign-in screen, and nobody is watching it.
 */
function useRotatingIndex(count: number, intervalMs: number): number {
  const [index, setIndex] = useState(0)

  useEffect(() => {
    if (count <= 1) return

    let timer: number | undefined

    const start = () => {
      timer = window.setInterval(() => setIndex((i) => (i + 1) % count), intervalMs)
    }

    const stop = () => {
      if (timer) window.clearInterval(timer)
      timer = undefined
    }

    const onVisibility = () => (document.hidden ? stop() : start())

    start()
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      stop()
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [count, intervalMs])

  return index
}

/** Concentric rings, suggesting weight plates, for when no photo is set. */
function Rings() {
  return (
    <svg
      className="pointer-events-none absolute -right-24 -top-24 h-[34rem] w-[34rem] opacity-[0.16]"
      viewBox="0 0 400 400"
      fill="none"
      aria-hidden="true"
    >
      {[190, 152, 114, 76, 38].map((r, i) => (
        <circle key={r} cx="200" cy="200" r={r} stroke="white" strokeWidth={i === 1 || i === 3 ? 1.5 : 0.75} />
      ))}
      <circle cx="200" cy="200" r="20" fill="white" fillOpacity="0.35" />
    </svg>
  )
}
