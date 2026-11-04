import { useRouter } from 'expo-router'
import { useState } from 'react'
import { PlanForm } from '../../src/components/PlanForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'

/** Add a plan people can be sold. */
export default function NewPlan() {
  const { can } = useAuth()
  const router = useRouter()
  const [saved, setSaved] = useState<string | null>(null)

  if (!can(P.plansManage)) {
    return (
      <Screen title="Add a plan" onBack={() => router.back()}>
        <NotPermitted what="manage plans" />
      </Screen>
    )
  }

  return (
    <Screen title="Add a plan" onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      <PlanForm onSaved={setSaved} />
      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
