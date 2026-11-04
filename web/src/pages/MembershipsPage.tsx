import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Alert,
  Card,
  EmptyState,
  FilterBar,
  LoadingBlock,
  MemberCell,
  PageHeader,
  Select,
  StatusBadge,
  Table,
  Td,
  Th,
} from '../components/ui'
import { Pager, RecordCard, RecordList } from '../components/RecordList'
import { api, errorMessage } from '../lib/api'
import { date, expiryLabel, money } from '../lib/format'
import type { Membership, Paginated } from '../lib/types'

/** SRS 5 / 6 - Memberships across all members. */
export function MembershipsPage() {
  const [params, setParams] = useSearchParams()
  const [page, setPage] = useState(1)

  const status = params.get('status') ?? ''
  const paymentStatus = params.get('payment_status') ?? ''

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
    setPage(1)
  }

  const { data, isLoading, error } = useQuery({
    queryKey: ['memberships', status, paymentStatus, page],
    queryFn: async () =>
      (
        await api.get<Paginated<Membership>>('/memberships', {
          params: {
            status: status || undefined,
            payment_status: paymentStatus || undefined,
            page,
            per_page: 25,
          },
        })
      ).data,
  })

  const meta = data?.meta ?? data
  const lastPage = meta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Memberships"
        subtitle={meta?.total != null ? `${meta.total.toLocaleString()} memberships` : undefined}
      />

      <Card padded={false}>
        <FilterBar>
          <Select value={status} onChange={(e) => setFilter('status', e.target.value)} className="max-w-[12rem]">
            <option value="">All statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="PENDING">Pending</option>
            <option value="EXPIRED">Expired</option>
            <option value="SUSPENDED">Suspended</option>
            <option value="CANCELLED">Cancelled</option>
          </Select>
          <Select
            value={paymentStatus}
            onChange={(e) => setFilter('payment_status', e.target.value)}
            className="max-w-[12rem]"
          >
            <option value="">Any payment status</option>
            <option value="UNPAID">Unpaid</option>
            <option value="PARTIAL">Partially paid</option>
            <option value="PAID">Paid</option>
          </Select>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState title="No memberships match these filters" />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(membership) => membership.id}
              card={(membership) => (
                <RecordCard
                  title={membership.member?.full_name ?? membership.reference}
                  subtitle={`${membership.reference} · ${membership.plan_name}`}
                  to={membership.member_id ? `/members/${membership.member_id}` : undefined}
                  badge={
                    <>
                      <StatusBadge status={membership.status} />
                      <StatusBadge status={membership.payment_status} />
                    </>
                  }
                  facts={[
                    { label: 'Starts', value: date(membership.start_date) },
                    { label: 'Ends', value: date(membership.end_date) },
                    { label: 'Value', value: money(membership.final_amount) },
                    {
                      label: 'Balance',
                      value:
                        membership.balance > 0 ? (
                          <span className="font-medium text-red-600">{money(membership.balance)}</span>
                        ) : (
                          '—'
                        ),
                    },
                  ]}
                  note={membership.status === 'ACTIVE' ? expiryLabel(membership.days_remaining) : undefined}
                />
              )}
              table={
                <Table>
              <thead>
                <tr>
                  <Th>Member</Th>
                  <Th>Reference</Th>
                  <Th>Plan</Th>
                  <Th>Period</Th>
                  <Th align="right">Value</Th>
                  <Th align="right">Balance</Th>
                  <Th align="right">Status</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {data.data.map((membership) => (
                  <tr key={membership.id} className="hover:bg-ink-50">
                    <Td>
                      <MemberCell member={membership.member} />
                    </Td>
                    <Td>
                      <span className="text-sm font-medium">{membership.reference}</span>
                    </Td>
                    <Td>{membership.plan_name}</Td>
                    <Td>
                      <span className="block text-sm">
                        {date(membership.start_date)} – {date(membership.end_date)}
                      </span>
                      {membership.status === 'ACTIVE' && (
                        <span className="block text-xs text-ink-500">{expiryLabel(membership.days_remaining)}</span>
                      )}
                    </Td>
                    <Td align="right">{money(membership.final_amount)}</Td>
                    <Td align="right">
                      <span className={membership.balance > 0 ? 'font-medium text-red-600' : 'text-ink-400'}>
                        {membership.balance > 0 ? money(membership.balance) : '—'}
                      </span>
                    </Td>
                    <Td align="right">
                      <div className="flex justify-end gap-1.5">
                        <StatusBadge status={membership.status} />
                        <StatusBadge status={membership.payment_status} />
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
    </>
  )
}
