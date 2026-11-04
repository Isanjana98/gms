import { useQuery } from '@tanstack/react-query'
import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, TextField } from '../../src/components/form'
import { Body, Button, Card, Fact, Loading, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { duration, formatDate, formatTime } from '../../src/lib/format'
import { saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { AttendanceRow, Paginated } from '../../src/lib/types'

/**
 * Correct one visit.
 *
 * Times are edited as full timestamps because that is what the API stores and
 * what a correction usually is — somebody forgot to check out and the row has
 * to be closed at the right hour, not merely today.
 */
export default function EditAttendance() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { can } = useAuth()
  const router = useRouter()

  const rows = useQuery({
    queryKey: ['/attendance', 'one', id],
    queryFn: async () =>
      (await api.get<Paginated<AttendanceRow>>('/attendance', { params: { per_page: 100 } })).data.data,
  })

  const act = useAction({ invalidate: ['/attendance', '/live', '/summary'] })

  const [checkIn, setCheckIn] = useState<string | null>(null)
  const [checkOut, setCheckOut] = useState<string | null>(null)
  const [notes, setNotes] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  if (!can(P.attendanceManage)) {
    return (
      <Screen title="Visit" onBack={() => router.back()}>
        <NotPermitted what="correct the visit log" />
      </Screen>
    )
  }

  if (rows.isLoading) {
    return (
      <Screen title="Visit" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  const row = rows.data?.find((r) => String(r.id) === String(id))

  if (!row) {
    return (
      <Screen title="Visit" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>
            {rows.error ? saveError(rows.error) : 'That visit is no longer in the recent log.'}
          </Body>
        </Card>
      </Screen>
    )
  }

  const save = () => {
    setResult(null)
    act.mutate(
      {
        url: `/attendance/${id}`,
        method: 'patch',
        payload: {
          check_in_time: checkIn ?? row.check_in_time,
          check_out_time: checkOut ?? row.check_out_time,
          notes: notes ?? row.notes ?? undefined,
        },
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: 'Visit corrected.' })
          void rows.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen
      title={row.member?.full_name ?? 'Unmatched scan'}
      subtitle={formatDate(row.attendance_date)}
      onBack={() => router.back()}
    >
      <Card>
        <Fact label="Membership no." value={row.member?.membership_number ?? '—'} />
        <Fact label="In" value={formatTime(row.check_in_time)} />
        <Fact label="Out" value={row.check_out_time ? formatTime(row.check_out_time) : 'Still inside'} />
        <Fact label="Length" value={row.duration_minutes ? duration(row.duration_minutes) : '—'} />
        <Fact label="Recorded by" value={row.attendance_type} />
        {row.validation_message ? <Fact label="At the time" value={row.validation_message} /> : null}
      </Card>

      <FormSection title="Correct it">
        <FormRow label="Checked in" hint="Full timestamp, as the system stores it.">
          <TextField
            value={checkIn ?? row.check_in_time ?? ''}
            onChange={setCheckIn}
            autoCapitalize="none"
            placeholder="2026-09-25T08:30:00+05:30"
          />
        </FormRow>

        <FormRow label="Checked out" hint="Leave blank to leave them inside.">
          <TextField
            value={checkOut ?? row.check_out_time ?? ''}
            onChange={setCheckOut}
            autoCapitalize="none"
            placeholder="2026-09-25T09:45:00+05:30"
          />
        </FormRow>

        <FormRow label="Notes">
          <TextField
            value={notes ?? row.notes ?? ''}
            onChange={setNotes}
            placeholder="Why it was corrected"
            multiline
          />
        </FormRow>

        <Button label="Save correction" onPress={save} loading={act.isPending} />
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>Corrections are recorded in the audit log against your account.</Muted>

      <Button label="Delete this visit" variant="ghost" onPress={() => setConfirming(true)} />

      <ConfirmSheet
        visible={confirming}
        title="Delete this visit?"
        message="It stops counting towards the member's attendance and today's numbers."
        confirmLabel="Delete"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          act.mutate(
            { url: `/attendance/${id}`, method: 'delete' },
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
