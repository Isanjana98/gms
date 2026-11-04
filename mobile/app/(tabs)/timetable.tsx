import { useQuery } from '@tanstack/react-query'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Card, Empty, ErrorNotice, Loading, Muted } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { theme } from '../../src/lib/theme'
import type { TimetableEntry } from '../../src/lib/types'

interface TimetableResponse {
  data: Record<string, TimetableEntry[]>
  days: Record<string, string>
}

/**
 * The published class timetable.
 *
 * Display only — the same rows the website shows. There is nothing to book:
 * class booking is a separate feature that is not built, and a screen that
 * looked bookable would be worse than one that plainly is not.
 */
export default function Timetable() {
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['timetable'],
    queryFn: async () => (await api.get<TimetableResponse>('/member/timetable')).data,
  })

  if (isLoading) {
    return (
      <Screen title="Classes" scroll={false}>
        <Loading />
      </Screen>
    )
  }

  if (error) {
    return (
      <Screen title="Classes" scroll={false}>
        <ErrorNotice message={errorMessage(error)} onRetry={() => void refetch()} />
      </Screen>
    )
  }

  const byDay = data?.data ?? {}
  const days = data?.days ?? {}
  // Monday first, matching how a timetable is read on a wall.
  const ordered = Object.keys(days).sort((a, b) => Number(a) - Number(b))
  const hasAny = ordered.some((day) => (byDay[day]?.length ?? 0) > 0)

  // Highlights today, which is the row a member is usually looking for.
  const todayIso = ((new Date().getDay() + 6) % 7) + 1

  return (
    <Screen
      title="Classes"
      subtitle="Turn up a few minutes early — nothing to book"
      onRefresh={() => void refetch()}
      refreshing={isRefetching}
    >
      {!hasAny ? (
        <Card>
          <Empty
            title="No classes published"
            description="Ask at reception for this week's timetable."
          />
        </Card>
      ) : (
        ordered.map((day) => {
          const classes = byDay[day] ?? []
          if (classes.length === 0) return null

          const isToday = Number(day) === todayIso

          return (
            <Card key={day} style={[{ padding: 0 }, isToday && styles.today]}>
              <View style={styles.dayHeader}>
                <Text style={[styles.dayName, isToday && { color: theme.accentHot }]}>
                  {days[day]}
                </Text>
                {isToday ? <Text style={styles.todayTag}>Today</Text> : null}
              </View>

              {classes.map((entry, index) => (
                <View
                  key={entry.id}
                  style={[styles.slot, index === classes.length - 1 && styles.slotLast]}
                >
                  <Text style={styles.time}>{entry.time_label}</Text>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.className}>{entry.class_name}</Text>
                    {entry.instructor || entry.room ? (
                      <Muted>
                        {[entry.instructor, entry.room].filter(Boolean).join(' · ')}
                      </Muted>
                    ) : null}
                  </View>
                </View>
              ))}
            </Card>
          )
        })
      )}
    </Screen>
  )
}

const styles = StyleSheet.create({
  today: { borderColor: theme.accent },

  dayHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 18,
    paddingTop: 16,
    paddingBottom: 10,
  },
  dayName: {
    color: theme.text,
    fontSize: 15,
    fontWeight: '800',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  todayTag: {
    color: theme.accentHot,
    fontSize: 10,
    fontWeight: '700',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },

  slot: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    paddingHorizontal: 18,
    paddingVertical: 12,
    borderTopWidth: 1,
    borderTopColor: theme.line,
  },
  slotLast: {},
  time: {
    color: theme.accentHot,
    fontSize: 13,
    fontWeight: '700',
    // Wide enough for "6:30pm – 7:30pm" on one line; 96 wrapped it.
    width: 112,
  },
  className: { color: theme.text, fontSize: 15, fontWeight: '600' },
})
