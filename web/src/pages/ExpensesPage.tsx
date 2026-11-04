import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  FileButton,
  FilterBar,
  Input,
  LoadingBlock,
  Modal,
  PageHeader,
  Select,
  Table,
  Td,
  Textarea,
  Th,
} from '../components/ui'
import { useConfirm } from '../components/Confirm'
import { Pager, RecordCard, RecordList } from '../components/RecordList'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, isoDaysAgo, money, todayIso } from '../lib/format'
import type { Expense, ExpenseCategory, Paginated } from '../lib/types'

type ExpensePage = Paginated<Expense> & { summary?: { entries: number; total: number } }

/**
 * What the gym spends.
 *
 * The counterpart to Payments, and laid out the same way on purpose: the same
 * date filters, the same running total in the filter bar, the same card layout
 * on a phone. Somebody who can use one screen can use the other.
 */
export function ExpensesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const [from, setFrom] = useState(isoDaysAgo(29))
  const [to, setTo] = useState(todayIso())
  const [categoryId, setCategoryId] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [editing, setEditing] = useState<Expense | null>(null)
  const [creating, setCreating] = useState(false)
  const [managingCategories, setManagingCategories] = useState(false)

  const canManage = can(P.expensesManage)

  const categories = useQuery({
    queryKey: ['expense-categories'],
    queryFn: async () => (await api.get<{ data: ExpenseCategory[] }>('/expense-categories')).data.data,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['expenses', from, to, categoryId, search, page],
    queryFn: async () =>
      (
        await api.get<ExpensePage>('/expenses', {
          params: {
            from,
            to,
            expense_category_id: categoryId || undefined,
            search: search || undefined,
            page,
            per_page: 25,
          },
        })
      ).data,
  })

  const remove = useMutation({
    mutationFn: async (expense: Expense) => (await api.delete(`/expenses/${expense.id}`)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      queryClient.invalidateQueries({ queryKey: ['expenses'] })
    },
    onError: (err) => toast.error('Could not delete the expense', errorMessage(err)),
  })

  const askDelete = async (expense: Expense) => {
    const ok = await confirm({
      title: 'Delete this expense?',
      tone: 'danger',
      confirmLabel: 'Delete expense',
      message: (
        <>
          <strong>{money(expense.total)}</strong> recorded on {date(expense.expense_date)} under{' '}
          {expense.category?.name ?? 'an unknown category'} will be removed.
        </>
      ),
      detail: <>It will no longer appear in the expense or profit reports for that period.</>,
    })

    if (ok) remove.mutate(expense)
  }

  const reset = () => setPage(1)
  const meta = data?.meta ?? data
  const summary = data?.summary

  return (
    <>
      <PageHeader
        title="Expenses"
        subtitle="Rent, salaries, equipment and everything else the gym pays for"
        actions={
          <div className="flex flex-wrap gap-2">
            {can(P.expenseCategoriesManage) && (
              <Button variant="secondary" onClick={() => setManagingCategories(true)}>
                Categories
              </Button>
            )}
            <FileButton
              url={`/reports/export/expenses?from=${from}&to=${to}${categoryId ? `&expense_category_id=${categoryId}` : ''}`}
              filename={`expenses-${from}-to-${to}.csv`}
              mode="download"
            >
              Export CSV
            </FileButton>
            {canManage && <Button onClick={() => setCreating(true)}>Record expense</Button>}
          </div>
        }
      />

      <Card padded={false}>
        <FilterBar>
          <label className="text-xs font-medium text-ink-600">
            From
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); reset() }} className="mt-1" />
          </label>
          <label className="text-xs font-medium text-ink-600">
            To
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); reset() }} className="mt-1" />
          </label>
          <label className="text-xs font-medium text-ink-600">
            Category
            <Select
              value={categoryId}
              onChange={(e) => { setCategoryId(e.target.value); reset() }}
              className="mt-1"
            >
              <option value="">All categories</option>
              {(categories.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </label>
          <label className="text-xs font-medium text-ink-600">
            Search
            <Input
              value={search}
              onChange={(e) => { setSearch(e.target.value); reset() }}
              placeholder="Payee or reference"
              className="mt-1"
            />
          </label>

          {/*
            The total for everything matching the filters, not for this page.
            "What did we spend on equipment this quarter" is the question being
            asked, and one page of 25 cannot answer it.
          */}
          <div className="ml-auto pb-1 text-right">
            <p className="text-[11px] uppercase tracking-wide text-ink-500">
              {summary ? `${summary.entries.toLocaleString()} entries` : 'Total'}
            </p>
            <p className="text-sm font-semibold tabular">{money(summary?.total ?? 0)}</p>
          </div>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState
            title="No expenses in this range"
            description="Record what the gym pays out to see it against what it takes in."
            action={canManage && <Button onClick={() => setCreating(true)}>Record expense</Button>}
          />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(expense) => expense.id}
              card={(expense) => (
                <RecordCard
                  title={expense.category?.name ?? '—'}
                  subtitle={expense.payee ?? expense.reference}
                  badge={<span className="font-semibold text-ink-900">{money(expense.total)}</span>}
                  facts={[
                    { label: 'Date', value: date(expense.expense_date) },
                    { label: 'Method', value: expense.method_label },
                    { label: 'Reference', value: expense.reference },
                    { label: 'Supplier ref', value: expense.supplier_reference ?? '—' },
                  ]}
                  note={expense.description}
                  actions={
                    canManage && (
                      <>
                        <Button size="sm" variant="ghost" onClick={() => setEditing(expense)}>
                          Edit
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => askDelete(expense)}>
                          Delete
                        </Button>
                      </>
                    )
                  }
                />
              )}
              table={
                <Table>
                  <thead>
                    <tr>
                      <Th>Date</Th>
                      <Th>Reference</Th>
                      <Th>Category</Th>
                      <Th>Payee</Th>
                      <Th>Method</Th>
                      <Th align="right">Amount</Th>
                      <Th align="right">Actions</Th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink-100">
                    {data.data.map((expense) => (
                      <tr key={expense.id} className="hover:bg-ink-50">
                        <Td>{date(expense.expense_date)}</Td>
                        <Td>
                          <span className="font-medium">{expense.reference}</span>
                          {expense.supplier_reference && (
                            <span className="block text-xs text-ink-500">{expense.supplier_reference}</span>
                          )}
                        </Td>
                        <Td>
                          <span className="text-sm">{expense.category?.name ?? '—'}</span>
                          {expense.category?.is_recurring && (
                            <span className="ml-1.5">
                              <Badge>recurring</Badge>
                            </span>
                          )}
                        </Td>
                        <Td>
                          <span className="text-sm">{expense.payee ?? '—'}</span>
                          {expense.description && (
                            <span className="block max-w-xs truncate text-xs text-ink-500">{expense.description}</span>
                          )}
                        </Td>
                        <Td>{expense.method_label}</Td>
                        <Td align="right">
                          <span className="font-medium">{money(expense.total)}</span>
                          {expense.tax_amount > 0 && (
                            <span className="block text-xs text-ink-500">incl. {money(expense.tax_amount)} tax</span>
                          )}
                        </Td>
                        <Td align="right">
                          {canManage && (
                            <div className="flex justify-end gap-1.5">
                              <Button size="sm" variant="ghost" onClick={() => setEditing(expense)}>
                                Edit
                              </Button>
                              <Button size="sm" variant="ghost" onClick={() => askDelete(expense)}>
                                Delete
                              </Button>
                            </div>
                          )}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              }
            />

            <Pager page={meta?.current_page ?? page} lastPage={meta?.last_page ?? 1} onChange={setPage} />
          </>
        )}
      </Card>

      {(creating || editing) && (
        <ExpenseModal
          expense={editing}
          categories={categories.data ?? []}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message ?? 'Expense saved.')
            setCreating(false)
            setEditing(null)
            queryClient.invalidateQueries({ queryKey: ['expenses'] })
          }}
        />
      )}

      {managingCategories && <CategoriesModal onClose={() => setManagingCategories(false)} />}
    </>
  )
}

