import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Button, Card, Eyebrow, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { money } from '../../src/lib/format'
import { theme } from '../../src/lib/theme'
import type { DashboardSummary } from '../../src/lib/types'

/**
 * Money, as a shift sees it.
 *
 * Not a ledger — the admin panel is better at that. This is the two numbers
 * somebody standing at the desk is asked for, and the two things they do about
 * them: take a payment, write down what was spent.
 */
export default function Money() {
  const { can } = useAuth()
  const router = useRouter()

  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: async () => (await api.get<DashboardSummary>('/dashboard/summary')).data,
    enabled: can(P.paymentsView),
  })

  const data = summary.data

  return (
    <Screen
      title="Money"
      subtitle="Takings, what is owed, and what went out"
      onRefresh={() => void summary.refetch()}
      refreshing={summary.isRefetching}
    >
      {can(P.paymentsView) ? (
        <Card>
          <Eyebrow>Taken</Eyebrow>
          <View style={styles.pair}>
            <View style={{ flex: 1 }}>
              <Text style={styles.big}>{money(data?.revenue.today ?? 0)}</Text>
              <Muted>today</Muted>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.big}>{money(data?.revenue.this_month ?? 0)}</Text>
              <Muted>this month</Muted>
            </View>
          </View>

          <View style={styles.outstanding}>
            <Text style={{ color: theme.warn, fontSize: 15 }}>
              {money(data?.revenue.outstanding ?? 0)} outstanding
            </Text>
            <Muted>across all unpaid invoices</Muted>
          </View>
        </Card>
      ) : null}

      <View style={{ gap: 10 }}>
        {can(P.paymentsCreate) ? (
          <Button label="Take a payment" onPress={() => router.push('/payments/new')} />
        ) : null}
        {can(P.expensesManage) ? (
          <Button label="Record an expense" variant="ghost" onPress={() => router.push('/expenses/new')} />
        ) : null}
      </View>

      <View style={{ gap: 10 }}>
        <Eyebrow>Open</Eyebrow>
        <Card style={{ padding: 0 }}>
          {can(P.paymentsView) ? (
            <LinkRow label="Payments" hint="Receipts and refunds" onPress={() => router.push('/payments')} />
          ) : null}
          {can(P.invoicesView) ? (
            <LinkRow label="Invoices" hint="What has been billed" onPress={() => router.push('/invoices')} />
          ) : null}
          {can(P.expensesView) ? (
            <LinkRow label="Expenses" hint="What the gym spends" onPress={() => router.push('/expenses')} />
          ) : null}
          {can(P.plansView) ? (
            <LinkRow label="Plans" hint="What a membership costs" onPress={() => router.push('/plans')} />
          ) : null}
          {can(P.reportsView) ? (
            <LinkRow label="Reports" hint="Revenue, outstanding, P&L" onPress={() => router.push('/reports')} last />
          ) : null}
        </Card>
      </View>
    </Screen>
  )
}

function LinkRow({
  label,
  hint,
  onPress,
  last = false,
}: {
  label: string
  hint: string
  onPress: () => void
  last?: boolean
}) {
  return (
    <Button
      label={`${label}  ·  ${hint}`}
      variant="ghost"
      onPress={onPress}
      style={[styles.linkRow, last && { borderBottomWidth: 0 }]}
    />
  )
}

const styles = StyleSheet.create({
  pair: { flexDirection: 'row', gap: 14, marginTop: 4 },
  big: { color: theme.text, fontSize: 24, fontWeight: '800', letterSpacing: -0.5 },

  outstanding: {
    marginTop: 16,
    padding: 12,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.30)',
  },

  linkRow: {
    borderRadius: 0,
    borderWidth: 0,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
    backgroundColor: 'transparent',
  },
})
