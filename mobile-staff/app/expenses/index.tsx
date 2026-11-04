import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import type { Expense } from '../../src/lib/types'

/** What the gym spends. */
export default function Expenses() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<Expense>('/expenses', { perPage: 20 })

  if (!can(P.expensesView)) {
    return (
      <Screen title="Expenses" onBack={() => router.back()}>
        <NotPermitted what="see expenses" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Expenses"
      subtitle={`${list.total} recorded`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      {can(P.expensesManage) ? (
        <Button label="Record an expense" onPress={() => router.push('/expenses/new')} />
      ) : null}

      <SearchBar
        value={list.term}
        onChange={list.setTerm}
        placeholder="Payee, reference or description"
      />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No expenses"
        emptyDescription="Nothing recorded that matches."
        onSelect={can(P.expensesManage) ? (row) => router.push(`/expenses/${row.id}`) : undefined}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.category?.name ?? 'Uncategorised'}
            subtitle={`${row.payee ?? row.description ?? row.reference ?? '—'} · ${formatDate(row.expense_date)}`}
            trailing={money(row.total ?? row.amount)}
          />
        )}
      />
    </Screen>
  )
}