function ExpenseModal({
  expense,
  categories,
  onClose,
  onSaved,
}: {
  expense: Expense | null
  categories: ExpenseCategory[]
  onClose: () => void
  onSaved: (message?: string) => void
}) {
  const [form, setForm] = useState({
    expense_category_id: String(expense?.expense_category_id ?? categories[0]?.id ?? ''),
    expense_date: expense?.expense_date ?? todayIso(),
    amount: String(expense?.amount ?? ''),
    tax_amount: String(expense?.tax_amount ?? 0),
    method: expense?.method ?? 'CASH',
    payee: expense?.payee ?? '',
    supplier_reference: expense?.supplier_reference ?? '',
    description: expense?.description ?? '',
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        expense_category_id: Number(form.expense_category_id),
        amount: Number(form.amount),
        tax_amount: Number(form.tax_amount || 0),
      }

      return expense
        ? (await api.put(`/expenses/${expense.id}`, payload)).data
        : (await api.post('/expenses', payload)).data
    },
    onSuccess: (result: { message?: string }) => onSaved(result?.message),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = (key: string) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const submit = (event: FormEvent) => {
    event.preventDefault()
    save.mutate()
  }

  const total = Number(form.amount || 0) + Number(form.tax_amount || 0)

  return (
    <Modal
      open
      onClose={onClose}
      title={expense ? `Edit ${expense.reference}` : 'Record an expense'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="expense-form" type="submit" loading={save.isPending}>
            {expense ? 'Save changes' : 'Record expense'}
          </Button>
        </>
      }
    >
      <form id="expense-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {categories.length === 0 && (
          <Alert tone="warning">
            There are no expense categories yet. Add one before recording a cost.
          </Alert>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Category" required error={errors.expense_category_id}>
            <Select value={form.expense_category_id} onChange={set('expense_category_id')} required>
              <option value="">Choose a category</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Date" required error={errors.expense_date} hint="When the money was spent.">
            <Input type="date" max={todayIso()} value={form.expense_date} onChange={set('expense_date')} required />
          </Field>

          <Field label="Amount" required error={errors.amount}>
            <Input type="number" step="0.01" min="0.01" value={form.amount} onChange={set('amount')} required />
          </Field>

          <Field label="Tax" error={errors.tax_amount} hint="Leave at zero if the amount already includes it.">
            <Input type="number" step="0.01" min="0" value={form.tax_amount} onChange={set('tax_amount')} />
          </Field>

          <Field label="Paid by" required error={errors.method}>
            <Select value={form.method} onChange={set('method')}>
              <option value="CASH">Cash</option>
              <option value="CARD">Card</option>
              <option value="BANK_TRANSFER">Bank transfer</option>
              <option value="ONLINE">Online</option>
            </Select>
          </Field>

          <Field label="Paid to" error={errors.payee} hint="The supplier, landlord or provider.">
            <Input value={form.payee} onChange={set('payee')} placeholder="Who was paid" />
          </Field>
        </div>

        <Field
          label="Supplier reference"
          error={errors.supplier_reference}
          hint="Their invoice or receipt number, for reconciliation."
        >
          <Input value={form.supplier_reference} onChange={set('supplier_reference')} />
        </Field>

        <Field label="Description" error={errors.description}>
          <Textarea value={form.description} onChange={set('description')} />
        </Field>

        {total > 0 && (
          <div className="flex items-center justify-between rounded-lg bg-ink-50 p-3 text-sm">
            <span className="text-ink-500">Total recorded</span>
            <span className="tabular font-semibold">{money(total)}</span>
          </div>
        )}
      </form>
    </Modal>
  )
}

