import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useToast } from './Toast'
import { cx } from './ui'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import type { BiometricDevice } from '../lib/types'

/**
 * A door release that follows you around the panel.
 *
 * Reception is rarely on the devices page when somebody needs letting in -
 * they are on a member, or taking a payment, and the person at the door is
 * waiting. Making them navigate away, losing whatever half-filled form they
 * had open, is the actual cost of putting this control on one page only.
 *
 * It renders nothing at all unless the signed-in user holds
 * `devices.open_door` AND there is at least one active reader, so for most
 * staff it does not exist.
 */
export function DoorButton() {
  const { can } = useAuth()
  const toast = useToast()
  const canOpen = can(P.devicesOpenDoor)

  const [listOpen, setListOpen] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)

  // Its own key, not the devices page's: that query asks for inactive readers
  // too, and sharing a key would hand this button doors it must not offer.
  const devices = useQuery({
    queryKey: ['door-devices'],
    queryFn: async () => (await api.get<{ data: BiometricDevice[] }>('/biometric/devices')).data.data,
    enabled: canOpen,
    staleTime: 5 * 60_000,
  })

  const openDoor = useMutation({
    mutationFn: async (device: BiometricDevice) =>
      (
        await api.post(`/biometric/devices/${device.id}/open-door`, {
          reason: 'Opened from the quick button',
        })
      ).data,
    onSuccess: (data: { message: string }) => {
      toast.success(data.message)
      setListOpen(false)
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  // Escape closes the list, and so does a press anywhere else. Without the
  // second one the panel stays open behind whatever is clicked next.
  useEffect(() => {
    if (!listOpen) return

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setListOpen(false)
    }
    const onDown = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setListOpen(false)
    }

    document.addEventListener('keydown', onKey)
    document.addEventListener('mousedown', onDown)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('mousedown', onDown)
    }
  }, [listOpen])

  const active = devices.data ?? []
  if (!canOpen || active.length === 0) return null

  const single = active.length === 1 ? active[0] : null
  const busy = openDoor.isPending

  return (
    <div
      ref={wrapRef}
      // z-20 puts this under every layer that is meant to cover the page: the
      // mobile backdrop (30), the drawer (40), modals (50/70) and toasts (80).
      // At z-40 it tied with the drawer and, being later in the tree, painted
      // over it - a button floating on top of the open menu, still live.
      className="no-print fixed bottom-5 right-5 z-20"
      style={{
        // Clear of the phone's home indicator and of a side system bar; both
        // resolve to 0 on a desktop, so the offsets above still apply.
        paddingBottom: 'env(safe-area-inset-bottom, 0px)',
        paddingRight: 'env(safe-area-inset-right, 0px)',
      }}
    >
      {listOpen && !single && (
        <div
          role="menu"
          aria-label="Choose a door"
          className="mb-2 w-60 overflow-hidden rounded-xl border border-ink-200 bg-white shadow-lg"
        >
          <p className="border-b border-ink-100 px-3 py-2 text-xs font-medium uppercase tracking-wide text-ink-500">
            Open which door
          </p>
          {active.map((device) => (
            <button
              key={device.id}
              type="button"
              role="menuitem"
              disabled={busy}
              onClick={() => openDoor.mutate(device)}
              className="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm transition hover:bg-ink-50 focus:bg-ink-50 focus:outline-none disabled:opacity-60"
            >
              <span className="min-w-0">
                <span className="block truncate font-medium text-ink-900">{device.name}</span>
                <span className="block truncate text-xs text-ink-500">{device.ip_address}</span>
              </span>
              {busy && openDoor.variables?.id === device.id && <Spinner />}
            </button>
          ))}
        </div>
      )}

      <button
        type="button"
        // One reader is the normal case, and then this is a single press with
        // nothing in between it and the lock. More than one, and the press
        // has to ask which - a list of doors is information, not a
        // confirmation step.
        onClick={() => (single ? openDoor.mutate(single) : setListOpen((v) => !v))}
        disabled={busy}
        aria-label={single ? `Open ${single.name}` : 'Open a door'}
        aria-expanded={single ? undefined : listOpen}
        title={single ? `Open ${single.name}` : 'Open a door'}
        className={cx(
          'flex items-center gap-2 rounded-full bg-brand-600 py-3 pl-4 pr-5 text-sm font-semibold text-white shadow-lg transition',
          'hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2',
          'disabled:cursor-not-allowed disabled:opacity-70',
        )}
      >
        {busy ? <Spinner light /> : <KeyIcon />}
        <span>Open door</span>
      </button>
    </div>
  )
}

function KeyIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M8 1a4 4 0 0 0-3.9 4.9L1.3 8.7a1 1 0 0 0-.3.7V12a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1v-1h1a1 1 0 0 0 1-1V9h1a1 1 0 0 0 .7-.3l.4-.4A4 4 0 1 0 8 1Zm3.5 3a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"
        clipRule="evenodd"
      />
    </svg>
  )
}

function Spinner({ light }: { light?: boolean }) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={cx('h-5 w-5 animate-spin', light ? 'text-white' : 'text-ink-400')}
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="3" fill="none" opacity="0.25" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" fill="none" strokeLinecap="round" />
    </svg>
  )
}
