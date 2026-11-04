import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, formatTime } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { AuditEntry } from '../../src/lib/types'

const TONE: Record<string, string> = {
  created: theme.good,
  updated: theme.warn,
  deleted: theme.bad,
  login: theme.dim,
  logout: theme.dim,
}

/** Who changed what, and when. */
export default function Audit() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<AuditEntry>('/audit-logs', { perPage: 25 })

  if (!can(P.auditView)) {
    return (
      <Screen title="Audit log" onBack={() => router.back()}>
        <NotPermitted what="see the audit log" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Audit log"
      subtitle={`${list.total} entries`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <SearchBar value={list.term} onChange={list.setTerm} placeholder="User, record or event" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="Nothing logged"
        emptyDescription="No entries match."
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.description ?? `${row.event} ${row.record_type}`}
            subtitle={`${row.user_name ?? 'System'}${row.user_role ? ` · ${row.user_role}` : ''} · ${formatDate(
              row.created_at,
            )} ${formatTime(row.created_at)}`}
            trailing={row.record_id ? `#${row.record_id}` : undefined}
            status={row.event}
            statusTone={TONE[row.event] ?? theme.dim}
          />
        )}
      />

      <Muted>
        The log is written by the server and cannot be edited from any client, including this one. That is the
        point of it.
      </Muted>
    </Screen>
  )
}
