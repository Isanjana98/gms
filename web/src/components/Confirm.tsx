import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'
import { Alert, Button, Input, Modal } from './ui'

export interface ConfirmOptions {
  title: string
  /** What is about to happen, and what it costs. Not "Are you sure?". */
  message: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  tone?: 'danger' | 'primary'
  /**
   * Require the exact phrase to be typed.
   *
   * For the few actions no amount of clicking should be able to trigger by
   * accident — resetting data, deleting a member. Typing forces the person to
   * read what they are about to destroy.
   */
  typeToConfirm?: string
  /** Extra context shown in a panel — a count of what will be removed. */
  detail?: ReactNode
}

type Resolver = (confirmed: boolean) => void

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null)

/**
 * A promise-based confirmation dialog.
 *
 * Replaces `window.confirm`, which cannot be styled, cannot show a count of
 * what is about to be destroyed, and looks identical whether it is asking
 * about one attendance row or the whole database.
 *
 * Used for destructive and hard-to-reverse actions only. Confirming ordinary
 * saves would teach people to dismiss dialogs without reading them, which is
 * precisely what makes the important ones stop working.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [options, setOptions] = useState<ConfirmOptions | null>(null)
  const [typed, setTyped] = useState('')
  const resolver = useRef<Resolver | null>(null)

  const confirm = useCallback((next: ConfirmOptions) => {
    setOptions(next)
    setTyped('')

    return new Promise<boolean>((resolve) => {
      resolver.current = resolve
    })
  }, [])

  const close = (confirmed: boolean) => {
    resolver.current?.(confirmed)
    resolver.current = null
    setOptions(null)
    setTyped('')
  }

  const phrase = options?.typeToConfirm
  const canConfirm = !phrase || typed.trim() === phrase

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}

      {options && (
        <Modal
          open
          elevated
          onClose={() => close(false)}
          title={options.title}
          footer={
            <>
              <Button variant="secondary" onClick={() => close(false)}>
                {options.cancelLabel ?? 'Cancel'}
              </Button>
              <Button
                variant={options.tone === 'danger' ? 'danger' : 'primary'}
                disabled={!canConfirm}
                onClick={() => close(true)}
              >
                {options.confirmLabel ?? 'Confirm'}
              </Button>
            </>
          }
        >
          <div className="space-y-4">
            <div className="text-sm text-ink-700">{options.message}</div>

            {options.detail && (
              <div className="rounded-lg bg-ink-50 p-3 text-xs text-ink-600">{options.detail}</div>
            )}

            {phrase && (
              <div className="space-y-2">
                <Alert tone="danger">
                  This cannot be undone. Type <strong>{phrase}</strong> to continue.
                </Alert>
                <Input
                  value={typed}
                  onChange={(e) => setTyped(e.target.value)}
                  placeholder={phrase}
                  autoFocus
                  autoComplete="off"
                  spellCheck={false}
                />
              </div>
            )}
          </div>
        </Modal>
      )}
    </ConfirmContext.Provider>
  )
}

export function useConfirm(): (options: ConfirmOptions) => Promise<boolean> {
  const context = useContext(ConfirmContext)
  if (!context) throw new Error('useConfirm must be used inside a ConfirmProvider.')
  return context
}
