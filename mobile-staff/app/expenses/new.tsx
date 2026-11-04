import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ExpenseForm } from '../../src/components/ExpenseForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'

/** Record something the gym paid for. */
export default function NewExpense() {
  const { can } = useAuth()
  const router = useRouter()
  const [saved, setSaved] = useState<string | null>(null)

  if (!can(P.expensesManage)) {
    return (
      <Screen title="Record an expense" onBack={() => router.back()}>
        <NotPermitted what="record expenses" />
      </Screen>
    )
  }

  return (
    <Screen title="Record an expense" subtitle="Counted against this month's costs" onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      <ExpenseForm onSaved={setSaved} />
      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
