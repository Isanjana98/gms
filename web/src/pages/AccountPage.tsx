import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  Input,
  LoadingBlock,
  PageHeader,
  Table,
  Td,
  Th,
} from '../components/ui'
import { useConfirm } from '../components/Confirm'
import { RecordCard, RecordList } from '../components/RecordList'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { useAuth } from '../lib/auth'
import { dateTime, relative } from '../lib/format'

interface SessionRow {
  id: number
  name: string
  ip_address: string | null
  device: string
  created_at: string | null
  last_used_at: string | null
  expires_at: string | null
  is_current: boolean
}

/**
 * Your own account: password, and where you are signed in.
 *
 * Available to everyone with no permission gate. Someone who suspects their
 * login has been used should be able to look and to end the session
 * themselves, not raise it with an administrator and wait.
 */
export function AccountPage() {
  const { user } = useAuth()

  return (
    <>
      <PageHeader title="Your account" subtitle={user?.email ?? undefined} />

      <div className="grid gap-6 lg:grid-cols-2">
        <PasswordCard />
        <SecurityNotes />
      </div>

      <div className="mt-6">
        <SessionsCard />
      </div>
    </>
  )
}

function PasswordCard() {
  const toast = useToast()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => (await api.post('/auth/password', form)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      setError(null)
      setErrors({})
    },
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = (key: keyof typeof form) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const submit = (event: FormEvent) => {
    event.preventDefault()
    save.mutate()
  }

  return (
    <Card title="Change your password">
      <form onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <Field label="Current password" required error={errors.current_password}>
          <Input
            type="password"
            autoComplete="current-password"
            value={form.current_password}
            onChange={set('current_password')}
            required
          />
        </Field>

        <Field label="New password" required error={errors.password} hint="At least 8 characters.">
          <Input
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={set('password')}
            required
          />
        </Field>

        <Field label="Confirm new password" required error={errors.password_confirmation}>
          <Input
            type="password"
            autoComplete="new-password"
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
            required
          />
        </Field>

        <p className="text-xs text-ink-500">
          Changing your password signs out every other device. This one stays signed in.
        </p>

        <Button type="submit" loading={save.isPending}>
          Update password
        </Button>
      </form>
    </Card>
  )
}

