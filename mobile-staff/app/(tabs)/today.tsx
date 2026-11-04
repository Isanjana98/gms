import { useQuery, useQueryClient } from '@tanstack/react-query'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Button, Card, Empty, ErrorNotice, Eyebrow, Loading, Muted } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { duration, formatTime, money } from '../../src/lib/format'
import { theme } from '../../src/lib/theme'
import type { DashboardSummary, LiveVisit, Paginated } from '../../src/lib/types'

/**
 * Today's numbers, and who is in the building.
 *
 * The figures a manager asks for from the floor rather than from a desk.
 * Money is shown only to somebody who may see it — the API refuses either way,
 * but a card reading "—" is better than one reading an error.
 */
export default function Today() {
  const { user, can } = useAuth()
  const queryClient = useQueryClient()

  const canSeeMoney = can(P.paymentsView)

  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: async () => (await api.get<DashboardSummary>('/dashboard/summary')).data,
  })

  const live = useQuery({
    queryKey: ['live'],
    queryFn: async () => (await api.get<Paginated<LiveVisit>>('/attendance/live')).data.data,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries()
  }

  if (summary.isLoading) {
    return (
      <Screen title="Today" scroll={false}>
        <Loading />
      </Screen>
    )
  }

  if (summary.error) {
    return (
      <Screen title="Today" scroll={false}>
        <ErrorNotice message={errorMessage(summary.error)} onRetry={() => void summary.refetch()} />
      </Screen>
    )
  }

  const data = summary.data
  const inside = live.data ?? []

  return (
    <Screen
      title="Today"
      subtitle={user?.branch?.name ?? (user?.is_multi_branch ? 'All branches' : undefined)}
      onRefresh={() => void refresh()}
      refreshing={summary.isRefetching || live.isRefetching}
    >
      <View style={styles.grid}>
        <Stat value={String(data?.attendance.currently_in ?? 0)} label="In the gym" tone={theme.good} />
        <Stat value={String(data?.attendance.today ?? 0)} label="Visits today" />
        <Stat value={String(data?.members.active ?? 0)} label="Active members" />
        <Stat
          value={String(data?.memberships.expiring_soon ?? 0)}
          label="Expiring soon"
          tone={(data?.memberships.expiring_soon ?? 0) > 0 ? theme.warn : undefined}
        />
      </View>

      {canSeeMoney ? (
        <Card>
          <Eyebrow>Money</Eyebrow>
          <View style={styles.moneyRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.moneyValue}>{money(data?.revenue.today)}</Text>
              <Text style={styles.moneyLabel}>Taken today</Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.moneyValue}>{money(data?.revenue.this_month)}</Text>
              <Text style={styles.moneyLabel}>This month</Text>
            </View>
          </View>

          {(data?.revenue.outstanding ?? 0) > 0 ? (
            <View style={styles.outstanding}>
              <Text style={{ color: theme.warn, fontSize: 14 }}>
                {money(data?.revenue.outstanding)} outstanding across unpaid invoices.
              </Text>
            </View>
          ) : null}
        </Card>
      ) : null}

      <Card style={{ padding: 0 }}>
        <View style={{ padding: 18, paddingBottom: 6 }}>
          <Eyebrow>In the gym now</Eyebrow>
        </View>

        {live.isLoading ? (
          <View style={{ padding: 18 }}>
            <Muted>Loading…</Muted>
          </View>
        ) : inside.length === 0 ? (
          <View style={{ paddingBottom: 12 }}>
            <Empty title="Nobody is checked in" description="Visits appear here as people arrive." />
          </View>
        ) : (
          inside.map((visit, index) => (
            <View key={visit.id} style={[styles.row, index === inside.length - 1 && styles.rowLast]}>
              <View style={{ flex: 1 }}>
                <Text style={styles.name}>{visit.member?.full_name ?? 'Unknown member'}</Text>
                <Muted>
                  {visit.member?.membership_number ?? ''} · in since {formatTime(visit.check_in_time)}
                </Muted>
              </View>
              <Text style={styles.duration}>{duration(visit.duration_minutes)}</Text>
            </View>
          ))
        )}
      </Card>

    </Screen>
  )
}

function Stat({ value, label, tone }: { value: string; label: string; tone?: string }) {
  return (
    <View style={styles.stat}>
      <Text style={[styles.statValue, tone ? { color: tone } : null]}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  stat: {
    // Two per row at any phone width, without a media query.
    flexGrow: 1,
    flexBasis: '46%',
    backgroundColor: theme.surface,
    borderWidth: 1,
    borderColor: theme.line,
    borderRadius: theme.radius,
    padding: 16,
  },
  statValue: { color: theme.text, fontSize: 26, fontWeight: '800', letterSpacing: -0.5 },
  statLabel: {
    color: theme.dim,
    fontSize: 10,
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginTop: 4,
  },

  moneyRow: { flexDirection: 'row', gap: 16, marginTop: 4 },
  moneyValue: { color: theme.text, fontSize: 19, fontWeight: '800' },
  moneyLabel: {
    color: theme.dim,
    fontSize: 10,
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginTop: 3,
  },
  outstanding: {
    marginTop: 16,
    padding: 11,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.30)',
  },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 18,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  name: { color: theme.text, fontSize: 15, fontWeight: '600' },
  duration: { color: theme.accentHot, fontSize: 14, fontWeight: '700' },
})
