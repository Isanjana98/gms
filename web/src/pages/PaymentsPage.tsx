import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Field,
  FilterBar,
  FileButton,
  Input,
  LoadingBlock,
  MemberCell,
  Modal,
  PageHeader,
  Select,
  StatusBadge,
  Table,
  Td,
  Th,
} from '../components/ui'
import { useConfirm } from '../components/Confirm'
import { Pager, RecordCard, RecordList } from '../components/RecordList'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { dateTime, isoDaysAgo, money, todayIso } from '../lib/format'
import type { Paginated, Payment } from '../lib/types'

/** SRS 17 / 18 - Payments, receipts and refunds. */
export function PaymentsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [from, setFrom] = useState(isoDaysAgo(29))
  const [to, setTo] = useState(todayIso())
  const [method, setMethod] = useState('')
  const [page, setPage] = useState(1)
  const [refunding, setRefunding] = useState<Payment | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['payments', from, to, method, page],
    queryFn: async () =>
      (
        await api.get<Paginated<Payment>>('/payments', {
          params: { from, to, method: method || undefined, page, per_page: 25 },
        })
      ).data,
  })

  const meta = data?.meta ?? data
  const lastPage = meta?.last_page ?? 1

  const total = (data?.data ?? []).reduce((sum, payment) => sum + Number(payment.amount), 0)

  return (
    <>
      <PageHeader
        title="Payments"
        subtitle={meta?.total != null ? `${meta.total.toLocaleString()} entries in this range` : undefined}
      />

      {notice && (
        <div className="mb-5">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      <Card padded={false}>
        <FilterBar>
          <label className="text-xs font-medium text-ink-600">
            From
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1) }} className="mt-1" />
          </label>
          <label className="text-xs font-medium text-ink-600">
            To
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1) }} className="mt-1" />
          </label>
          <label className="text-xs font-medium text-ink-600">
            Method
            <Select value={method} onChange={(e) => { setMethod(e.target.value); setPage(1) }} className="mt-1">
              <option value="">All methods</option>
              <option value="CASH">Cash</option>
              <option value="CARD">Card</option>
              <option value="BANK_TRANSFER">Bank transfer</option>
              <option value="ONLINE">Online</option>
            </Select>
          </label>
          <div className="ml-auto pb-1 text-right">
            <p className="text-[11px] uppercase tracking-wide text-ink-500">This page</p>
            <p className="text-sm font-semibold tabular">{money(total)}</p>
          </div>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState title="No payments in this range" />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(payment) => payment.id}
              card={(payment) => (
                <RecordCard
                  title={payment.receipt_number}
                  subtitle={payment.member?.full_name}
                  to={payment.member_id ? `/members/${payment.member_id}` : undefined}
                  badge={
                    <span className={payment.is_refund ? 'font-semibold text-red-600' : 'font-semibold text-ink-900'}>
                      {money(payment.amount)}
                    </span>
                  }
                  facts={[
                    { label: 'Date', value: dateTime(payment.paid_at) },
                    { label: 'Method', value: payment.method_label },
                    { label: 'For', value: payment.payment_type_label },
                    { label: 'Status', value: <StatusBadge status={payment.status} /> },
                  ]}
                  actions={
                    <>
                      <FileButton
                        url={`/payments/${payment.id}/receipt`}
                        filename={`receipt-${payment.receipt_number}.pdf`}
                        onError={(text) => setNotice({ tone: 'danger', text })}
                      >
                        Receipt
                      </FileButton>
                      {can(P.paymentsRefund) && !payment.is_refund && payment.status !== 'REFUNDED' && (
                        <Button size="sm" variant="ghost" onClick={() => setRefunding(payment)}>
                          Refund
                        </Button>
                      )}
                    </>
                  }
                />
              )}
              table={
                <Table>
              <thead>
                <tr>
                  <Th>Receipt</Th>
                  <Th>Member</Th>
                  <Th>Date</Th>
                  <Th>For</Th>
                  <Th>Method</Th>
                  <Th align="right">Amount</Th>
                  <Th align="right">Actions</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {data.data.map((payment) => (
                  <tr key={payment.id} className="hover:bg-ink-50">
                    <Td>
                      <span className="font-medium">{payment.receipt_number}</span>
                      {payment.status === 'REFUNDED' && (
                        <span className="ml-1.5">
                          <StatusBadge status="REFUNDED" />
                        </span>
                      )}
                    </Td>
                    <Td>
                      <MemberCell member={payment.member} />
                    </Td>
                    <Td>{dateTime(payment.paid_at)}</Td>
                    <Td>{payment.payment_type_label}</Td>
                    <Td>
                      <span className="text-sm">{payment.method_label}</span>
                      {payment.reference && <span className="block text-xs text-ink-500">{payment.reference}</span>}
                    </Td>
                    <Td align="right">
                      <span className={payment.is_refund ? 'font-medium text-red-600' : 'font-medium'}>
                        {money(payment.amount)}
                      </span>
                    </Td>
                    <Td align="right">
                      <div className="flex justify-end gap-1.5">
                        <FileButton
                          url={`/payments/${payment.id}/receipt`}
                          filename={`receipt-${payment.receipt_number}.pdf`}
                          onError={(text) => setNotice({ tone: 'danger', text })}
                        >
                          Receipt
                        </FileButton>
                        {can(P.paymentsRefund) && !payment.is_refund && payment.status !== 'REFUNDED' && (
                          <Button size="sm" variant="ghost" onClick={() => setRefunding(payment)}>
                            Refund
                          </Button>
                        )}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
                </Table>
              }
            />

            <Pager page={meta?.current_page ?? page} lastPage={lastPage} onChange={setPage} />
          </>
        )}
      </Card>

      {refunding && (
        <RefundModal
          payment={refunding}
          onClose={() => setRefunding(null)}
          onDone={(message) => {
            setRefunding(null)
            setNotice({ tone: 'success', text: message })
            queryClient.invalidateQueries({ queryKey: ['payments'] })
          }}
        />
      )}
    </>
  )
}

