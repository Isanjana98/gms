import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { TrainerForm } from '../../src/components/TrainerForm'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Loading, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Trainer } from '../../src/lib/types'

/** Change a trainer, or remove them. */
export default function EditTrainer() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const trainer = useResource<Trainer>('/trainers', id)
  const remove = useAction({ invalidate: ['/trainers'] })

  const [saved, setSaved] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  if (!can(P.trainersManage)) {
    return (
      <Screen title="Trainer" onBack={() => router.back()}>
        <NotPermitted what="manage trainers" />
      </Screen>
    )
  }

  if (trainer.isLoading) {
    return (
      <Screen title="Trainer" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (trainer.error || !trainer.data) {
    return (
      <Screen title="Trainer" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(trainer.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const assigned = trainer.data.members_count ?? 0

  return (
    <Screen title={trainer.data.full_name} subtitle={trainer.data.trainer_code} onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      {failure ? <Outcome ok={false} message={failure} /> : null}

      <TrainerForm
        initial={trainer.data}
        onSaved={(message) => {
          setSaved(message)
          void trainer.refetch()
        }}
      />

      {assigned > 0 ? (
        <Card>
          <Muted>
            {assigned} of the gym&apos;s members are assigned to them. Marking the trainer inactive above is
            usually better than deleting: it keeps the history on those members intact.
          </Muted>
        </Card>
      ) : null}

      <Button label="Delete this trainer" variant="ghost" onPress={() => setConfirming(true)} />

      <ConfirmSheet
        visible={confirming}
        title="Delete this trainer?"
        message="Members assigned to them will be left without one."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          remove.mutate(
            { url: `/trainers/${id}`, method: 'delete' },
            { onSuccess: () => router.back(), onError: (error) => setFailure(saveError(error)) },
          )
        }}
      />
    </Screen>
  )
}
