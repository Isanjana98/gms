import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { statusTone } from '../../src/lib/theme'
import type { WorkoutPlan } from '../../src/lib/types'

/** Programmes assigned to members. */
export default function Workouts() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<WorkoutPlan>('/workout-plans', { perPage: 25 })

  if (!can(P.workoutsView)) {
    return (
      <Screen title="Workout plans" onBack={() => router.back()}>
        <NotPermitted what="see workout plans" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Workout plans"
      subtitle={`${list.total} written`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Member or plan title" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No workout plans"
        emptyDescription="None have been written yet."
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.title}
            subtitle={`${row.member?.full_name ?? 'Unassigned'}${
              row.trainer ? ` · ${row.trainer.full_name}` : ''
            }${row.start_date ? ` · from ${formatDate(row.start_date)}` : ''}`}
            trailing={row.exercises_count ? `${row.exercises_count} exercises` : undefined}
            status={row.status}
            statusTone={statusTone(row.status)}
          />
        )}
      />

      <Muted>
        Writing a programme means naming exercises, sets and reps across seven days — a table, and a job for
        the admin panel. This is here so a trainer on the floor can check what somebody is meant to be doing.
      </Muted>
    </Screen>
  )
}
