import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useConfirm } from './Confirm'
import { useToast } from './Toast'
import { Alert, Button, Card, Field, Input, LoadingBlock, cx } from './ui'
import { api, errorMessage } from '../lib/api'

interface Scope {
  scope: string
  label: string
  description: string
  counts: Record<string, number>
  total: number
}

const TABLE_LABELS: Record<string, string> = {
  attendance: 'attendance records',
  biometric_event_logs: 'device scan events',
  attendance_sync_logs: 'synchronisation runs',
  payments: 'payments',
  invoices: 'invoices',
  invoice_items: 'invoice lines',
  memberships: 'memberships',
  expenses: 'recorded expenses',
  expense_categories: 'expense categories',
  members: 'members',
  member_documents: 'member documents',
  biometric_users: 'fingerprint mappings',
  workout_plans: 'gym schedules',
  workout_exercises: 'schedule exercises',
  notifications: 'notifications',
  biometric_devices: 'devices',
  trainers: 'trainers',
  membership_plans: 'membership plans',
}

/**
 * Clearing operational data — for handing over a system after a trial.
 *
 * Built to be hard to trigger by accident. The confirmation states the exact
 * number of rows in each table rather than asking "are you sure?", because
 * "2,481 attendance records and 96 payments" is a decision someone can
 * actually make. A backup runs first, always.
 */
export function DataReset() {
  const toast = useToast()
  const confirm = useConfirm()
  const queryClient = useQueryClient()

  const [selected, setSelected] = useState<string | null>(null)
  const [password, setPassword] = useState('')

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['data-reset'],
    queryFn: async () =>
      (await api.get<{ scopes: Scope[]; kept: string[] }>('/data-reset')).data,
  })

  const reset = useMutation({
    mutationFn: async (scope: string) =>
      (await api.post('/data-reset', { scope, password, confirm: 'RESET' })).data,
    onSuccess: (result: { message: string }) => {
      toast.success('Data reset complete', result.message)
      setPassword('')
      setSelected(null)
      // Everything on screen is now stale, including the counts here.
      queryClient.clear()
      refetch()
    },
    onError: (err) => toast.error('Nothing was deleted', errorMessage(err)),
  })

  if (isLoading) return <LoadingBlock />
  if (error) return <Alert>{errorMessage(error)}</Alert>

  const scopes = data?.scopes ?? []
  const chosen = scopes.find((s) => s.scope === selected)

  const start = async () => {
    if (!chosen) return

    const breakdown = Object.entries(chosen.counts)
      .map(([table, count]) => `${count.toLocaleString()} ${TABLE_LABELS[table] ?? table}`)
      .join(', ')

    const ok = await confirm({
      title: `Delete ${chosen.label.toLowerCase()}?`,
      tone: 'danger',
      confirmLabel: 'Delete permanently',
      typeToConfirm: 'RESET',
      message: (
        <>
          This permanently deletes <strong>{chosen.total.toLocaleString()} rows</strong> from the database. A backup is
          taken first, and it is the only way back.
        </>
      ),
      detail: breakdown ? <>Will be deleted: {breakdown}.</> : 'There is nothing to delete in this scope.',
    })

    if (ok) reset.mutate(chosen.scope)
  }

  return (
    <Card
      title="Reset data"
      subtitle="Clear operational data when handing the system over after a trial"
    >
      <div className="space-y-5">
        <Alert tone="danger">
          This permanently deletes records. A backup runs first and nothing is deleted if it fails, but restoring one is
          a manual job — treat this as a one-way door.
        </Alert>

        <div className="space-y-2">
          {scopes.map((scope) => (
            <label
              key={scope.scope}
              className={cx(
                'flex cursor-pointer gap-3 rounded-lg border p-3 transition',
                selected === scope.scope ? 'border-red-300 bg-red-50/50' : 'border-ink-200 hover:bg-ink-50',
              )}
            >
              <input
                type="radio"
                name="reset-scope"
                checked={selected === scope.scope}
                onChange={() => setSelected(scope.scope)}
                className="mt-0.5 h-4 w-4 shrink-0 border-ink-300 text-red-600 focus:ring-red-600"
              />
              <span className="min-w-0">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium text-ink-900">{scope.label}</span>
                  <span className="rounded bg-ink-100 px-1.5 py-0.5 text-[11px] tabular text-ink-600">
                    {scope.total.toLocaleString()} rows
                  </span>
                </span>
                <span className="mt-0.5 block text-xs leading-relaxed text-ink-500">{scope.description}</span>
              </span>
            </label>
          ))}
        </div>

        <div className="rounded-lg bg-ink-50 p-3">
          <p className="text-xs font-semibold uppercase tracking-wide text-ink-600">Always kept</p>
          <ul className="mt-1.5 space-y-0.5">
            {(data?.kept ?? []).map((item) => (
              <li key={item} className="text-xs text-ink-600">
                · {item}
              </li>
            ))}
          </ul>
        </div>

        <Field
          label="Your password"
          required
          hint="Re-entered because a valid session only proves someone signed in earlier, not that it is still them."
        >
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            className="max-w-xs"
          />
        </Field>

        <Button variant="danger" disabled={!selected || !password} loading={reset.isPending} onClick={start}>
          Reset {chosen ? chosen.label.toLowerCase() : 'data'}
        </Button>
      </div>
    </Card>
  )
}
