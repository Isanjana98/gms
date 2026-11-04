import { useLocalSearchParams, useRouter } from 'expo-router'
import { useState } from 'react'
import { View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { Outcome } from '../../src/components/form'
import { Body, Button, Card, Fact, Heading, Loading, Muted, Pill } from '../../src/components/ui'
import { openDocument } from '../../src/lib/documents'
import { formatDate, money } from '../../src/lib/format'
import { saveError, useResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Invoice } from '../../src/lib/types'

/** One invoice, and the PDF of it. */
export default function InvoiceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const router = useRouter()
  const invoice = useResource<Invoice>('/invoices', id)
  const [failure, setFailure] = useState<string | null>(null)

  if (invoice.isLoading) {
    return (
      <Screen title="Invoice" onBack={() => router.back()}>
        <Loading />
      </Screen>
    )
  }

  if (invoice.error || !invoice.data) {
    return (
      <Screen title="Invoice" onBack={() => router.back()}>
        <Card>
          <Body style={{ color: theme.accentHot }}>{saveError(invoice.error)}</Body>
        </Card>
      </Screen>
    )
  }

  const row = invoice.data

  return (
    <Screen title={row.invoice_number} subtitle={row.member?.full_name ?? undefined} onBack={() => router.back()}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 14 }}>
          <View style={{ flex: 1 }}>
            <Heading>{money(row.total)}</Heading>
            <Muted>{row.balance > 0 ? `${money(row.balance)} still owed` : 'Settled'}</Muted>
          </View>
          <Pill text={row.status.toLowerCase()} tone={row.balance > 0 ? theme.warn : theme.good} />
        </View>

        <Fact label="Issued" value={formatDate(row.issue_date)} />
        <Fact label="Due" value={formatDate(row.due_date)} />
        <Fact label="Subtotal" value={money(row.sub_total)} />
        <Fact label="Discount" value={money(row.discount)} />
        <Fact label="Tax" value={money(row.tax)} />
        <Fact label="Paid" value={money(row.paid_amount)} />
        {row.notes ? <Fact label="Notes" value={row.notes} /> : null}
      </Card>

      <Button
        label="Open the invoice"
        variant="ghost"
        onPress={() => {
          setFailure(null)
          openDocument(`/invoices/${row.id}/pdf`, `invoice-${row.invoice_number}.pdf`).catch((error) =>
            setFailure(saveError(error)),
          )
        }}
      />

      {failure ? <Outcome ok={false} message={failure} /> : null}
    </Screen>
  )
}