/**
 * The chart of accounts.
 *
 * A modal rather than its own page: it is set up once and then rarely touched,
 * and a sidebar entry for it would sit there permanently for the sake of a
 * screen used twice a year.
 */
function CategoriesModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const [editing, setEditing] = useState<ExpenseCategory | null>(null)
  const [form, setForm] = useState({ code: '', name: '', is_recurring: false })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['expense-categories-all'],
    queryFn: async () =>
      (await api.get<{ data: ExpenseCategory[] }>('/expense-categories?include_inactive=true')).data.data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['expense-categories'] })
    queryClient.invalidateQueries({ queryKey: ['expense-categories-all'] })
  }

  const save = useMutation({
    mutationFn: async () =>
      editing
        ? (await api.put(`/expense-categories/${editing.id}`, form)).data
        : (await api.post('/expense-categories', form)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      setForm({ code: '', name: '', is_recurring: false })
      setEditing(null)
      setError(null)
      refresh()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const toggleActive = useMutation({
    mutationFn: async (category: ExpenseCategory) =>
      (
        await api.put(`/expense-categories/${category.id}`, {
          code: category.code,
          name: category.name,
          is_recurring: category.is_recurring,
          is_active: !category.is_active,
        })
      ).data,
    onSuccess: () => refresh(),
    onError: (err) => toast.error('Could not update the category', errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: async (category: ExpenseCategory) => (await api.delete(`/expense-categories/${category.id}`)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      refresh()
    },
    onError: (err) => toast.error('Could not delete the category', errorMessage(err)),
  })

  const askDelete = async (category: ExpenseCategory) => {
    const ok = await confirm({
      title: `Delete "${category.name}"?`,
      tone: 'danger',
      confirmLabel: 'Delete category',
      message: <>The category will be removed from the list of things an expense can be filed under.</>,
    })

    if (ok) remove.mutate(category)
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Expense categories"
      footer={
        <Button variant="secondary" onClick={onClose}>
          Done
        </Button>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <p className="text-sm text-ink-600">
          Your own chart of accounts. Mark the ones that recur every month — rent, salaries — and the profit report can
          separate what the gym costs to keep open from what it spent once.
        </p>

        <form
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="grid gap-3 rounded-lg bg-ink-50 p-3 sm:grid-cols-[1fr_1fr_auto]"
        >
          <Field label="Name" required>
            <Input
              value={form.name}
              onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
              placeholder="Pool chemicals"
              required
            />
          </Field>
          <Field label="Code" required>
            <Input
              value={form.code}
              onChange={(e) => setForm((prev) => ({ ...prev, code: e.target.value.toUpperCase() }))}
              placeholder="POOL"
              required
            />
          </Field>
          <div className="flex items-end">
            <Button type="submit" loading={save.isPending}>
              {editing ? 'Save' : 'Add'}
            </Button>
          </div>

          <label className="flex items-center gap-2 text-sm text-ink-700 sm:col-span-3">
            <input
              type="checkbox"
              checked={form.is_recurring}
              onChange={(e) => setForm((prev) => ({ ...prev, is_recurring: e.target.checked }))}
              className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
            />
            Recurs every month
          </label>
        </form>

        {isLoading ? (
          <LoadingBlock />
        ) : (
          <ul className="divide-y divide-ink-100 rounded-lg ring-1 ring-ink-200">
            {(data ?? []).map((category) => (
              <li key={category.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                <div className="min-w-0 flex-1">
                  <span className={category.is_active ? 'text-sm font-medium' : 'text-sm font-medium text-ink-400'}>
                    {category.name}
                  </span>
                  <span className="ml-1.5 text-xs text-ink-500">{category.code}</span>
                  {category.is_recurring && (
                    <span className="ml-1.5">
                      <Badge>monthly</Badge>
                    </span>
                  )}
                  {!category.is_active && (
                    <span className="ml-1.5">
                      <Badge>inactive</Badge>
                    </span>
                  )}
                  <span className="block text-xs text-ink-500">
                    {category.expenses_count ?? 0} expense{category.expenses_count === 1 ? '' : 's'}
                  </span>
                </div>

                <div className="flex gap-1">
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setEditing(category)
                      setForm({ code: category.code, name: category.name, is_recurring: category.is_recurring })
                    }}
                  >
                    Edit
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => toggleActive.mutate(category)}>
                    {category.is_active ? 'Deactivate' : 'Activate'}
                  </Button>
                  {/* Only offered when nothing is filed under it — the server
                      refuses otherwise, and a button that always fails is
                      worse than no button. */}
                  {(category.expenses_count ?? 0) === 0 && (
                    <Button size="sm" variant="ghost" onClick={() => askDelete(category)}>
                      Delete
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
