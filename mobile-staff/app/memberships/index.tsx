import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { expiryTone, statusTone } from '../../src/lib/theme'
import type { Membership } from '../../src/lib/types'

/** Terms sold, running and finished. */
export default function Memberships() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Membership>('/memberships', { perPage: 20 })

  if (!can(P.membershipsView)) {
    return (
      <Screen title="Memberships" onBack={() => router.back()}>
        <NotPermitted what="see memberships" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Memberships"
      subtitle={`${list.total} sold`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      {can(P.membershipsCreate) ? (
        <Button label="Sell a membership" onPress={() => router.push('/memberships/new')} />
      ) : null}

      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Reference, member or plan" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No memberships"
        emptyDescription="Nothing sold that matches."
        onSelect={(row) => router.push(`/memberships/${row.id}`)}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.member?.full_name ?? 'Unknown member'}
            subtitle={`${row.plan_name} · until ${formatDate(row.end_date)}`}
            trailing={row.balance > 0 ? `${money(row.balance)} due` : undefined}
            status={row.status_label ?? row.status}
            statusTone={row.status === 'ACTIVE' ? expiryTone(row.days_remaining) : statusTone(row.status)}
          />
        )}
      />
    </Screen>
  )
}