function RefundModal({
  payment,
  onClose,
  onDone,
}: {
  payment: Payment
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [amount, setAmount] = useState(String(payment.amount))
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const confirm = useConfirm()

  const refund = useMutation({
    mutationFn: async () =>
      (await api.post(`/payments/${payment.id}/refund`, { amount: Number(amount), reason: reason || undefined })).data,
    onSuccess: (data: { message: string }) => onDone(data.message),
    onError: (err) => setError(errorMessage(err)),
  })

  /**
   * A second step before money goes back.
   *
   * The form itself can be filled in and submitted without ever stating the
   * amount plainly — this does, next to the member's name, so an extra zero
   * is caught before the till is short.
   */
  const start = async () => {
    const value = Number(amount)

    if (!value || value <= 0) {
      setError('Enter an amount greater than zero.')
      return
    }

    const ok = await confirm({
      title: 'Record this refund?',
      tone: 'danger',
      confirmLabel: `Refund ${money(value)}`,
      message: (
        <>
          <strong>{money(value)}</strong> will be recorded as returned to {payment.member?.full_name ?? 'this member'}.
        </>
      ),
      detail: (
        <>
          The original receipt {payment.receipt_number} is kept exactly as issued. The refund is added as its own entry
          in the ledger, and cannot be removed.
        </>
      ),
    })

    if (ok) refund.mutate()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Refund receipt ${payment.receipt_number}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="danger" loading={refund.isPending} onClick={start}>
            Record refund
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="rounded-lg bg-ink-50 p-3 text-sm">
          <div className="flex justify-between">
            <span className="text-ink-500">{payment.member?.full_name}</span>
            <span className="tabular font-medium">{money(payment.amount)}</span>
          </div>
          <p className="mt-1 text-xs text-ink-500">{dateTime(payment.paid_at)} · {payment.method_label}</p>
        </div>

        <Field label="Refund amount" required hint="Can be less than the original for a partial refund.">
          <Input type="number" step="0.01" min="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </Field>

        <Field label="Reason">
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this being refunded?" />
        </Field>

        <p className="text-xs text-ink-500">
          The original receipt is kept exactly as issued. The refund is recorded as its own entry in the ledger.
        </p>
      </div>
    </Modal>
  )
}
