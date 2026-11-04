import { useQuery } from '@tanstack/react-query'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Card, Empty, ErrorNotice, Eyebrow, Loading, Muted } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { duration, formatDate, formatTime } from '../../src/lib/format'
import { theme } from '../../src/lib/theme'
import type { AttendanceSummary, Paginated, Visit } from '../../src/lib/types'

type VisitsResponse = Paginated<Visit> & { summary: AttendanceSummary }

export default function Visits() {
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['visits'],
    queryFn: async () => (await api.get<VisitsResponse>('/member/attendance')).data,
  })

  if (isLoading) {
    return (
      <Screen title="Visits" scroll={false}>
        <Loading />
      </Screen>
    )
  }

  if (error) {
    return (
      <Screen title="Visits" scroll={false}>
        <ErrorNotice message={errorMessage(error)} onRetry={() => void refetch()} />
      </Screen>
    )
  }

  const visits = data?.data ?? []
  const summary = data?.summary

  return (
    <Screen
      title="Visits"
      subtitle={summary ? `${summary.total_visits} in total` : undefined}
      onRefresh={() => void refetch()}
      refreshing={isRefetching}
    >
      <View style={styles.stats}>
        <Stat value={String(summary?.this_month ?? 0)} label="This month" />
        <Stat value={String(summary?.total_visits ?? 0)} label="All time" />
        <Stat value={duration(summary?.avg_duration_minutes)} label="Average" />
      </View>

      {visits.length === 0 ? (
        <Card>
          <Empty
            title="No visits yet"
            description="Your check-ins appear here once you have been in."
          />
        </Card>
      ) : (
        <Card style={{ padding: 0 }}>
          <View style={{ padding: 18, paddingBottom: 6 }}>
            <Eyebrow>Recent</Eyebrow>
          </View>

          {visits.map((visit, index) => (
            <View key={visit.id} style={[styles.row, index === visits.length - 1 && styles.rowLast]}>
              <View style={{ flex: 1 }}>
                <Text style={styles.date}>{formatDate(visit.date)}</Text>
                <Muted>
                  {formatTime(visit.check_in_time)}
                  {visit.check_out_time ? ` – ${formatTime(visit.check_out_time)}` : ' – still in'}
                </Muted>
              </View>
              <Text style={styles.duration}>{duration(visit.duration_minutes)}</Text>
            </View>
          ))}
        </Card>
      )}
    </Screen>
  )
}

function Stat({ value, label }: { value: string; label: string }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  stats: { flexDirection: 'row', gap: 10 },
  stat: {
    flex: 1,
    backgroundColor: theme.surface,
    borderWidth: 1,
    borderColor: theme.line,
    borderRadius: theme.radius,
    padding: 14,
  },
  statValue: { color: theme.text, fontSize: 22, fontWeight: '800', letterSpacing: -0.4 },
  statLabel: {
    color: theme.dim,
    fontSize: 10,
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginTop: 4,
  },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 18,
    paddingVertical: 13,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  date: { color: theme.text, fontSize: 15, fontWeight: '600' },
  duration: { color: theme.accentHot, fontSize: 14, fontWeight: '700' },
})
