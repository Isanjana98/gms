import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Body, Button, Card, Empty, Eyebrow, Fact, Heading, Muted, Pill } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { expiryLabel, formatDate, initials, money } from '../../src/lib/format'
import { expiryTone, statusTone, theme } from '../../src/lib/theme'
import type { MemberSummary, Paginated } from '../../src/lib/types'

/**
 * Member lookup.
 *
 * What reception is asked at the counter: when does it run out, what do they
 * owe, what is their number. Deliberately read-only — editing a member on a
 * phone is worse than editing one at a desk, and the admin panel is a browser
 * away.
 */
export default function Members() {
  const { can } = useAuth()
  const router = useRouter()
  const [term, setTerm] = useState('')
  const [selected, setSelected] = useState<MemberSummary | null>(null)

  const results = useQuery({
    queryKey: ['members', term],
    enabled: term.trim().length >= 2,
    queryFn: async () =>
      (
        await api.get<Paginated<MemberSummary>>('/members', {
          params: { search: term.trim(), per_page: 20 },
        })
      ).data.data,
  })

  const detail = useQuery({
    queryKey: ['member', selected?.id],
    enabled: Boolean(selected),
    queryFn: async () =>
      (await api.get<{ data: MemberSummary }>(`/members/${selected!.id}`)).data.data,
  })

  if (selected) {
    const member = detail.data ?? selected
    const membership = member.current_membership

    return (
      <Screen title={member.full_name} subtitle={member.membership_number}>
        {detail.isLoading ? (
          <Card>
            <ActivityIndicator color={theme.accent} style={{ paddingVertical: 28 }} />
          </Card>
        ) : detail.error ? (
          <Card>
            <Body style={{ color: theme.accentHot }}>{errorMessage(detail.error)}</Body>
          </Card>
        ) : (
          <>
            <Card style={{ borderColor: expiryTone(membership?.days_remaining), borderWidth: 1.5 }}>
              <View style={styles.top}>
                <View style={styles.avatarLarge}>
                  <Text style={styles.avatarLargeText}>{initials(member.full_name)}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Heading>{membership?.plan_name ?? 'No active membership'}</Heading>
                  <Muted>{member.membership_number}</Muted>
                </View>
                <Pill text={member.status.toLowerCase()} tone={statusTone(member.status)} />
              </View>

              <Text style={[styles.expiry, { color: expiryTone(membership?.days_remaining) }]}>
                {expiryLabel(membership?.days_remaining)}
              </Text>
              {membership?.end_date ? <Muted>Until {formatDate(membership.end_date)}</Muted> : null}

              {membership && membership.balance > 0 ? (
                <View style={styles.balance}>
                  <Text style={{ color: theme.warn, fontSize: 15 }}>
                    {money(membership.balance)} outstanding — take payment at the desk.
                  </Text>
                </View>
              ) : null}
            </Card>

            <Card>
              <Eyebrow>Contact</Eyebrow>
              <Fact label="Telephone" value={member.telephone ?? '—'} />
              <Fact label="Email" value={member.email ?? '—'} />
              <Fact label="Total visits" value={String(member.total_visits ?? 0)} />
              <Fact label="Last visit" value={formatDate(member.last_visit_at)} />

              {member.telephone ? (
                <Button
                  label={`Call ${member.telephone}`}
                  variant="ghost"
                  onPress={() => void Linking.openURL(`tel:${member.telephone}`)}
                  style={{ marginTop: 16 }}
                />
              ) : null}
            </Card>

            {can(P.membersUpdate) ? (
              <Button label="Edit their details" onPress={() => router.push(`/members/${member.id}`)} />
            ) : null}

            <Button label="Back to search" variant="ghost" onPress={() => setSelected(null)} />
          </>
        )}
      </Screen>
    )
  }

  return (
    <Screen title="Members" subtitle="Search by name, number or phone">
      {can(P.membersCreate) ? (
        <Button label="Register a member" onPress={() => router.push('/members/new')} />
      ) : null}

      <TextInput
        testID="member-search"
        value={term}
        onChangeText={setTerm}
        placeholder="Nimal, GM-2026-00021, 077…"
        placeholderTextColor={theme.dim}
        autoCapitalize="none"
        autoCorrect={false}
        style={styles.search}
      />

      {term.trim().length < 2 ? (
        <Card>
          <Empty title="Look somebody up" description="Type at least two characters." />
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
                <Muted>
                  {member.membership_number}
                  {member.telephone ? ` · ${member.telephone}` : ''}
                </Muted>
              </View>
              <Pill text={member.status.toLowerCase()} tone={statusTone(member.status)} />
            </Pressable>
          ))}
        </Card>
      )}
    </Screen>
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

  top: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 18 },
  expiry: { fontSize: 24, fontWeight: '800', letterSpacing: -0.4 },

  balance: {
    marginTop: 16,
    padding: 12,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.30)',
  },
})
