import { useRouter } from 'expo-router'
import { useState } from 'react'
import { Screen } from '../../src/components/Screen'
import { UserForm } from '../../src/components/UserForm'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'

/** Create a staff account. */
export default function NewUser() {
  const { can } = useAuth()
  const router = useRouter()
  const [saved, setSaved] = useState<string | null>(null)

  if (!can(P.usersManage)) {
    return (
      <Screen title="Create an account" onBack={() => router.back()}>
        <NotPermitted what="manage staff accounts" />
      </Screen>
    )
  }

  return (
    <Screen title="Create an account" onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      <UserForm onSaved={setSaved} />
      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