function SessionsCard() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const { data, isLoading, error } = useQuery({
    queryKey: ['auth-sessions'],
    queryFn: async () =>
      (await api.get<{ data: SessionRow[]; idle_timeout_minutes: number }>('/auth/sessions')).data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['auth-sessions'] })

  const revoke = useMutation({
    mutationFn: async (session: SessionRow) => (await api.delete(`/auth/sessions/${session.id}`)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      refresh()
    },
    onError: (err) => toast.error('Could not end that session', errorMessage(err)),
  })

  const revokeOthers = useMutation({
    mutationFn: async () => (await api.post('/auth/sessions/revoke-others')).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      refresh()
    },
    onError: (err) => toast.error('Could not sign out the other sessions', errorMessage(err)),
  })

  const askRevoke = async (session: SessionRow) => {
    const ok = await confirm({
      title: session.is_current ? 'Sign out of this device?' : 'End this session?',
      tone: 'danger',
      confirmLabel: session.is_current ? 'Sign out' : 'End session',
      message: session.is_current ? (
        <>You are using this session now. Ending it signs you out immediately.</>
      ) : (
        <>
          <strong>{session.device}</strong>
          {session.ip_address ? ` at ${session.ip_address}` : ''} will be signed out at once.
        </>
      ),
    })

    if (ok) revoke.mutate(session)
  }

  const askRevokeOthers = async () => {
    const others = (data?.data ?? []).filter((s) => !s.is_current).length

    if (others === 0) {
      toast.info('There are no other sessions to sign out.')
      return
    }

    const ok = await confirm({
      title: 'Sign out everywhere else?',
      tone: 'danger',
      confirmLabel: `Sign out ${others} session${others === 1 ? '' : 's'}`,
      message: (
        <>
          Every other device signed in as you will be signed out at once. This device stays signed in.
        </>
      ),
      detail: <>Do this if you see a session you do not recognise, or if you have signed in on a shared computer.</>,
    })

    if (ok) revokeOthers.mutate()
  }

  const rows = data?.data ?? []
  const idle = data?.idle_timeout_minutes ?? 0

  return (
    <Card
      title="Where you are signed in"
      subtitle={
        idle > 0
          ? `Sessions end automatically after ${idle >= 60 ? `${Math.round(idle / 60)} hours` : `${idle} minutes`} of inactivity.`
          : undefined
      }
      padded={false}
      actions={
        rows.length > 1 && (
          <Button variant="secondary" size="sm" loading={revokeOthers.isPending} onClick={askRevokeOthers}>
            Sign out everywhere else
          </Button>
        )
      }
    >
      {error ? (
        <div className="p-5">
          <Alert>{errorMessage(error)}</Alert>
        </div>
      ) : isLoading ? (
        <LoadingBlock />
      ) : (
        <RecordList
          items={rows}
          keyOf={(session) => session.id}
          card={(session) => (
            <RecordCard
              title={session.device}
              subtitle={session.ip_address ?? undefined}
              badge={session.is_current ? <Badge tone="success">this device</Badge> : undefined}
              facts={[
                { label: 'Last used', value: session.last_used_at ? relative(session.last_used_at) : 'not yet' },
                { label: 'Signed in', value: dateTime(session.created_at) },
              ]}
              actions={
                <Button size="sm" variant="ghost" onClick={() => askRevoke(session)}>
                  {session.is_current ? 'Sign out' : 'End session'}
                </Button>
              }
            />
          )}
          table={
            <Table>
              <thead>
                <tr>
                  <Th>Device</Th>
                  <Th>Address</Th>
                  <Th>Signed in</Th>
                  <Th>Last used</Th>
                  <Th align="right">Actions</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {rows.map((session) => (
                  <tr key={session.id} className="hover:bg-ink-50">
                    <Td>
                      <span className="font-medium">{session.device}</span>
                      {session.is_current && (
                        <span className="ml-1.5">
                          <Badge tone="success">this device</Badge>
                        </span>
                      )}
                      <span className="block text-xs text-ink-500">{session.name}</span>
                    </Td>
                    <Td>{session.ip_address ?? '—'}</Td>
                    <Td>{dateTime(session.created_at)}</Td>
                    <Td>{session.last_used_at ? relative(session.last_used_at) : 'not yet'}</Td>
                    <Td align="right">
                      <Button size="sm" variant="ghost" onClick={() => askRevoke(session)}>
                        {session.is_current ? 'Sign out' : 'End session'}
                      </Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          }
        />
      )}
    </Card>
  )
}

/**
 * Plain-language notes on how the session actually works.
 *
 * Here because the people using this are receptionists and gym owners, not
 * security engineers, and "why am I being signed out?" is otherwise answered
 * by guesswork.
 */
function SecurityNotes() {
  return (
    <Card title="How your session is protected">
      <ul className="space-y-3 text-sm text-ink-700">
        <li>
          <span className="font-medium text-ink-900">Your session is not readable by the page.</span> It is held in a
          cookie the browser will not hand to any script, so it cannot be copied out of this screen.
        </li>
        <li>
          <span className="font-medium text-ink-900">Other sites cannot use it.</span> The browser will not attach your
          session to a request started by another website, and the server refuses any it does not recognise as coming
          from here.
        </li>
        <li>
          <span className="font-medium text-ink-900">Inactivity ends it.</span> A terminal left signed in at the front
          desk stops working on its own, without anyone having to remember to sign out.
        </li>
        <li>
          <span className="font-medium text-ink-900">Signing in is rate limited.</span> Repeated wrong passwords lock
          out further attempts for a time, so a password cannot be guessed at speed.
        </li>
      </ul>
    </Card>
  )
}
