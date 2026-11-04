import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { MemberForm } from '../../src/components/MemberForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Loading, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { MemberSummary } from '../../src/lib/types'

/** Correct a member's details. */
export default function EditMember() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const member = useResource<MemberSummary & Record<string, unknown>>('/members', id)
  const remove = useAction({ invalidate: ['/members'] })

  const [saved, setSaved] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  if (!can(P.membersUpdate)) {
    return (
      <Screen title="Member" onBack={() => router.back()}>
        <NotPermitted what="change member details" />
      </Screen>
    )
  }

  if (member.isLoading) {
    return (
      <Screen title="Member" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (member.error || !member.data) {
    return (
      <Screen title="Member" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(member.error)}</Body>
        </Card>
      </Screen>
    )
  }

  return (
    <Screen
      title={member.data.full_name}
      subtitle={member.data.membership_number}
      onBack={() => router.back()}
    >
      {saved ? <Outcome ok message={saved} /> : null}
      {failure ? <Outcome ok={false} message={failure} /> : null}

      <MemberForm
        initial={member.data}
        onSaved={(message) => {
          setSaved(message)
          void member.refetch()
        }}
      />

      {can(P.membersDelete) ? (
        <>
          <Card>
            <Muted>
              Deleting a member is a soft delete: their visits, payments and invoices stay in the books, which
              is what the accounts need. It is still not the tool for somebody who has merely lapsed — they
              expire on their own.
            </Muted>
          </Card>
          <Button label="Delete this member" variant="ghost" onPress={() => setConfirming(true)} />
        </>
      ) : null}

      <ConfirmSheet
        visible={confirming}
        title={`Delete ${member.data.full_name}?`}
        message="They will stop appearing at the desk. Their payment history is kept."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          remove.mutate(
            { url: `/members/${id}`, method: 'delete' },
            { onSuccess: () => router.back(), onError: (error) => setFailure(saveError(error)) },
          )
        }}
      />
    </Screen>
  )
}
