import { useRouter } from 'expo-router'
import { useState } from 'react'
import { View } from 'react-native'
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
  TextField,
} from '../../src/components/form'
import { Body, Button, Card, Muted } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { money } from '../../src/lib/format'
import { fieldErrors, saveError, useSaveResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { MemberSummary } from '../../src/lib/types'

const METHODS = [
  { label: 'Cash', value: 'CASH' },
  { label: 'Card', value: 'CARD' },
  { label: 'Bank transfer', value: 'BANK_TRANSFER' },
  { label: 'Online', value: 'ONLINE' },
]

const TYPES = [
  { label: 'Membership', value: 'MEMBERSHIP' },
  { label: 'Renewal', value: 'RENEWAL' },
  { label: 'Registration fee', value: 'REGISTRATION_FEE' },
  { label: 'Personal training', value: 'PERSONAL_TRAINING' },
  { label: 'Other', value: 'OTHER' },
]

/**
 * Take a payment at the desk.
 *
 * The member is chosen first and their outstanding balance is shown straight
 * away, because the question being answered at the counter is almost always
 * "how much do I owe?" — and an amount typed before anyone has looked at the
 * balance is the one that gets typed wrong.
 */
export default function NewPayment() {
  const { can } = useAuth()
  const router = useRouter()

  const [member, setMember] = useState<MemberSummary | null>(null)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<string>('CASH')
  const [type, setType] = useState<string>('MEMBERSHIP')
  const [reference, setReference] = useState('')
  const [paidAt, setPaidAt] = useState(new Date().toISOString().slice(0, 10))
  const [notes, setNotes] = useState('')
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const save = useSaveResource('/payments', { also: ['/invoices', '/memberships', '/members'] })
  const errors = fieldErrors(save.error)

  if (!can(P.paymentsCreate)) {
    return (
      <Screen title="Take a payment" onBack={() => router.back()}>
        <NotPermitted what="take payments" />
      </Screen>
    )
  }

  const balance = member?.current_membership?.balance ?? 0

  const submit = () => {
    setResult(null)

    if (!member) {
      setResult({ ok: false, message: 'Choose a member first.' })
      return
    }

    save.mutate(
      {
        member_id: member.id,
        amount: Number(amount),
        method,
        payment_type: type,
        reference: reference.trim() || undefined,
        paid_at: paidAt || undefined,
        notes: notes.trim() || undefined,
      },
      {
        onSuccess: (data: unknown) => {
          const receipt = (data as { data?: { receipt_number?: string } })?.data?.receipt_number
          setResult({ ok: true, message: receipt ? `Recorded as ${receipt}.` : 'Payment recorded.' })
          setAmount('')
          setReference('')
          setNotes('')
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen title="Take a payment" subtitle="Recorded against the member's account" onBack={() => router.back()}>
      <FormSection title="Who">
        <FormRow label="Member" error={errors.member_id}>
          <MemberPicker value={member} onChange={setMember} />
        </FormRow>

        {member ? (
          balance > 0 ? (
            <Card style={{ backgroundColor: 'rgba(245,158,11,0.10)', borderColor: 'rgba(245,158,11,0.30)' }}>
              <Body style={{ color: theme.warn }}>{money(balance)} outstanding</Body>
              <Muted>{member.current_membership?.plan_name}</Muted>
              <Button
                label={`Pay the full ${money(balance)}`}
                variant="ghost"
                onPress={() => setAmount(String(balance))}
                style={{ marginTop: 12 }}
              />
            </Card>
          ) : (
            <Muted>Nothing outstanding on their current membership.</Muted>
          )
        ) : null}
      </FormSection>

      <FormSection title="How much">
        <FormRow label="Amount" error={errors.amount}>
          <MoneyField testID="amount" value={amount} onChange={setAmount} />
        </FormRow>

        <FormRow label="Method" error={errors.method}>
          <SelectField testID="method" value={method} options={METHODS} onChange={(v) => setMethod(String(v))} />
        </FormRow>

        <FormRow label="What for" error={errors.payment_type}>
          <SelectField testID="type" value={type} options={TYPES} onChange={(v) => setType(String(v))} />
        </FormRow>

        <FormRow label="Paid on" error={errors.paid_at}>
          <DateField value={paidAt} onChange={setPaidAt} />
        </FormRow>

        <FormRow
          label="Reference"
          hint="A card slip or transfer number, if there is one."
          error={errors.reference}
        >
          <TextField value={reference} onChange={setReference} placeholder="Optional" autoCapitalize="none" />
        </FormRow>

        <FormRow label="Notes" error={errors.notes}>
          <TextField value={notes} onChange={setNotes} placeholder="Optional" multiline />
        </FormRow>
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <View style={{ gap: 10 }}>
        <Button label="Record payment" onPress={submit} loading={save.isPending} />
        <Button label="Done" variant="ghost" onPress={() => router.back()} />
      </View>
    </Screen>
  )
}
