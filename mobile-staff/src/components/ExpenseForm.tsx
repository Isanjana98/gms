import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { View } from 'react-native'
import { api } from '../lib/api'
import { fieldErrors, saveError, useSaveResource } from '../lib/resource'
import type { Expense, ExpenseCategory, Paginated } from '../lib/types'
import {
  DateField,
  FormRow,
  FormSection,
  MoneyField,
  Outcome,
  SelectField,
  TextField,
} from './form'
import { Button } from './ui'

const METHODS = [
  { label: 'Cash', value: 'CASH' },
  { label: 'Card', value: 'CARD' },
  { label: 'Bank transfer', value: 'BANK_TRANSFER' },
  { label: 'Online', value: 'ONLINE' },
]

/**
 * Record or correct an expense.
 *
 * Shared by the create and the edit screen because the two differ only in
 * where the values start and which verb the button uses — and an edit form
 * that has drifted from the create form is how a field ends up saveable in one
 * place and not the other.
 */
export function ExpenseForm({
  initial,
  onSaved,
}: {
  initial?: Expense
  onSaved: (message: string) => void
}) {
  const [categoryId, setCategoryId] = useState<string | number | null>(
    initial?.expense_category_id ?? null,
  )
  const [amount, setAmount] = useState(initial ? String(initial.amount) : '')
  const [tax, setTax] = useState(initial?.tax_amount ? String(initial.tax_amount) : '')
  const [method, setMethod] = useState(initial?.method ?? 'CASH')
  const [spentOn, setSpentOn] = useState(initial?.expense_date ?? new Date().toISOString().slice(0, 10))
  const [payee, setPayee] = useState(initial?.payee ?? '')
  const [supplierRef, setSupplierRef] = useState(initial?.supplier_reference ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const categories = useQuery({
    queryKey: ['/expense-categories'],
    queryFn: async () =>
      (await api.get<Paginated<ExpenseCategory> | ExpenseCategory[]>('/expense-categories')).data,
  })

  const rows = Array.isArray(categories.data) ? categories.data : (categories.data?.data ?? [])
  const options = rows
    .filter((c) => c.is_active !== false)
    .map((c) => ({ label: c.name, value: c.id, hint: c.code }))

  const save = useSaveResource('/expenses', { id: initial?.id, also: ['/reports'] })
  const errors = fieldErrors(save.error)

  const submit = () => {
    setResult(null)

    save.mutate(
      {
        expense_category_id: categoryId,
        expense_date: spentOn,
        amount: Number(amount),
        tax_amount: tax ? Number(tax) : undefined,
        method,
        payee: payee.trim() || undefined,
        supplier_reference: supplierRef.trim() || undefined,
        description: description.trim() || undefined,
      },
      {
        onSuccess: () => onSaved(initial ? 'Expense updated.' : 'Expense recorded.'),
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <>
      <FormSection title="What was spent">
        <FormRow label="Category" error={errors.expense_category_id}>
          <SelectField
            testID="category"
            value={categoryId}
            options={options}
            onChange={setCategoryId}
            placeholder={categories.isLoading ? 'Loading…' : 'Choose a category'}
          />
        </FormRow>

        <FormRow label="Amount" error={errors.amount}>
          <MoneyField testID="amount" value={amount} onChange={setAmount} />
        </FormRow>

        <FormRow label="Tax" hint="Included in the total. Leave blank if none." error={errors.tax_amount}>
          <MoneyField value={tax} onChange={setTax} />
        </FormRow>

        <FormRow
          label="Spent on"
          hint="An expense is something that has happened, so not a future date."
          error={errors.expense_date}
        >
          <DateField value={spentOn} onChange={setSpentOn} />
        </FormRow>

        <FormRow label="Method" error={errors.method}>
          <SelectField value={method} options={METHODS} onChange={(v) => setMethod(String(v))} />
        </FormRow>
      </FormSection>

      <FormSection title="Who and why">
        <FormRow label="Paid to" error={errors.payee}>
          <TextField value={payee} onChange={setPayee} placeholder="Supplier or person" autoCapitalize="words" />
        </FormRow>

        <FormRow label="Their reference" error={errors.supplier_reference}>
          <TextField
            value={supplierRef}
            onChange={setSupplierRef}
            placeholder="Invoice or bill number"
            autoCapitalize="none"
          />
        </FormRow>

        <FormRow label="Description" error={errors.description}>
          <TextField value={description} onChange={setDescription} placeholder="What it was for" multiline />
        </FormRow>
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <View style={{ gap: 10 }}>
        <Button
          label={initial ? 'Save changes' : 'Record expense'}
          onPress={submit}
          loading={save.isPending}
        />
      </View>
    </>
  )
}
