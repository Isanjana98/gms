import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { MemberPicker } from '../../src/components/MemberPicker'
import { Screen } from '../../src/components/Screen'
import {
  DateField,
  FormRow,
  FormSection,
  MoneyField,
  NotPermitted,
  Outcome,
  SelectField,
  SwitchField,
  TextField,
} from '../../src/components/form'
import { Button } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { money } from '../../src/lib/format'
import { fieldErrors, saveError, useSaveResource } from '../../src/lib/resource'
import type { MembershipPlan, MemberSummary, Paginated } from '../../src/lib/types'

/** Sell a membership. */
export default function NewMembership() {
  const { can } = useAuth()
  const router = useRouter()

  const [member, setMember] = useState<MemberSummary | null>(null)
  const [planId, setPlanId] = useState<string | number | null>(null)
  const [startDate, setStartDate] = useState('')
  const [discount, setDiscount] = useState('')
  const [registration, setRegistration] = useState('')
  const [notes, setNotes] = useState('')
  const [activate, setActivate] = useState(true)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const plans = useQuery({
    queryKey: ['/membership-plans'],
    queryFn: async () =>
      (await api.get<Paginated<MembershipPlan>>('/membership-plans', { params: { per_page: 100 } })).data.data,
  })

  const save = useSaveResource('/memberships', { also: ['/members', '/invoices'] })
  const errors = fieldErrors(save.error)

  if (!can(P.membershipsCreate)) {
    return (
      <Screen title="Sell a membership" onBack={() => router.back()}>
        <NotPermitted what="sell memberships" />
      </Screen>
    )
  }

  const submit = () => {
    setResult(null)

    if (!member) {
      setResult({ ok: false, message: 'Choose a member first.' })
      return
    }

    save.mutate(
      {
        member_id: member.id,
        membership_plan_id: planId,
        start_date: startDate || undefined,
        discount: discount ? Number(discount) : undefined,
        registration_fee: registration ? Number(registration) : undefined,
        notes: notes.trim() || undefined,
        activate,
      },
      {
        onSuccess: () => setResult({ ok: true, message: 'Membership created. Take payment next.' }),
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen title="Sell a membership" onBack={() => router.back()}>
      <FormSection title="Who">
        <FormRow label="Member" error={errors.member_id}>
          <MemberPicker value={member} onChange={setMember} />
        </FormRow>
      </FormSection>

      <FormSection title="What">
        <FormRow label="Plan" error={errors.membership_plan_id}>
          <SelectField
            testID="plan"
            value={planId}
            options={(plans.data ?? []).map((p) => ({
              label: p.name,
              value: p.id,
              hint: `${money(p.price)} · ${p.duration_label ?? ''}`,
            }))}
            onChange={setPlanId}
            placeholder={plans.isLoading ? 'Loading…' : 'Choose a plan'}
          />
        </FormRow>

        <FormRow label="Starts" hint="Blank starts today." error={errors.start_date}>
          <DateField value={startDate} onChange={setStartDate} />
        </FormRow>

        <FormRow label="Discount" error={errors.discount}>
          <MoneyField value={discount} onChange={setDiscount} />
        </FormRow>

        <FormRow
          label="Registration fee"
          hint="Blank uses the plan's own fee."
          error={errors.registration_fee}
        >
          <MoneyField value={registration} onChange={setRegistration} />
        </FormRow>

        <FormRow label="Notes" error={errors.notes}>
          <TextField value={notes} onChange={setNotes} placeholder="Optional" multiline />
        </FormRow>

        <SwitchField
          label="Activate straight away"
          hint="Off leaves it pending, which is what you want if they have not paid yet."
          value={activate}
          onChange={setActivate}
        />
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Button label="Create membership" onPress={submit} loading={save.isPending} />
      <Button label="Done" variant="ghost" onPress={() => router.back()} />
    </Screen>
  )
}
