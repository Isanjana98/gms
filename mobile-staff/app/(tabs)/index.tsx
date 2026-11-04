import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Body, Button, Card, Empty, Eyebrow, Heading, Muted, Pill } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, formatTime, initials } from '../../src/lib/format'
import { expiryTone, statusTone, theme } from '../../src/lib/theme'
import type { CheckInOutcome, EntryValidation, MemberSummary, Paginated } from '../../src/lib/types'

/**
 * The check-in desk.
 *
 * Search, look at the verdict, then act. The order matters: the system is
 * asked "would this person be let in?" **without recording anything**, so a
 * lapsed member starts a renewal conversation instead of leaving a refused
 * entry in the log.
 */
export default function CheckIn() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [term, setTerm] = useState('')
  const [selected, setSelected] = useState<MemberSummary | null>(null)
  const [outcome, setOutcome] = useState<{ ok: boolean; message: string } | null>(null)
  const [busy, setBusy] = useState(false)

  const canManage = can(P.attendanceManage)

  const results = useQuery({
    queryKey: ['member-search', term],
    // Two characters is the point where a search stops matching half the gym.
    enabled: term.trim().length >= 2 && !selected,
    queryFn: async () =>
      (
        await api.get<Paginated<MemberSummary>>('/members', {
          params: { search: term.trim(), per_page: 8 },
        })
      ).data.data,
  })

  const validation = useQuery({
    queryKey: ['validate', selected?.id],
    enabled: Boolean(selected),
    // Always fresh: a verdict from thirty seconds ago is not a verdict.
    staleTime: 0,
    queryFn: async () =>
      (await api.get<EntryValidation>(`/attendance/validate/${selected!.id}`)).data,
  })

  const act = async (direction: 'IN' | 'OUT') => {
    if (!selected) return

    setBusy(true)
    setOutcome(null)

    try {
      const { data } = await api.post<CheckInOutcome>('/attendance', {
        member_id: selected.id,
        direction,
      })

      setOutcome({ ok: data.accepted, message: data.message })
      await queryClient.invalidateQueries({ queryKey: ['validate', selected.id] })
      await queryClient.invalidateQueries({ queryKey: ['summary'] })
      await queryClient.invalidateQueries({ queryKey: ['live'] })
    } catch (error) {
      setOutcome({ ok: false, message: errorMessage(error) })
    } finally {
      setBusy(false)
    }
  }

  const reset = () => {
    setSelected(null)
    setOutcome(null)
    setTerm('')
  }

  return (
    <Screen title="Check-in" subtitle={selected ? undefined : 'Search by name, number or phone'}>
      {!selected ? (
        <>
          <TextInput
            testID="search"
            value={term}
            onChangeText={setTerm}
            placeholder="Nimal, GM-2026-00021, 077…"
            placeholderTextColor={theme.dim}
            autoCapitalize="none"
            autoCorrect={false}
            style={styles.search}
            autoFocus
          />

          {term.trim().length < 2 ? (
            <Card>
              <Empty
                title="Who is coming in?"
                description="Type at least two characters of a name, membership number or phone."
              />
            </Card>
          ) : results.isLoading ? (
            <Card>
              <ActivityIndicator color={theme.accent} style={{ paddingVertical: 24 }} />
            </Card>
          ) : !results.data?.length ? (
            <Card>
              <Empty title="Nobody found" description="Check the spelling, or try their phone number." />
            </Card>
          ) : (
            <Card style={{ padding: 0 }}>
              {results.data.map((member, index) => (
                <Pressable
                  key={member.id}
                  testID={`result-${index}`}
                  onPress={() => setSelected(member)}
                  accessibilityRole="button"
                  style={({ pressed }) => [
                    styles.row,
                    index === results.data.length - 1 && styles.rowLast,
                    pressed && styles.rowPressed,
                  ]}
                >
                  <View style={styles.avatar}>
                    <Text style={styles.avatarText}>{initials(member.full_name)}</Text>
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.name}>{member.full_name}</Text>
                    <Muted>{member.membership_number}</Muted>
                  </View>
                  <Pill text={member.status.toLowerCase()} tone={statusTone(member.status)} />
                </Pressable>
              ))}
            </Card>
          )}
        </>
      ) : (
        <>
          {/* The verdict, before anything is recorded. */}
          {validation.isLoading ? (
            <Card>
              <ActivityIndicator color={theme.accent} style={{ paddingVertical: 32 }} />
            </Card>
          ) : validation.error ? (
            <Card>
              <Body style={{ color: theme.accentHot }}>{errorMessage(validation.error)}</Body>
            </Card>
          ) : validation.data ? (
            <Verdict data={validation.data} />
          ) : null}

          {outcome ? (
            <View style={[styles.outcome, outcome.ok ? styles.outcomeOk : styles.outcomeBad]}>
              <Text style={{ color: outcome.ok ? theme.good : theme.accentHot, fontSize: 15 }}>
                {outcome.message}
              </Text>
            </View>
          ) : null}

          {canManage ? (
            <View style={{ gap: 10 }}>
              {validation.data?.currently_checked_in ? (
                <Button label="Check out" onPress={() => void act('OUT')} loading={busy} />
              ) : (
                <Button label="Check in" onPress={() => void act('IN')} loading={busy} />
              )}
              <Button label="Someone else" variant="ghost" onPress={reset} />
            </View>
          ) : (
            <>
              <Card>
                <Muted>
                  You can look members up but not record attendance. Ask an administrator for the
                  attendance permission.
                </Muted>
              </Card>
              <Button label="Someone else" variant="ghost" onPress={reset} />
            </>
          )}
        </>
      )}
    </Screen>
  )
}

