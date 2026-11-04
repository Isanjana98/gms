import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { UserForm } from '../../src/components/UserForm'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Loading, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { StaffAccount } from '../../src/lib/types'

/** Change a staff account, or close it. */
export default function EditUser() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can, user } = useAuth()
  const router = useRouter()

  const account = useResource<StaffAccount>('/users', id)
  const remove = useAction({ invalidate: ['/users'] })

  const [saved, setSaved] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  if (!can(P.usersManage)) {
    return (
      <Screen title="Account" onBack={() => router.back()}>
        <NotPermitted what="manage staff accounts" />
      </Screen>
    )
  }

  if (account.isLoading) {
    return (
      <Screen title="Account" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (account.error || !account.data) {
    return (
      <Screen title="Account" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(account.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const isSelf = String(account.data.id) === String(user?.id)

  return (
    <Screen title={account.data.name} subtitle={account.data.email} onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      {failure ? <Outcome ok={false} message={failure} /> : null}

      <UserForm
        initial={account.data}
        onSaved={(message) => {
          setSaved(message)
          void account.refetch()
        }}
      />

      {isSelf ? (
        <Card>
          <Muted>
            This is your own account. Deleting it is refused by the server, which is the right answer — an
            administrator locking themselves out is not a recoverable mistake from a phone.
          </Muted>
        </Card>
      ) : (
        <Button label="Delete this account" variant="ghost" onPress={() => setConfirming(true)} />
      )}

      <ConfirmSheet
        visible={confirming}
        title={`Delete ${account.data.name}?`}
        message="They will not be able to sign in again. What they have already recorded stays in the audit log."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          remove.mutate(
            { url: `/users/${id}`, method: 'delete' },
            { onSuccess: () => router.back(), onError: (error) => setFailure(saveError(error)) },
          )
        }}
      />
    </Screen>
  )
}
