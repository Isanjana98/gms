import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../lib/api'
import { fieldErrors, saveError, useSaveResource } from '../lib/resource'
import type { MembershipPlan, MemberSummary, Paginated, Trainer } from '../lib/types'
import { money } from '../lib/format'
import { DateField, FormRow, FormSection, MoneyField, Outcome, SelectField, TextField } from './form'
import { Button, Muted } from './ui'

const GENDERS = [
  { label: 'Male', value: 'MALE' },
  { label: 'Female', value: 'FEMALE' },
  { label: 'Other', value: 'OTHER' },
]

/**
 * Register a member, or correct one.
 *
 * On a new registration the form can also sell the first membership, because
 * that is one conversation at the desk rather than two — the API takes both in
 * a single request for exactly that reason (SRS 32). On an edit those fields
 * are absent: changing somebody's phone number should not quietly sell them
 * anything.
 */
export function MemberForm({
  initial,
  onSaved,
}: {
  initial?: MemberSummary & Record<string, unknown>
  onSaved: (message: string, memberId?: number) => void
}) {
  const [fullName, setFullName] = useState(initial?.full_name ?? '')
  const [telephone, setTelephone] = useState(initial?.telephone ?? '')
  const [email, setEmail] = useState(initial?.email ?? '')
  const [nic, setNic] = useState((initial?.nic_passport as string) ?? '')
  const [dob, setDob] = useState((initial?.date_of_birth as string) ?? '')
  const [gender, setGender] = useState((initial?.gender as string) ?? '')
  const [address, setAddress] = useState((initial?.address as string) ?? '')
  const [city, setCity] = useState((initial?.city as string) ?? '')
  const [emergencyName, setEmergencyName] = useState((initial?.emergency_contact_name as string) ?? '')
  const [emergencyNumber, setEmergencyNumber] = useState((initial?.emergency_contact_number as string) ?? '')
  const [trainerId, setTrainerId] = useState<string | number | null>((initial?.trainer_id as number) ?? null)
  const [notes, setNotes] = useState((initial?.notes as string) ?? '')

  // New registrations only.
  const [planId, setPlanId] = useState<string | number | null>(null)
  const [discount, setDiscount] = useState('')

  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const trainers = useQuery({
    queryKey: ['/trainers'],
    queryFn: async () =>
      (await api.get<Paginated<Trainer>>('/trainers', { params: { per_page: 100 } })).data.data,
  })

  const plans = useQuery({
    queryKey: ['/membership-plans'],
    enabled: !initial,
    queryFn: async () =>
      (await api.get<Paginated<MembershipPlan>>('/membership-plans', { params: { per_page: 100 } })).data.data,
  })

  const save = useSaveResource('/members', {
    id: initial?.id,
    method: initial ? 'patch' : 'post',
    also: ['/memberships', '/invoices'],
  })
  const errors = fieldErrors(save.error)

  const submit = () => {
    setResult(null)

    save.mutate(
      {
        full_name: fullName.trim(),
        telephone: telephone.trim() || undefined,
        email: email.trim() || undefined,
        nic_passport: nic.trim() || undefined,
        date_of_birth: dob || undefined,
        gender: gender || undefined,
        address: address.trim() || undefined,
        city: city.trim() || undefined,
        emergency_contact_name: emergencyName.trim() || undefined,
        emergency_contact_number: emergencyNumber.trim() || undefined,
        trainer_id: trainerId ?? undefined,
        notes: notes.trim() || undefined,
        ...(initial
          ? {}
          : {
              membership_plan_id: planId ?? undefined,
              membership_discount: discount ? Number(discount) : undefined,
            }),
      },
      {
        onSuccess: (data: unknown) => {
          const member = (data as { data?: { id?: number; membership_number?: string } })?.data
          onSaved(
            initial
              ? 'Member updated.'
              : `Registered as ${member?.membership_number ?? 'a new member'}.`,
            member?.id,
          )
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <>
      <FormSection title="Who they are">
        <FormRow label="Full name" error={errors.full_name}>
          <TextField testID="full_name" value={fullName} onChange={setFullName} autoCapitalize="words" />
        </FormRow>
        <FormRow label="Telephone" error={errors.telephone}>
          <TextField
            testID="telephone"
            value={telephone}
            onChange={setTelephone}
            keyboardType="phone-pad"
            autoCapitalize="none"
          />
        </FormRow>
        <FormRow label="Email" error={errors.email}>
          <TextField value={email} onChange={setEmail} keyboardType="email-address" autoCapitalize="none" />
        </FormRow>
        <FormRow
          label="NIC or passport"
          hint="Not checked for uniqueness — families share documents. Duplicates are flagged, not blocked."
          error={errors.nic_passport}
        >
          <TextField value={nic} onChange={setNic} autoCapitalize="characters" />
        </FormRow>
        <FormRow label="Date of birth" error={errors.date_of_birth}>
          <DateField value={dob} onChange={setDob} />
        </FormRow>
        <FormRow label="Gender" error={errors.gender}>
          <SelectField value={gender} options={GENDERS} onChange={(v) => setGender(String(v))} placeholder="Not said" />
        </FormRow>
      </FormSection>

      <FormSection title="Where they are">
        <FormRow label="Address" error={errors.address}>
          <TextField value={address} onChange={setAddress} multiline />
        </FormRow>
        <FormRow label="City" error={errors.city}>
          <TextField value={city} onChange={setCity} autoCapitalize="words" />
        </FormRow>
      </FormSection>

      <FormSection title="If something goes wrong">
        <FormRow label="Contact name" error={errors.emergency_contact_name}>
          <TextField value={emergencyName} onChange={setEmergencyName} autoCapitalize="words" />
        </FormRow>
        <FormRow label="Contact number" error={errors.emergency_contact_number}>
          <TextField
            value={emergencyNumber}
            onChange={setEmergencyNumber}
            keyboardType="phone-pad"
            autoCapitalize="none"
          />
        </FormRow>
        <Muted>Worth asking for. It is the one field nobody misses until it matters.</Muted>
      </FormSection>

      <FormSection title="Training">
        <FormRow label="Assigned trainer" error={errors.trainer_id}>
          <SelectField
            value={trainerId}
            options={(trainers.data ?? []).map((t) => ({ label: t.full_name, value: t.id, hint: t.specialization ?? undefined }))}
            onChange={setTrainerId}
            placeholder="Nobody"
          />
        </FormRow>
        <FormRow label="Notes" error={errors.notes}>
          <TextField value={notes} onChange={setNotes} multiline placeholder="Injuries, preferences, anything worth knowing" />
        </FormRow>
      </FormSection>

      {!initial ? (
        <FormSection title="Sell them a membership now">
          <FormRow label="Plan" hint="Optional. Leave blank to register them and sell later." error={errors.membership_plan_id}>
            <SelectField
              testID="plan"
              value={planId}
              options={(plans.data ?? []).map((p) => ({
                label: p.name,
                value: p.id,
                hint: `${money(p.price)} · ${p.duration_label ?? ''}`,
              }))}
              onChange={setPlanId}
              placeholder={plans.isLoading ? 'Loading…' : 'Not yet'}
            />
          </FormRow>
          {planId ? (
            <FormRow label="Discount" error={errors.membership_discount}>
              <MoneyField value={discount} onChange={setDiscount} />
            </FormRow>
          ) : null}
        </FormSection>
      ) : null}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Button
        label={initial ? 'Save changes' : 'Register member'}
        onPress={submit}
        loading={save.isPending}
      />
    </>
  )
}
