import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { statusTone } from '../../src/lib/theme'
import type { Trainer } from '../../src/lib/types'

/** Staff who take sessions. */
export default function Trainers() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Trainer>('/trainers', { perPage: 30 })

  if (!can(P.trainersView)) {
    return (
      <Screen title="Trainers" onBack={() => router.back()}>
        <NotPermitted what="see trainers" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Trainers"
      subtitle={`${list.total} on the books`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      {can(P.trainersManage) ? (
        <Button label="Add a trainer" onPress={() => router.push('/trainers/new')} />
      ) : null}

      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Name, code or phone" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No trainers"
        emptyDescription="Nobody has been added yet."
        onSelect={can(P.trainersManage) ? (row) => router.push(`/trainers/${row.id}`) : undefined}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.full_name}
            subtitle={`${row.specialization ?? row.trainer_code} · ${row.members_count ?? 0} members`}
            trailing={row.telephone ?? undefined}
            status={row.status}
            statusTone={statusTone(row.status)}
          />
        )}
      />
    </Screen>
  )
}
