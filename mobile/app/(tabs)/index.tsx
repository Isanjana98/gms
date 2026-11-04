import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Linking, StyleSheet, Text, View } from 'react-native'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { Body, Button, Card, Eyebrow, Fact, Heading, Muted, Pill } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { useAuth } from '../../src/lib/auth'
import { expiryLabel, formatDate, initials, money } from '../../src/lib/format'
import { expiryTone, statusTone, theme } from '../../src/lib/theme'
import type { GymDetails } from '../../src/lib/types'

/**
 * The membership card.
 *
 * The one screen the app exists for: how long is left, and what to do about
 * it. Everything else on this screen is secondary to that number.
 */
export default function Membership() {
  const { member, signOut, refresh } = useAuth()
  const queryClient = useQueryClient()
  const [refreshing, setRefreshing] = useState(false)
  const [confirmingSignOut, setConfirmingSignOut] = useState(false)

  const gym = useQuery({
    queryKey: ['gym'],
    queryFn: async () => (await api.get<GymDetails>('/member/gym')).data,
  })

  const onRefresh = async () => {
    setRefreshing(true)

    try {
      await refresh()
      await queryClient.invalidateQueries()
    } finally {
      setRefreshing(false)
    }
  }

  if (!member) return null

  const membership = member.membership
  const tone = expiryTone(membership?.days_remaining)

  const call = () => {
    const number = gym.data?.branch?.telephone
    if (number) void Linking.openURL(`tel:${number}`)
  }

  return (
    <Screen title={member.full_name} subtitle={member.membership_number} onRefresh={onRefresh} refreshing={refreshing}>
      {/* The card a member would otherwise carry in their wallet. */}
      <Card style={[styles.card, { borderColor: tone }]}>
        <View style={styles.cardTop}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{initials(member.full_name)}</Text>
          </View>
          <View style={{ flex: 1 }}>
            <Heading>{membership?.plan_name ?? 'No active membership'}</Heading>
            <Muted>{member.branch ?? ''}</Muted>
          </View>
          <Pill text={member.status?.toLowerCase() ?? 'unknown'} tone={statusTone(member.status)} />
        </View>

        <View style={styles.expiry}>
          <Text style={[styles.expiryValue, { color: tone }]}>
            {expiryLabel(membership?.days_remaining)}
          </Text>
          {membership?.end_date ? (
            <Muted style={{ marginTop: 2 }}>Until {formatDate(membership.end_date)}</Muted>
          ) : (
            <Muted style={{ marginTop: 2 }}>Speak to reception to start a membership.</Muted>
          )}
        </View>

        {membership && membership.balance > 0 ? (
          <View style={styles.balance}>
            <Body style={{ color: theme.warn }}>
              {money(membership.balance)} outstanding on this membership.
            </Body>
          </View>
        ) : null}
      </Card>

      <Card>
        <Eyebrow>Your membership</Eyebrow>
        <Fact label="Member since" value={formatDate(member.join_date)} />
        <Fact label="Total visits" value={String(member.total_visits)} />
        <Fact label="Last visit" value={formatDate(member.last_visit_at)} />
        {membership ? <Fact label="Reference" value={membership.reference} /> : null}
      </Card>

      {gym.data ? (
        <Card>
          <Eyebrow>{gym.data.name}</Eyebrow>

          {gym.data.branch?.address ? (
            <Fact
              label="Address"
              value={[gym.data.branch.address, gym.data.branch.city].filter(Boolean).join(', ')}
            />
          ) : null}

          {gym.data.opening_hours?.map((row, index) => (
            <Fact key={index} label={row.days ?? 'Hours'} value={row.hours ?? ''} />
          ))}

          {gym.data.branch?.telephone ? (
            <Button label={`Call ${gym.data.branch.telephone}`} variant="ghost" onPress={call} style={{ marginTop: 16 }} />
          ) : null}
        </Card>
      ) : null}

      <Button label="Sign out" variant="ghost" onPress={() => setConfirmingSignOut(true)} style={{ marginTop: 4 }} />

      <Muted style={{ textAlign: 'center', marginTop: 4 }}>
        Signing out only affects this device.
      </Muted>

      <ConfirmSheet
        visible={confirmingSignOut}
        title="Sign out?"
        message="You will need a new code to sign back in."
        confirmLabel="Sign out"
        onCancel={() => setConfirmingSignOut(false)}
        onConfirm={() => {
          setConfirmingSignOut(false)
          void signOut()
        }}
      />
    </Screen>
  )
}

const styles = StyleSheet.create({
  card: { borderWidth: 1.5 },
  cardTop: { flexDirection: 'row', alignItems: 'center', gap: 12 },

  avatar: {
    width: 46,
    height: 46,
    borderRadius: 23,
    backgroundColor: theme.surface2,
    borderWidth: 1,
    borderColor: theme.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: theme.muted, fontWeight: '800', fontSize: 15 },

  expiry: { marginTop: 20 },
  expiryValue: { fontSize: 28, fontWeight: '800', letterSpacing: -0.5 },

  balance: {
    marginTop: 16,
    padding: 12,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.30)',
  },
})
