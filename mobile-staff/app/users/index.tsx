import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Button, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { StaffAccount } from '../../src/lib/types'

/** Who can sign in, and as what. */
export default function Users() {
  const { can, user } = useAuth()
  const router = useRouter()

  const list = useSearchableList<StaffAccount>('/users', { perPage: 30 })

  if (!can(P.usersManage)) {
    return (
      <Screen title="Staff users" onBack={() => router.back()}>
        <NotPermitted what="manage staff accounts" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Staff users"
      subtitle={`${list.total} accounts`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <Button label="Create an account" onPress={() => router.push('/users/new')} />

      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Name or email" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No accounts"
        emptyDescription="Nobody matches that."
        onSelect={(row) => router.push(`/users/${row.id}`)}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.id === user?.id ? `${row.name} (you)` : row.name}
            subtitle={`${row.roles[0] ?? 'No role'} · ${row.branch?.name ?? 'All branches'}`}
            trailing={row.last_login_at ? formatDate(row.last_login_at) : 'Never signed in'}
            status={row.is_active ? undefined : 'blocked'}
            statusTone={theme.bad}
          />
        )}
      />

      <Muted>
        An account holds permissions, not a person. Blocking one stops the sessions it already has, so it takes
        effect at the door rather than at the next sign-in.
      </Muted>
    </Screen>
  )
}
