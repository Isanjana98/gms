import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { cx } from './ui'

type Tone = 'success' | 'danger' | 'warning' | 'info'

interface Toast {
  id: number
  tone: Tone
  message: string
  detail?: string
}

interface ToastApi {
  /** Something worked. Auto-dismisses. */
  success: (message: string, detail?: string) => void
  /** Something failed. Stays until dismissed — an error nobody read is an error nobody fixed. */
  error: (message: string, detail?: string) => void
  warning: (message: string, detail?: string) => void
  info: (message: string, detail?: string) => void
}

const ToastContext = createContext<ToastApi | null>(null)

const AUTO_DISMISS_MS = 5000

/**
 * Transient confirmations, stacked bottom-right.
 *
 * Replaces the per-page notice banners. Those pushed the page content down as
 * they appeared, so the row somebody had just clicked moved under their cursor
 * — and each page had to carry its own notice state to show one.
 *
 * Successes fade on their own. Failures do not: an error that vanished before
 * it was read is an error nobody acted on.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const push = useCallback(
    (tone: Tone, message: string, detail?: string) => {
      const id = Date.now() + Math.random()

      setToasts((current) => [...current.slice(-3), { id, tone, message, detail }])

      if (tone !== 'danger') {
        window.setTimeout(() => dismiss(id), AUTO_DISMISS_MS)
      }
    },
    [dismiss],
  )

  const api = useMemo<ToastApi>(
    () => ({
      success: (message, detail) => push('success', message, detail),
      error: (message, detail) => push('danger', message, detail),
      warning: (message, detail) => push('warning', message, detail),
      info: (message, detail) => push('info', message, detail),
    }),
    [push],
  )

  return (
    <ToastContext.Provider value={api}>
      {children}

      {/*
        aria-live so a screen reader announces the outcome. "polite" rather
        than "assertive": it should not interrupt someone mid-sentence, and
        every message here is a confirmation of something they just did.
      */}
      <div
        // Above every dialog: a toast is the result of what someone just did,
        // and hiding it behind the dialog that caused it defeats the point.
        className="pointer-events-none fixed inset-x-0 bottom-0 z-[80] flex flex-col items-center gap-2 p-4 sm:items-end"
        aria-live="polite"
        aria-atomic="false"
      >
        {toasts.map((toast) => (
          <ToastCard key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  )
}

const TONE_STYLES: Record<Tone, { bar: string; icon: string; path: string }> = {
  success: { bar: 'bg-emerald-500', icon: 'text-emerald-600', path: 'M5 13l4 4L19 7' },
  danger: { bar: 'bg-red-500', icon: 'text-red-600', path: 'M12 8v5m0 3.5h.01M10.3 3.9L2.7 17a2 2 0 001.7 3h15.2a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z' },
  warning: { bar: 'bg-amber-500', icon: 'text-amber-600', path: 'M12 8v5m0 3.5h.01M12 3a9 9 0 100 18 9 9 0 000-18z' },
  info: { bar: 'bg-brand-500', icon: 'text-brand-600', path: 'M12 16v-5m0-3.5h.01M12 3a9 9 0 100 18 9 9 0 000-18z' },
}

function ToastCard({ toast, onDismiss }: { toast: Toast; onDismiss: () => void }) {
  const style = TONE_STYLES[toast.tone]

  return (
    <div
      role={toast.tone === 'danger' ? 'alert' : 'status'}
      className="pointer-events-auto flex w-full max-w-md overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-ink-900/10"
    >
      <span className={cx('w-1 shrink-0', style.bar)} aria-hidden="true" />

      <div className="flex flex-1 items-start gap-3 p-3.5">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          className={cx('mt-0.5 h-[18px] w-[18px] shrink-0', style.icon)}
          aria-hidden="true"
        >
          <path d={style.path} strokeLinecap="round" strokeLinejoin="round" />
        </svg>

        <div className="min-w-0 flex-1">
          <p className="wrap-anywhere text-sm font-medium text-ink-900">{toast.message}</p>
          {toast.detail && <p className="wrap-anywhere mt-0.5 text-xs text-ink-500">{toast.detail}</p>}
        </div>

        <button
          type="button"
          onClick={onDismiss}
          className="-m-1 shrink-0 rounded p-1 text-ink-400 transition hover:bg-ink-100 hover:text-ink-700"
          aria-label="Dismiss"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4">
            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
          </svg>
        </button>
      </div>
    </div>
  )
}

export function useToast(): ToastApi {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast must be used inside a ToastProvider.')
  return context
}
