import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { PlanForm } from '../../src/components/PlanForm'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Loading, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { MembershipPlan } from '../../src/lib/types'

/** Change a plan, or retire it. */
export default function EditPlan() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const plan = useResource<MembershipPlan>('/membership-plans', id)
  const remove = useAction({ invalidate: ['/membership-plans'] })

  const [saved, setSaved] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  if (!can(P.plansManage)) {
    return (
      <Screen title="Plan" onBack={() => router.back()}>
        <NotPermitted what="manage plans" />
      </Screen>
    )
  }

  if (plan.isLoading) {
    return (
      <Screen title="Plan" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (plan.error || !plan.data) {
    return (
      <Screen title="Plan" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(plan.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const active = plan.data.active_memberships_count ?? 0

  return (
    <Screen title={plan.data.name} subtitle={plan.data.code} onBack={() => router.back()}>
      {saved ? <Outcome ok message={saved} /> : null}
      {failure ? <Outcome ok={false} message={failure} /> : null}

      <PlanForm
        initial={plan.data}
        onSaved={(message) => {
          setSaved(message)
          void plan.refetch()
        }}
      />

      <Card>
        <Muted>
          {active > 0
            ? `${active} membership${active === 1 ? '' : 's'} currently use this plan. Deleting it is refused while any are active — turn it off above instead, which stops new sales and leaves those running.`
            : 'No memberships use this plan.'}
        </Muted>
      </Card>

      <Button label="Delete this plan" variant="ghost" onPress={() => setConfirming(true)} />

      <ConfirmSheet
        visible={confirming}
        title="Delete this plan?"
        message="Nobody will be able to be sold it. Memberships already on it are not affected."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          remove.mutate(
            { url: `/membership-plans/${id}`, method: 'delete' },
            { onSuccess: () => router.back(), onError: (error) => setFailure(saveError(error)) },
          )
        }}
      />
    </Screen>
  )
}
