import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { money } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { MembershipPlan } from '../../src/lib/types'

/** What a membership costs, and for how long. */
export default function Plans() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<MembershipPlan>('/membership-plans', { perPage: 50 })

  if (!can(P.plansView)) {
    return (
      <Screen title="Plans" onBack={() => router.back()}>
        <NotPermitted what="see plans" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Plans"
      subtitle={`${list.total} available`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      {can(P.plansManage) ? <Button label="Add a plan" onPress={() => router.push('/plans/new')} /> : null}

      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Plan name or code" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No plans"
        emptyDescription="Add one before selling a membership."
        onSelect={can(P.plansManage) ? (row) => router.push(`/plans/${row.id}`) : undefined}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.name}
            subtitle={`${row.duration_label ?? `${row.duration_value} ${row.duration_unit.toLowerCase()}`} · ${row.active_memberships_count ?? 0} active`}
            trailing={money(row.price)}
            status={row.is_active ? undefined : 'inactive'}
            statusTone={theme.warn}
          />
        )}
      />
    </Screen>
  )
}
