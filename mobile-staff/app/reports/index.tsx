import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { DateField, FormRow, FormSection, NotPermitted, Outcome } from '../../src/components/form'
import { Body, Button, Card, Eyebrow, Fact, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { openDocument } from '../../src/lib/documents'
import { money } from '../../src/lib/format'
import { saveError } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'

/**
 * The numbers, and a copy of them to take away.
 *
 * A phone is a poor place to read a forty-column table, so this shows the
 * headline figures and then hands the detail over as a CSV — which is what
 * anybody asking for "the report" actually wants to do with it. The export
 * endpoints already exist and are permission-checked server-side; this is a
 * second door to the same room, not a second implementation.
 */

const REPORTS: { key: string; label: string; description: string; permission: string }[] = [
  {
    key: 'attendance-members',
    label: 'Visits per member',
    description: 'Who came, and how often',
    permission: P.reportsView,
  },
  {
    key: 'attendance-daily',
    label: 'Attendance by day',
    description: 'How busy each day was',
    permission: P.reportsView,
  },
  {
    key: 'revenue-payments',
    label: 'Payments',
    description: 'Every payment taken',
    permission: P.reportsFinancial,
  },
  {
    key: 'revenue-daily',
    label: 'Takings by day',
    description: 'Revenue per day',
    permission: P.reportsFinancial,
  },
  {
    key: 'outstanding',
    label: 'Outstanding invoices',
    description: 'What is still owed',
    permission: P.reportsFinancial,
  },
  { key: 'expenses', label: 'Expenses', description: 'Everything spent', permission: P.expensesView },
  {
    key: 'expenses-by-category',
    label: 'Expenses by category',
    description: 'Where it went',
    permission: P.expensesView,
  },
  {
    key: 'profit-loss',
    label: 'Profit and loss',
    description: 'In, out, and what is left',
    permission: P.reportsFinancial,
  },
]

export default function Reports() {
  const { can } = useAuth()
  const router = useRouter()

  const monthStart = new Date()
  monthStart.setDate(1)

  const [from, setFrom] = useState(monthStart.toISOString().slice(0, 10))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [busy, setBusy] = useState<string | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const pnl = useQuery({
    queryKey: ['/reports/profit-loss', from, to],
    enabled: can(P.reportsFinancial),
    queryFn: async () =>
      (
        // `income`, not `revenue` - the endpoint separates gross takings from
        // refunds, and reading the wrong key here renders a confident zero.
        await api.get<{
          income: number
          gross_income: number
          refunds: number
          expenses: number
          profit: number
          margin_percent: number | null
        }>('/reports/profit-loss', { params: { from, to } })
      ).data,
  })

  if (!can(P.reportsView) && !can(P.reportsFinancial) && !can(P.expensesView)) {
    return (
      <Screen title="Reports" onBack={() => router.back()}>
        <NotPermitted what="see reports" />
      </Screen>
    )
  }

  const available = REPORTS.filter((r) => can(r.permission))

  const download = (key: string, label: string) => {
    setResult(null)
    setBusy(key)

    openDocument(
      `/reports/export/${key}?from=${from}&to=${to}`,
      `${key}-${from}-to-${to}.csv`,
      'text/csv',
    )
      .then(() => setResult({ ok: true, message: `${label} exported.` }))
      .catch((error) => setResult({ ok: false, message: saveError(error) }))
      .finally(() => setBusy(null))
  }

  return (
    <Screen title="Reports" subtitle="Pick a period, then take it away" onBack={() => router.back()}>
      <FormSection title="Period">
        <FormRow label="From">
          <DateField testID="from" value={from} onChange={setFrom} />
        </FormRow>
        <FormRow label="To">
          <DateField testID="to" value={to} onChange={setTo} />
        </FormRow>
      </FormSection>

      {can(P.reportsFinancial) ? (
        <Card>
          <Eyebrow>Over that period</Eyebrow>
          {pnl.isLoading ? (
            <Muted>Working it out…</Muted>
          ) : pnl.error ? (
            <Body style={{ color: theme.accentHot }}>{saveError(pnl.error)}</Body>
          ) : (
            <>
              <View style={styles.pair}>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.big, { color: theme.good }]}>{money(pnl.data?.income ?? 0)}</Text>
                  <Muted>came in</Muted>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.big, { color: theme.accentHot }]}>{money(pnl.data?.expenses ?? 0)}</Text>
                  <Muted>went out</Muted>
                </View>
              </View>
              <Fact label="Left over" value={money(pnl.data?.profit ?? 0)} />
              {pnl.data?.refunds ? <Fact label="Refunded" value={money(pnl.data.refunds)} /> : null}
              {pnl.data?.margin_percent != null ? (
                <Fact label="Margin" value={`${pnl.data.margin_percent}%`} />
              ) : null}
            </>
          )}
        </Card>
      ) : null}

      <View style={{ gap: 10 }}>
        <Eyebrow>Export</Eyebrow>
        {available.map((report) => (
          <Card key={report.key}>
            <Text style={styles.name}>{report.label}</Text>
            <Muted>{report.description}</Muted>
            <Button
              label="Export as CSV"
              variant="ghost"
              loading={busy === report.key}
              onPress={() => download(report.key, report.label)}
              style={{ marginTop: 12 }}
            />
          </Card>
        ))}
      </View>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>
        A CSV opens in Numbers, Excel or Sheets. The file is fetched with your own credentials, so it contains
        only what you are allowed to see.
      </Muted>
    </Screen>
  )
}

const styles = StyleSheet.create({
  pair: { flexDirection: 'row', gap: 14, marginTop: 4, marginBottom: 10 },
  big: { fontSize: 22, fontWeight: '800', letterSpacing: -0.4 },
  name: { color: theme.text, fontSize: 15, fontWeight: '600' },
})