/**
 * Whether this person is getting in, said plainly.
 *
 * The colour and the sentence come from the server, which owns the rule. The
 * app must never decide this for itself — a phone that disagrees with the door
 * is worse than no phone.
 */
function Verdict({ data }: { data: EntryValidation }) {
  const tone = data.valid ? theme.good : theme.bad

  return (
    <Card style={{ borderColor: tone, borderWidth: 1.5 }}>
      <View style={styles.verdictTop}>
        <View style={styles.avatarLarge}>
          <Text style={styles.avatarLargeText}>{initials(data.member.full_name)}</Text>
        </View>
        <View style={{ flex: 1 }}>
          <Heading>{data.member.full_name}</Heading>
          <Muted>{data.member.membership_number}</Muted>
        </View>
      </View>

      <Text style={[styles.verdict, { color: tone }]}>
        {data.valid ? 'Let them in' : 'Do not let them in'}
      </Text>
      <Body style={{ marginTop: 2 }}>{data.message}</Body>

      {data.currently_checked_in ? (
        <View style={styles.inside}>
          <Text style={{ color: theme.warn, fontSize: 14 }}>
            Already inside since {formatTime(data.checked_in_at)}
          </Text>
        </View>
      ) : null}

      {data.membership ? (
        <View style={styles.membership}>
          <Muted>
            {data.membership.plan_name} · expires {formatDate(data.membership.end_date)}
          </Muted>
          <Text style={{ color: expiryTone(data.membership.days_remaining), fontSize: 13, fontWeight: '700' }}>
            {data.membership.days_remaining} days left
          </Text>
        </View>
      ) : (
        <View style={styles.membership}>
          <Muted>No active membership.</Muted>
        </View>
      )}
    </Card>
  )
}

const styles = StyleSheet.create({
  search: {
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 12,
    color: theme.text,
    fontSize: 16,
    paddingHorizontal: 14,
    minHeight: 52,
  },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  rowPressed: { backgroundColor: theme.surface2 },

  avatar: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: theme.surface2,
    borderWidth: 1,
    borderColor: theme.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: theme.muted, fontWeight: '800', fontSize: 13 },

  avatarLarge: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: theme.surface2,
    borderWidth: 1,
    borderColor: theme.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarLargeText: { color: theme.muted, fontWeight: '800', fontSize: 16 },

  name: { color: theme.text, fontSize: 15, fontWeight: '600' },

  verdictTop: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 18 },
  verdict: { fontSize: 24, fontWeight: '800', letterSpacing: -0.4 },

  inside: {
    marginTop: 14,
    padding: 11,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.30)',
  },

  membership: {
    marginTop: 16,
    paddingTop: 14,
    borderTopWidth: 1,
    borderTopColor: theme.line,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: 12,
  },

  outcome: { padding: 14, borderRadius: 10, borderWidth: 1 },
  outcomeOk: { backgroundColor: 'rgba(16,185,129,0.10)', borderColor: 'rgba(16,185,129,0.35)' },
  outcomeBad: { backgroundColor: 'rgba(225,29,46,0.10)', borderColor: 'rgba(225,29,46,0.35)' },
})
