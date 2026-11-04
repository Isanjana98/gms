import { useQuery } from '@tanstack/react-query'
import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { Linking } from 'react-native'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SelectField, TextField } from '../../src/components/form'
import { Body, Button, Card, Fact, Loading, Muted, Pill } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate } from '../../src/lib/format'
import { saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Lead, Paginated } from '../../src/lib/types'

const STATUSES = [
  { label: 'New', value: 'NEW' },
  { label: 'Contacted', value: 'CONTACTED' },
  { label: 'Closed', value: 'CLOSED' },
]

/**
 * One enquiry, and the two things worth doing with it: ring them, or turn
 * them into a member.
 *
 * There is no single-lead endpoint, so the row comes from the list. Refetching
 * the list after a change keeps this screen honest without inventing an API.
 */
export default function LeadDetail() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const leads = useQuery({
    queryKey: ['/leads', 'one', id],
    queryFn: async () =>
      (await api.get<Paginated<Lead>>('/leads', { params: { per_page: 100 } })).data.data,
  })

  const act = useAction({ invalidate: ['/leads', '/members'] })

  const [status, setStatus] = useState<string | null>(null)
  const [notes, setNotes] = useState<string | null>(null)
  const [converting, setConverting] = useState(false)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  if (leads.isLoading) {
    return (
      <Screen title="Enquiry" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  const row = leads.data?.find((l) => String(l.id) === String(id))

  if (!row) {
    return (
      <Screen title="Enquiry" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>
            {leads.error ? saveError(leads.error) : 'That enquiry is no longer in the list.'}
          </Body>
        </Card>
      </Screen>
    )
  }

  if (!can(P.leadsView)) {
    return (
      <Screen title="Enquiry" onBack={() => router.back()}>
        <NotPermitted what="see enquiries" />
      </Screen>
    )
  }

  const save = () => {
    setResult(null)
    act.mutate(
      {
        url: `/leads/${id}`,
        method: 'patch',
        payload: { status: status ?? row.status, notes: notes ?? row.notes ?? undefined },
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: 'Enquiry updated.' })
          void leads.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen title={row.name} subtitle={formatDate(row.created_at)} onBack={() => router.back()}>
      <Card>
        <Pill text={row.status.toLowerCase()} tone={theme.muted} />
        <Fact label="Telephone" value={row.telephone ?? '—'} />
        <Fact label="Email" value={row.email ?? '—'} />
        <Fact label="Interested in" value={row.plan ?? '—'} />
        <Fact label="Came from" value={row.source ?? '—'} />
        <Fact label="Handled by" value={row.handled_by ?? '—'} />
        {row.message ? <Fact label="They said" value={row.message} /> : null}
        {row.converted_member ? (
          <Fact label="Became" value={`${row.converted_member.full_name} (${row.converted_member.membership_number})`} />
        ) : null}
      </Card>

      {row.telephone ? (
        <Button
          label={`Call ${row.telephone}`}
          variant="ghost"
          onPress={() => void Linking.openURL(`tel:${row.telephone}`)}
        />
      ) : null}

      {can(P.leadsManage) && !row.converted_member ? (
        <>
          <FormSection title="Update">
            <FormRow label="Status">
              <SelectField
                value={status ?? row.status}
                options={STATUSES}
                onChange={(v) => setStatus(String(v))}
              />
            </FormRow>
            <FormRow label="Notes">
              <TextField
                value={notes ?? row.notes ?? ''}
                onChange={setNotes}
                placeholder="What was said"
                multiline
              />
            </FormRow>
            <Button label="Save" onPress={save} loading={act.isPending} />
          </FormSection>

          <Button label="Turn into a member…" onPress={() => setConverting(true)} />
        </>
      ) : null}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>An enquiry that becomes a member keeps its history against that member.</Muted>

      <ConfirmSheet
        visible={converting}
        title={`Create a member for ${row.name}?`}
        message="Their name and contact details are copied across. You can sell them a membership straight after."
        confirmLabel="Create member"
        onCancel={() => setConverting(false)}
        onConfirm={() => {
          setConverting(false)
          setResult(null)
          act.mutate(
            { url: `/leads/${id}/convert`, payload: {} },
            {
              onSuccess: () => {
                setResult({ ok: true, message: 'Member created from this enquiry.' })
                void leads.refetch()
              },
              onError: (error) => setResult({ ok: false, message: saveError(error) }),
            },
          )
        }}
      />
    </Screen>
  )
}
