import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { View } from 'react-native'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { FormRow, MoneyField, Outcome, TextField } from '../../src/components/form'
import { Body, Button, Card, Eyebrow, Fact, Heading, Loading, Muted, Pill } from '../../src/components/ui'
import { P, useAuth } from '../../src/lib/auth'
import { openDocument } from '../../src/lib/documents'
import { formatDate, formatTime, money } from '../../src/lib/format'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Payment } from '../../src/lib/types'

/** One payment: what it was, and the two things that can still be done to it. */
export default function PaymentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const payment = useResource<Payment>('/payments', id)
  const refund = useAction({ invalidate: ['/payments', '/invoices', '/members'] })

  const [refunding, setRefunding] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  if (payment.isLoading) {
    return (
      <Screen title="Payment" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (payment.error || !payment.data) {
    return (
      <Screen title="Payment" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(payment.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const row = payment.data

  const doRefund = () => {
    setConfirming(false)
    setResult(null)

    refund.mutate(
      {
        url: `/payments/${row.id}/refund`,
        payload: { amount: amount ? Number(amount) : undefined, reason: reason.trim() || undefined },
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: 'Refund recorded.' })
          setRefunding(false)
          void payment.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen title={row.receipt_number} subtitle={row.member?.full_name ?? undefined} onBack={() => router.back()}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 14 }}>
          <View style={{ flex: 1 }}>
            <Heading>{money(row.amount)}</Heading>
            <Muted>{row.payment_type_label ?? row.payment_type}</Muted>
          </View>
          <Pill
            text={row.status.toLowerCase()}
            tone={row.is_refund ? theme.warn : row.status === 'COMPLETED' ? theme.good : theme.dim}
          />
        </View>

        <Fact label="Member" value={row.member?.full_name ?? '—'} />
        <Fact label="Membership no." value={row.member?.membership_number ?? '—'} />
        <Fact label="Method" value={row.method_label ?? row.method} />
        <Fact label="Paid" value={`${formatDate(row.paid_at)} ${formatTime(row.paid_at)}`} />
        <Fact label="Reference" value={row.reference ?? '—'} />
        <Fact label="Taken by" value={row.received_by?.name ?? '—'} />
        {row.notes ? <Fact label="Notes" value={row.notes} /> : null}
      </Card>

      <Button
        label="Open receipt"
        variant="ghost"
        onPress={() => {
          setResult(null)
          openDocument(`/payments/${row.id}/receipt`, `receipt-${row.receipt_number}.pdf`).catch((error) =>
            setResult({ ok: false, message: saveError(error) }),
          )
        }}
      />

      {can(P.paymentsRefund) && !row.is_refund && row.status === 'COMPLETED' ? (
        refunding ? (
          <Card>
            <Eyebrow>Refund</Eyebrow>
            <View style={{ gap: 16, marginTop: 4 }}>
              <FormRow
                label="Amount"
                hint={`Leave blank to refund the whole ${money(row.amount)}.`}
              >
                <MoneyField value={amount} onChange={setAmount} />
              </FormRow>
              <FormRow label="Reason">
                <TextField value={reason} onChange={setReason} placeholder="Why it is being refunded" multiline />
              </FormRow>
              <Button label="Refund this payment" onPress={() => setConfirming(true)} loading={refund.isPending} />
              <Button label="Cancel" variant="ghost" onPress={() => setRefunding(false)} />
            </View>
          </Card>
        ) : (
          <Button label="Refund…" variant="ghost" onPress={() => setRefunding(true)} />
        )
      ) : null}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <ConfirmSheet
        visible={confirming}
        title="Refund this payment?"
        message={`${amount ? money(Number(amount)) : money(row.amount)} will be refunded against ${row.receipt_number}. This is recorded and cannot be undone from the app.`}
        confirmLabel="Refund"
        onConfirm={doRefund}
        onCancel={() => setConfirming(false)}
      />
    </Screen>
  )
}
