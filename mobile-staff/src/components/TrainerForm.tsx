import { useState } from 'react'
import { fieldErrors, saveError, useSaveResource } from '../lib/resource'
import type { Trainer } from '../lib/types'
import { DateField, FormRow, FormSection, Outcome, SelectField, TextField } from './form'
import { Button } from './ui'

const STATUSES = [
  { label: 'Active', value: 'ACTIVE' },
  { label: 'Inactive', value: 'INACTIVE' },
]

/** Add or change a trainer. */
export function TrainerForm({
  initial,
  onSaved,
}: {
  initial?: Trainer
  onSaved: (message: string) => void
}) {
  const [fullName, setFullName] = useState(initial?.full_name ?? '')
  const [telephone, setTelephone] = useState(initial?.telephone ?? '')
  const [email, setEmail] = useState(initial?.email ?? '')
  const [specialization, setSpecialization] = useState(initial?.specialization ?? '')
  const [joiningDate, setJoiningDate] = useState(initial?.joining_date ?? '')
  const [status, setStatus] = useState(initial?.status ?? 'ACTIVE')
  const [notes, setNotes] = useState(initial?.notes ?? '')
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const save = useSaveResource('/trainers', { id: initial?.id })
  const errors = fieldErrors(save.error)

  const submit = () => {
    setResult(null)
    save.mutate(
      {
        full_name: fullName.trim(),
        telephone: telephone.trim() || undefined,
        email: email.trim() || undefined,
        specialization: specialization.trim() || undefined,
        joining_date: joiningDate || undefined,
        status,
        notes: notes.trim() || undefined,
      },
      {
        onSuccess: () => onSaved(initial ? 'Trainer updated.' : 'Trainer added.'),
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <>
      <FormSection title="Who they are">
        <FormRow label="Name" error={errors.full_name}>
          <TextField testID="full_name" value={fullName} onChange={setFullName} autoCapitalize="words" />
        </FormRow>
        <FormRow label="Telephone" error={errors.telephone}>
          <TextField value={telephone} onChange={setTelephone} keyboardType="phone-pad" autoCapitalize="none" />
        </FormRow>
        <FormRow label="Email" error={errors.email}>
          <TextField value={email} onChange={setEmail} keyboardType="email-address" autoCapitalize="none" />
        </FormRow>
      </FormSection>

      <FormSection title="What they do">
        <FormRow label="Specialisation" error={errors.specialization}>
          <TextField
            value={specialization}
            onChange={setSpecialization}
            placeholder="Strength, cardio, rehabilitation…"
          />
        </FormRow>
        <FormRow label="Joined" error={errors.joining_date}>
          <DateField value={joiningDate} onChange={setJoiningDate} />
        </FormRow>
        <FormRow label="Status" error={errors.status}>
          <SelectField value={status} options={STATUSES} onChange={(v) => setStatus(String(v))} />
        </FormRow>
        <FormRow label="Notes" error={errors.notes}>
          <TextField value={notes} onChange={setNotes} placeholder="Optional" multiline />
        </FormRow>
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Button label={initial ? 'Save changes' : 'Add trainer'} onPress={submit} loading={save.isPending} />
    </>
  )
}
