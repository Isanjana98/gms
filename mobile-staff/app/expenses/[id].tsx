import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { ExpenseForm } from '../../src/components/ExpenseForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Loading } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Expense } from '../../src/lib/types'

/** Correct or remove an expense. */
export default function EditExpense() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const expense = useResource<Expense>('/expenses', id)
  const remove = useAction({ invalidate: ['/expenses', '/reports'] })

  const [saved, setSaved] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)

  if (!can(P.expensesManage)) {
    return (
      <Screen title="Expense" onBack={() => router.back()}>
        <NotPermitted what="change expenses" />
      </Screen>
    )
  }

  if (expense.isLoading) {
    return (
      <Screen title="Expense" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (expense.error || !expense.data) {
    return (
      <Screen title="Expense" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(expense.error)}</Body>
        </Card>
      </Screen>
    )
  }

  return (
    <Screen
      title={expense.data.reference ?? 'Expense'}
      subtitle={expense.data.category?.name ?? undefined}
      onBack={() => router.back()}
    >
      {saved ? <Outcome ok message={saved} /> : null}
      {failure ? <Outcome ok={false} message={failure} /> : null}

      <ExpenseForm
        initial={expense.data}
        onSaved={(message) => {
          setSaved(message)
          void expense.refetch()
        }}
      />

      <Button label="Delete this expense" variant="ghost" onPress={() => setConfirming(true)} />

      <ConfirmSheet
        visible={confirming}
        title="Delete this expense?"
        message="It will no longer count against the gym's costs. This cannot be undone from the app."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          remove.mutate(
            { url: `/expenses/${id}`, method: 'delete' },
            {
              onSuccess: () => router.back(),
              onError: (error) => setFailure(saveError(error)),
            },
          )
        }}
      />
    </Screen>
  )
}
