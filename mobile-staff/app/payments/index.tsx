import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { Button } from '../../src/components/ui'
import { NotPermitted } from '../../src/components/form'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Payment } from '../../src/lib/types'

/** Every payment taken, newest first. */
export default function Payments() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Payment>('/payments', { perPage: 20 })

  if (!can(P.paymentsView)) {
    return (
      <Screen title="Payments" onBack={() => router.back()}>
        <NotPermitted what="see payments" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Payments"
      subtitle={`${list.total} recorded`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      {can(P.paymentsCreate) ? (
        <Button label="Take a payment" onPress={() => router.push('/payments/new')} />
      ) : null}

      <SearchBar
        value={list.term}
        onChange={list.setTerm}
        placeholder="Receipt number, member or reference"
      />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No payments"
        emptyDescription="Nothing has been taken that matches."
        onSelect={(row) => router.push(`/payments/${row.id}`)}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.member?.full_name ?? 'Unknown member'}
            subtitle={`${row.receipt_number} · ${row.method_label ?? row.method} · ${formatDate(row.paid_at)}`}
            trailing={`${row.is_refund ? '−' : ''}${money(row.amount)}`}
            status={row.status}
            statusTone={row.is_refund ? theme.warn : row.status === 'COMPLETED' ? theme.good : theme.dim}
          />
        )}
      />
    </Screen>
  )
}
