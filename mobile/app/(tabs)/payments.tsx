import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Card, Empty, ErrorNotice, Eyebrow, Loading, Muted } from '../../src/components/ui'
import { api, errorMessage } from '../../src/lib/api'
import { formatDate, money } from '../../src/lib/format'
import { openReceipt } from '../../src/lib/receipt'
import { theme } from '../../src/lib/theme'
import type { Paginated, PaymentRow } from '../../src/lib/types'

export default function Payments() {
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['payments'],
    queryFn: async () => (await api.get<Paginated<PaymentRow>>('/member/payments')).data,
  })

  if (isLoading) {
    return (
      <Screen title="Payments" scroll={false}>
        <Loading />
      </Screen>
    )
  }

  if (error) {
    return (
      <Screen title="Payments" scroll={false}>
        <ErrorNotice message={errorMessage(error)} onRetry={() => void refetch()} />
      </Screen>
    )
  }

  const payments = data?.data ?? []

  return (
    <Screen
      title="Payments"
      subtitle={data?.meta ? `${data.meta.total} receipt${data.meta.total === 1 ? '' : 's'}` : undefined}
      onRefresh={() => void refetch()}
      refreshing={isRefetching}
    >
      {payments.length === 0 ? (
        <Card>
          <Empty title="Nothing yet" description="Your receipts appear here after you pay." />
        </Card>
      ) : (
        <Card style={{ padding: 0 }}>
          <View style={{ padding: 18, paddingBottom: 6 }}>
            <Eyebrow>Your receipts</Eyebrow>
          </View>

          {payments.map((payment, index) => (
            <Receipt key={payment.id} payment={payment} last={index === payments.length - 1} />
          ))}
        </Card>
      )}

      <Muted style={{ textAlign: 'center' }}>
        Tap a receipt to open the PDF.
      </Muted>
    </Screen>
  )
}

function Receipt({ payment, last }: { payment: PaymentRow; last: boolean }) {
  const [busy, setBusy] = useState(false)

  const open = async () => {
    setBusy(true)

    try {
      await openReceipt(payment.id, payment.receipt_number)
    } catch (error) {
      Alert.alert('Could not open the receipt', errorMessage(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Pressable
      onPress={() => void open()}
      disabled={busy}
      accessibilityRole="button"
      accessibilityLabel={`Open receipt ${payment.receipt_number}`}
      style={({ pressed }) => [styles.row, last && styles.rowLast, pressed && styles.rowPressed]}
    >
      <View style={{ flex: 1 }}>
        <Text style={styles.receiptNumber}>{payment.receipt_number}</Text>
        <Muted>
          {formatDate(payment.paid_at)}
          {payment.method ? ` · ${payment.method}` : ''}
        </Muted>
      </View>

      {busy ? (
        <ActivityIndicator color={theme.accent} />
      ) : (
        <Text style={[styles.amount, payment.is_refund && { color: theme.bad }]}>
          {money(payment.amount)}
        </Text>
      )}
    </Pressable>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 18,
    paddingVertical: 14,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  rowPressed: { backgroundColor: theme.surface2 },
  receiptNumber: { color: theme.text, fontSize: 15, fontWeight: '600' },
  amount: { color: theme.text, fontSize: 15, fontWeight: '700' },
})
