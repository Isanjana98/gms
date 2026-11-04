import { useQuery } from '@tanstack/react-query'
import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { View } from 'react-native'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { DateField, FormRow, FormSection, MoneyField, Outcome, SelectField, TextField } from '../../src/components/form'
import { Body, Button, Card, Fact, Heading, Loading, Muted, Pill } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useAction, useResource } from '../../src/lib/resource'
import { expiryTone, statusTone, theme } from '../../src/lib/theme'
import type { Membership, MembershipPlan, Paginated } from '../../src/lib/types'

/**
 * One membership, and everything that can be done to it.
 *
 * Renewing shows the dates the server would use *before* committing, because
 * "does this run from today or from the day it expires?" is a question with
 * money attached and the answer is a setting, not a guess.
 */
export default function MembershipDetail() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const membership = useResource<Membership>('/memberships', id)
  const act = useAction({ invalidate: ['/memberships', '/members', '/invoices'] })

  const [renewing, setRenewing] = useState(false)
  const [planId, setPlanId] = useState<string | number | null>(null)
  const [startDate, setStartDate] = useState('')
  const [discount, setDiscount] = useState('')
  const [notes, setNotes] = useState('')
  const [reason, setReason] = useState('')
  const [confirm, setConfirm] = useState<'suspend' | 'cancel' | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const plans = useQuery({
    queryKey: ['/membership-plans'],
    enabled: renewing,
    queryFn: async () =>
      (await api.get<Paginated<MembershipPlan>>('/membership-plans', { params: { per_page: 100 } })).data.data,
  })

  const preview = useQuery({
    queryKey: ['renewal-preview', id, planId],
    enabled: renewing && Boolean(id),
    queryFn: async () =>
      (
        await api.get<{ start_date: string; end_date: string; price: number; final_amount: number }>(
          `/memberships/${id}/renewal-preview`,
          { params: { membership_plan_id: planId ?? undefined } },
        )
      ).data,
  })

  if (membership.isLoading) {
    return (
      <Screen title="Membership" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (membership.error || !membership.data) {
    return (
      <Screen title="Membership" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(membership.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const row = membership.data

  const run = (url: string, payload?: unknown, done = 'Done.') => {
    setResult(null)
    act.mutate(
      { url, payload },
      {
        onSuccess: () => {
          setResult({ ok: true, message: done })
          setRenewing(false)
          void membership.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen title={row.reference} subtitle={row.member?.full_name ?? undefined} onBack={() => router.back()}>
      <Card style={{ borderColor: expiryTone(row.days_remaining), borderWidth: 1.5 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 14 }}>
          <View style={{ flex: 1 }}>
            <Heading>{row.plan_name}</Heading>
            <Muted>
              {formatDate(row.start_date)} — {formatDate(row.end_date)}
            </Muted>
          </View>
          <Pill text={(row.status_label ?? row.status).toLowerCase()} tone={statusTone(row.status)} />
        </View>

        <Fact label="Days left" value={row.is_expired ? 'Expired' : String(row.days_remaining)} />
        <Fact label="Price" value={money(row.price)} />
        {row.discount ? <Fact label="Discount" value={money(row.discount)} /> : null}
        {row.registration_fee ? <Fact label="Registration" value={money(row.registration_fee)} /> : null}
        <Fact label="Total" value={money(row.final_amount)} />
        <Fact label="Paid" value={money(row.paid_amount)} />
        <Fact label="Owing" value={row.balance > 0 ? money(row.balance) : 'Nothing'} />
        {row.notes ? <Fact label="Notes" value={row.notes} /> : null}
      </Card>

      {row.balance > 0 && can(P.paymentsCreate) ? (
        <Button label={`Take the ${money(row.balance)} owing`} onPress={() => router.push('/payments/new')} />
      ) : null}

      {/* -------------------------------------------------------- renewal */}
      {can(P.membershipsRenew) ? (
        renewing ? (
          <FormSection title="Renew">
            <FormRow label="Plan" hint="Leave as is to renew onto the same plan.">
              <SelectField
                value={planId ?? row.membership_plan_id}
                options={(plans.data ?? []).map((p) => ({
                  label: p.name,
                  value: p.id,
                  hint: `${money(p.price)} · ${p.duration_label ?? ''}`,
                }))}
                onChange={setPlanId}
                placeholder={plans.isLoading ? 'Loading…' : 'Same plan'}
              />
            </FormRow>

            <FormRow label="Starts" hint="Blank uses the gym's rule for where a renewal begins.">
              <DateField value={startDate} onChange={setStartDate} />
            </FormRow>

            <FormRow label="Discount">
              <MoneyField value={discount} onChange={setDiscount} />
            </FormRow>

            <FormRow label="Notes">
              <TextField value={notes} onChange={setNotes} placeholder="Optional" multiline />
            </FormRow>

            {preview.data ? (
              <Card style={{ backgroundColor: theme.surface2 }}>
                <Muted>The server would write</Muted>
                <Fact label="From" value={formatDate(preview.data.start_date)} />
                <Fact label="Until" value={formatDate(preview.data.end_date)} />
                <Fact label="To pay" value={money(preview.data.final_amount)} />
              </Card>
            ) : null}

            <Button
              label="Renew this membership"
              loading={act.isPending}
              onPress={() =>
                run(
                  `/memberships/${id}/renew`,
                  {
                    membership_plan_id: planId ?? undefined,
                    start_date: startDate || undefined,
                    discount: discount ? Number(discount) : undefined,
                    notes: notes.trim() || undefined,
                    activate: true,
                  },
                  'Renewed.',
                )
              }
            />
            <Button label="Cancel" variant="ghost" onPress={() => setRenewing(false)} />
          </FormSection>
        ) : (
          <Button label="Renew…" variant="ghost" onPress={() => setRenewing(true)} />
        )
      ) : null}

      {/* -------------------------------------------------------- lifecycle */}
      {can(P.membershipsCreate) && row.status === 'PENDING' ? (
        <Button
          label="Activate"
          variant="ghost"
          loading={act.isPending}
          onPress={() => run(`/memberships/${id}/activate`, undefined, 'Activated.')}
        />
      ) : null}

      {can(P.membershipsCancel) && row.status === 'ACTIVE' ? (
        <>
          <FormRow label="Reason" hint="Recorded against the suspension.">
            <TextField value={reason} onChange={setReason} placeholder="Optional" />
          </FormRow>
          <Button label="Suspend…" variant="ghost" onPress={() => setConfirm('suspend')} />
          <Button label="Cancel this membership…" variant="ghost" onPress={() => setConfirm('cancel')} />
        </>
      ) : null}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <ConfirmSheet
        visible={confirm === 'suspend'}
        title="Suspend this membership?"
        message="The member will be refused entry until it is reinstated. The term keeps running."
        confirmLabel="Suspend"
        onCancel={() => setConfirm(null)}
        onConfirm={() => {
          setConfirm(null)
          run(`/memberships/${id}/suspend`, { reason: reason.trim() || undefined }, 'Suspended.')
        }}
      />

      <ConfirmSheet
        visible={confirm === 'cancel'}
        title="Cancel this membership?"
        message="This ends the term. Any money already taken stays recorded and is refunded separately."
        confirmLabel="Cancel it"
        onCancel={() => setConfirm(null)}
        onConfirm={() => {
          setConfirm(null)
          setResult(null)
          act.mutate(
            { url: `/memberships/${id}`, method: 'delete' },
            {
              onSuccess: () => router.back(),
              onError: (error) => setResult({ ok: false, message: saveError(error) }),
            },
          )
        }}
      />
    </Screen>
  )
}
