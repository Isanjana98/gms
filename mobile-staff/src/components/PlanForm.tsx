import { useState } from 'react'
import { fieldErrors, saveError, useSaveResource } from '../lib/resource'
import type { MembershipPlan } from '../lib/types'
import {
  FormRow,
  FormSection,
  MoneyField,
  Outcome,
  SelectField,
  SwitchField,
  TextField,
} from './form'
import { Button } from './ui'

const UNITS = [
  { label: 'Days', value: 'DAY' },
  { label: 'Weeks', value: 'WEEK' },
  { label: 'Months', value: 'MONTH' },
  { label: 'Years', value: 'YEAR' },
]

/** Create or change a membership plan. */
export function PlanForm({
  initial,
  onSaved,
}: {
  initial?: MembershipPlan
  onSaved: (message: string) => void
}) {
  const [code, setCode] = useState(initial?.code ?? '')
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [durationValue, setDurationValue] = useState(String(initial?.duration_value ?? 1))
  const [durationUnit, setDurationUnit] = useState(initial?.duration_unit ?? 'MONTH')
  const [price, setPrice] = useState(initial ? String(initial.price) : '')
  const [discount, setDiscount] = useState(initial?.default_discount ? String(initial.default_discount) : '')
  const [registration, setRegistration] = useState(
    initial?.registration_fee ? String(initial.registration_fee) : '',
  )
  const [graceDays, setGraceDays] = useState(String(initial?.grace_days ?? 0))
  const [active, setActive] = useState(initial?.is_active ?? true)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const save = useSaveResource('/membership-plans', { id: initial?.id })
  const errors = fieldErrors(save.error)

  const submit = () => {
    setResult(null)

    save.mutate(
      {
        code: code.trim().toUpperCase(),
        name: name.trim(),
        description: description.trim() || undefined,
        duration_value: Number(durationValue),
        duration_unit: durationUnit,
        price: Number(price),
        default_discount: discount ? Number(discount) : undefined,
        registration_fee: registration ? Number(registration) : undefined,
        grace_days: graceDays ? Number(graceDays) : undefined,
        is_active: active,
      },
      {
        onSuccess: () => onSaved(initial ? 'Plan updated.' : 'Plan added.'),
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <>
      <FormSection title="The plan">
        <FormRow label="Name" error={errors.name}>
          <TextField testID="name" value={name} onChange={setName} placeholder="Monthly" autoCapitalize="words" />
        </FormRow>

        <FormRow
          label="Code"
          hint="Short, unique, and used on receipts. Changing it later does not rewrite old ones."
          error={errors.code}
        >
          <TextField testID="code" value={code} onChange={setCode} placeholder="MONTHLY" autoCapitalize="none" />
        </FormRow>

        <FormRow label="Description" error={errors.description}>
          <TextField value={description} onChange={setDescription} placeholder="Optional" multiline />
        </FormRow>
      </FormSection>

      <FormSection title="How long it lasts">
        <FormRow label="Length" error={errors.duration_value}>
          <TextField value={durationValue} onChange={setDurationValue} keyboardType="numeric" />
        </FormRow>

        <FormRow label="Unit" error={errors.duration_unit}>
          <SelectField value={durationUnit} options={UNITS} onChange={(v) => setDurationUnit(String(v))} />
        </FormRow>

        <FormRow
          label="Grace days"
          hint="Days after expiry that entry is still allowed."
          error={errors.grace_days}
        >
          <TextField value={graceDays} onChange={setGraceDays} keyboardType="numeric" />
        </FormRow>
      </FormSection>

      <FormSection title="What it costs">
        <FormRow label="Price" error={errors.price}>
          <MoneyField testID="price" value={price} onChange={setPrice} />
        </FormRow>

        <FormRow
          label="Usual discount"
          hint="Offered by default when selling this plan. Cannot exceed the price."
          error={errors.default_discount}
        >
          <MoneyField value={discount} onChange={setDiscount} />
        </FormRow>

        <FormRow label="Registration fee" error={errors.registration_fee}>
          <MoneyField value={registration} onChange={setRegistration} />
        </FormRow>

        <SwitchField
          label="Available to sell"
          hint="Turning this off hides the plan from new memberships. Existing ones continue."
          value={active}
          onChange={setActive}
        />
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Button label={initial ? 'Save changes' : 'Add plan'} onPress={submit} loading={save.isPending} />
    </>
  )
}
