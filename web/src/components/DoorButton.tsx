import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
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
/** Where the button was last dragged to, per browser. */
const POSITION_KEY = 'gms.door-button.position'

/** Keeps the button clear of the viewport edges when dragged or on resize. */
const EDGE = 8

type Point = { x: number; y: number }

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), Math.max(min, max))
}

function readStoredPosition(): Point | null {
  try {
    const raw = localStorage.getItem(POSITION_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as Partial<Point>
    return typeof parsed?.x === 'number' && typeof parsed?.y === 'number'
      ? { x: parsed.x, y: parsed.y }
      : null
  } catch {
    // Private windows and blocked site data both throw here. The button
    // simply starts in its default corner.
    return null
  }
}

export function DoorButton() {
  const { can } = useAuth()
  const toast = useToast()
  const canOpen = can(P.devicesOpenDoor)

  const [listOpen, setListOpen] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)

  // null means "never moved", which keeps the default bottom-right anchoring
  // and its safe-area padding rather than pinning a pixel position.
  const [position, setPosition] = useState<Point | null>(readStoredPosition)
  const positionRef = useRef<Point | null>(position)

  const drag = useRef<{ dx: number; dy: number; startX: number; startY: number } | null>(null)
  // Read by onClick. Reset on every pointerdown, so a drag that ends off the
  // button can never swallow the next genuine press.
  const dragged = useRef(false)

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

  // A button parked near the right edge of a wide window would otherwise sit
  // off-screen on a narrow one, unreachable and with no way to bring it back.
  useEffect(() => {
    const onResize = () => {
      const el = wrapRef.current
      const current = positionRef.current
      if (!el || !current) return

      const rect = el.getBoundingClientRect()
      const next = {
        x: clamp(current.x, EDGE, window.innerWidth - rect.width - EDGE),
        y: clamp(current.y, EDGE, window.innerHeight - rect.height - EDGE),
      }

      positionRef.current = next
      setPosition(next)
    }

    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])

  const onPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    // The door list is a menu, not a handle: dragging from it would make
    // choosing a door almost impossible on a touchscreen.
    if ((e.target as HTMLElement).closest('[role="menu"]')) return

    const el = wrapRef.current
    if (!el || e.button !== 0) return

    const rect = el.getBoundingClientRect()
    dragged.current = false
    drag.current = {
      dx: e.clientX - rect.left,
      dy: e.clientY - rect.top,
      startX: e.clientX,
      startY: e.clientY,
    }
    el.setPointerCapture(e.pointerId)
  }

  const onPointerMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    const d = drag.current
    const el = wrapRef.current
    if (!d || !el) return

    // A press that wanders a pixel or two is still a press. Only past the
    // threshold does this become a drag and stop being a door release.
    if (!dragged.current && Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < 5) return
    dragged.current = true

    const rect = el.getBoundingClientRect()
    const next = {
      x: clamp(e.clientX - d.dx, EDGE, window.innerWidth - rect.width - EDGE),
      y: clamp(e.clientY - d.dy, EDGE, window.innerHeight - rect.height - EDGE),
    }

    positionRef.current = next
    setPosition(next)
  }

  const onPointerUp = (e: ReactPointerEvent<HTMLDivElement>) => {
    const wasDragging = drag.current !== null
    drag.current = null
    if (!wasDragging) return

    wrapRef.current?.releasePointerCapture(e.pointerId)
    if (!dragged.current) return

    try {
      if (positionRef.current) {
        localStorage.setItem(POSITION_KEY, JSON.stringify(positionRef.current))
      }
    } catch {
      // Not worth telling anyone about: the button stays where it was put
      // for this visit and returns to the corner on the next one.
    }
  }

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
      className={cx('no-print fixed z-20', position === null && 'bottom-5 right-5')}
      style={
        position === null
          ? {
              // Clear of the phone's home indicator and of a side system bar;
              // both resolve to 0 on a desktop, so the offsets above still
              // apply.
              paddingBottom: 'env(safe-area-inset-bottom, 0px)',
              paddingRight: 'env(safe-area-inset-right, 0px)',
            }
          : // Once moved, the position is explicit and already kept inside the
            // viewport, so the insets have nothing left to protect against.
            { left: position.x, top: position.y }
      }
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
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
        onClick={() => {
          // Let go after moving it and nothing should open.
          if (dragged.current) return
          if (single) openDoor.mutate(single)
          else setListOpen((v) => !v)
        }}
        disabled={busy}
        aria-label={single ? `Open ${single.name}` : 'Open a door'}
        aria-expanded={single ? undefined : listOpen}
        title={single ? `Open ${single.name} · drag to move` : 'Open a door · drag to move'}
        className={cx(
          // touch-none on the button only: a drag starting here must not
          // scroll the page, while the door list above stays scrollable.
          'touch-none select-none active:cursor-grabbing',
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
