import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { FormRow, FormSection, Outcome, TextField } from '../../src/components/form'
import { Button, Card, Eyebrow, Fact, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { useAuth } from '../../src/lib/auth'
import { formatDate, formatTime } from '../../src/lib/format'
import { fieldErrors, saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { SessionRow } from '../../src/lib/types'

/**
 * Your own account: the password, and everywhere it is currently signed in.
 *
 * The session list matters more on a staff tool than it looks. A token here
 * can read every member's telephone number, so "which devices are holding
 * one?" is a question somebody should be able to answer and act on from the
 * phone in their hand — including the case where the phone in their hand is
 * the only one they still have.
 */
export default function Account() {
  const { user } = useAuth()
  const router = useRouter()

  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [revoking, setRevoking] = useState(false)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const sessions = useQuery({
    queryKey: ['/auth/sessions'],
    queryFn: async () => {
      const { data } = await api.get<{ data: SessionRow[] } | SessionRow[]>('/auth/sessions')
      return Array.isArray(data) ? data : data.data
    },
  })

  const act = useAction({ invalidate: ['/auth/sessions'] })
  const errors = fieldErrors(act.error)

  const changePassword = () => {
    setResult(null)

    if (next !== confirm) {
      setResult({ ok: false, message: 'The two new passwords do not match.' })
      return
    }

    act.mutate(
      {
        url: '/auth/password',
        payload: { current_password: current, password: next, password_confirmation: confirm },
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: 'Password changed.' })
          setCurrent('')
          setNext('')
          setConfirm('')
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen
      title="Your account"
      subtitle={user?.email}
      onBack={() => router.back()}
      onRefresh={() => void sessions.refetch()}
      refreshing={sessions.isRefetching}
    >
      <Card>
        <Eyebrow>You</Eyebrow>
        <Fact label="Name" value={user?.name ?? '—'} />
        <Fact label="Role" value={user?.roles?.join(', ') ?? '—'} />
        <Fact label="Branch" value={user?.branch?.name ?? (user?.is_multi_branch ? 'All branches' : '—')} />
        <Fact label="Permissions" value={String(user?.permissions?.length ?? 0)} />
      </Card>

      <FormSection title="Change your password">
        <FormRow label="Current password" error={errors.current_password}>
          <TextField testID="current-password" value={current} onChange={setCurrent} secure autoCapitalize="none" />
        </FormRow>
        <FormRow label="New password" hint="At least eight characters." error={errors.password}>
          <TextField testID="new-password" value={next} onChange={setNext} secure autoCapitalize="none" />
        </FormRow>
        <FormRow label="New password again">
          <TextField value={confirm} onChange={setConfirm} secure autoCapitalize="none" />
        </FormRow>
        <Button label="Change password" onPress={changePassword} loading={act.isPending} />
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Eyebrow>Signed in on</Eyebrow>
      <ResourceList
        rows={sessions.data ?? []}
        loading={sessions.isLoading}
        error={sessions.error ? saveError(sessions.error) : undefined}
        onRetry={() => void sessions.refetch()}
        emptyTitle="No other sessions"
        renderRow={(row) => (
          <Row
            title={row.is_current ? `${row.device ?? row.name} (this one)` : (row.device ?? row.name)}
            subtitle={`${row.ip_address ?? 'unknown address'} · last used ${formatDate(
              row.last_used_at,
            )} ${formatTime(row.last_used_at)}`}
            status={row.is_current ? 'current' : undefined}
            statusTone={theme.good}
          />
        )}
      />

      <Button label="Sign out everywhere else" variant="ghost" onPress={() => setRevoking(true)} />

      <Muted>
        This ends every other session but leaves this one alone, so you will not lock yourself out by pressing
        it. Use it if a device has been lost.
      </Muted>

      <ConfirmSheet
        visible={revoking}
        title="Sign out every other device?"
        message="Anyone signed in elsewhere has to sign in again. This device stays signed in."
        confirmLabel="Sign them out"
        onCancel={() => setRevoking(false)}
        onConfirm={() => {
          setRevoking(false)
          setResult(null)
          act.mutate(
            { url: '/auth/sessions/revoke-others', payload: {} },
            {
              onSuccess: () => {
                setResult({ ok: true, message: 'Every other session has been ended.' })
                void sessions.refetch()
              },
              onError: (error) => setResult({ ok: false, message: saveError(error) }),
            },
          )
        }}
      />
    </Screen>
  )
}
