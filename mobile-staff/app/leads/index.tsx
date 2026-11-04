import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Lead } from '../../src/lib/types'

const TONE: Record<string, string> = {
  NEW: theme.accentHot,
  CONTACTED: theme.warn,
  CONVERTED: theme.good,
  CLOSED: theme.dim,
}

/** People who asked about joining, newest first. */
export default function Leads() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Lead>('/leads', { perPage: 20 })

  if (!can(P.leadsView)) {
    return (
      <Screen title="Enquiries" onBack={() => router.back()}>
        <NotPermitted what="see enquiries" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Enquiries"
      subtitle={`${list.total} received`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Name, phone or email" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No enquiries"
        emptyDescription="Nothing has come in through the website yet."
        onSelect={(row) => router.push(`/leads/${row.id}`)}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.name}
            subtitle={`${row.telephone ?? row.email ?? 'No contact given'} · ${formatDate(row.created_at)}`}
            status={row.status}
            statusTone={TONE[row.status] ?? theme.dim}
          />
        )}
      />
    </Screen>
  )
}
