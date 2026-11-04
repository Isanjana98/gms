import { useRouter } from 'expo-router'
import { useState } from 'react'
import { MemberForm } from '../../src/components/MemberForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'

/** Register somebody at the desk. */
export default function NewMember() {
  const { can } = useAuth()
  const router = useRouter()
  const [saved, setSaved] = useState<string | null>(null)

  if (!can(P.membersCreate)) {
    return (
      <Screen title="Register a member" onBack={() => router.back()}>
        <NotPermitted what="register members" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Register a member"
      subtitle="Name and phone are enough to start"
      onBack={() => router.back()}
    >
      {saved ? <Outcome ok message={saved} /> : null}

      <MemberForm onSaved={(message) => setSaved(message)} />

      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
