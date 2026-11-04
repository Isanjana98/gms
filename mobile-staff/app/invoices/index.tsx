import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Invoice } from '../../src/lib/types'

/** What has been billed, and what is still owed on it. */
export default function Invoices() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Invoice>('/invoices', { perPage: 20 })

  if (!can(P.invoicesView)) {
    return (
      <Screen title="Invoices" onBack={() => router.back()}>
        <NotPermitted what="see invoices" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Invoices"
      subtitle={`${list.total} issued`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Invoice number or member" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No invoices"
        emptyDescription="Nothing billed that matches."
        onSelect={(row) => router.push(`/invoices/${row.id}`)}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.member?.full_name ?? 'Unknown member'}
            subtitle={`${row.invoice_number} · ${formatDate(row.issue_date)}`}
            trailing={row.balance > 0 ? `${money(row.balance)} due` : money(row.total)}
            status={row.status}
            statusTone={row.balance > 0 ? theme.warn : theme.good}
          />
        )}
      />
    </Screen>
  )
}
