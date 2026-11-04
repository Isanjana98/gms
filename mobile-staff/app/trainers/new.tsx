import { useRouter } from 'expo-router'
import { useState } from 'react'
import { Screen } from '../../src/components/Screen'
import { TrainerForm } from '../../src/components/TrainerForm'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'

/** Add a trainer. */
export default function NewTrainer() {
  const { can } = useAuth()
  const router = useRouter()
  const [saved, setSaved] = useState<string | null>(null)

  if (!can(P.trainersManage)) {
    return (
      <Screen title="Add a trainer" onBack={() => router.back()}>
        <NotPermitted what="manage trainers" />
      </Screen>
    )
  }

  return (
    <Screen title="Add a trainer" onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      <TrainerForm onSaved={setSaved} />
      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
