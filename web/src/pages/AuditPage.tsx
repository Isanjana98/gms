import { useQuery } from '@tanstack/react-query'
import { Fragment, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  FilterBar,
  Input,
  LoadingBlock,
  PageHeader,
  Select,
  Table,
  Td,
  Th,
} from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { dateTime, isoDaysAgo, todayIso } from '../lib/format'
import type { Paginated } from '../lib/types'

interface AuditEntry {
  id: number
  user_name: string | null
  user_role: string | null
  event: string
  record_type: string | null
  record_id: number | null
  description: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

const EVENT_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  created: 'success',
  updated: 'info',
  deleted: 'danger',
  force_deleted: 'danger',
  restored: 'warning',
  login: 'neutral',
  logout: 'neutral',
  password_changed: 'warning',
}

/** SRS 25 - Reading the audit trail. */
export function AuditPage() {
  const [from, setFrom] = useState(isoDaysAgo(6))
  const [to, setTo] = useState(todayIso())
  const [event, setEvent] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState<number | null>(null)

  const events = useQuery({
    queryKey: ['audit-events'],
    queryFn: async () => (await api.get<{ events: string[] }>('/audit-logs/events')).data.events,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['audit', from, to, event, search, page],
    queryFn: async () =>
      (
        await api.get<Paginated<AuditEntry>>('/audit-logs', {
          params: { from, to, event: event || undefined, search: search || undefined, page, per_page: 50 },
        })
      ).data,
  })

  const meta = data?.meta ?? data
  const lastPage = meta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Audit log"
        subtitle="Every change, who made it and when"
      />

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
            Event
            <Select value={event} onChange={(e) => { setEvent(e.target.value); setPage(1) }} className="mt-1">
              <option value="">All events</option>
              {events.data?.map((name) => (
                <option key={name} value={name}>
                  {name.replace(/_/g, ' ')}
                </option>
              ))}
            </Select>
          </label>
          <label className="flex-1 text-xs font-medium text-ink-600">
            Search
            <Input
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
              placeholder="Description contains…"
              className="mt-1 max-w-xs"
            />
          </label>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState title="No audit entries in this range" />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>When</Th>
                  <Th>Who</Th>
                  <Th>Event</Th>
                  <Th>What</Th>
                  <Th align="right" />
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {data.data.map((entry) => (
                  // The key belongs on the fragment: it, not the rows inside
                  // it, is what this map returns.
                  <Fragment key={entry.id}>
                    <tr className="hover:bg-ink-50">
                      <Td>
                        <span className="text-sm">{dateTime(entry.created_at)}</span>
                      </Td>
                      <Td>
                        <span className="block text-sm">{entry.user_name ?? 'System'}</span>
                        <span className="block text-xs text-ink-500">{entry.user_role ?? ''}</span>
                      </Td>
                      <Td>
                        <Badge tone={EVENT_TONE[entry.event] ?? 'neutral'}>{entry.event.replace(/_/g, ' ')}</Badge>
                      </Td>
                      <Td>
                        <span className="text-sm">{entry.description ?? '—'}</span>
                        {entry.record_type && (
                          <span className="block text-xs text-ink-500">
                            {entry.record_type} #{entry.record_id}
                          </span>
                        )}
                      </Td>
                      <Td align="right">
                        {(entry.old_values || entry.new_values) && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setExpanded(expanded === entry.id ? null : entry.id)}
                          >
                            {expanded === entry.id ? 'Hide' : 'Details'}
                          </Button>
                        )}
                      </Td>
                    </tr>
                    {expanded === entry.id && (
                      <tr className="bg-ink-50">
                        <td colSpan={5} className="px-4 py-3">
                          <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                              <p className="mb-1 text-xs font-semibold text-ink-600">Before</p>
                              <pre className="max-h-48 overflow-auto rounded bg-white p-2.5 text-xs text-ink-700 ring-1 ring-ink-200">
                                {JSON.stringify(entry.old_values ?? {}, null, 2)}
                              </pre>
                            </div>
                            <div>
                              <p className="mb-1 text-xs font-semibold text-ink-600">After</p>
                              <pre className="max-h-48 overflow-auto rounded bg-white p-2.5 text-xs text-ink-700 ring-1 ring-ink-200">
                                {JSON.stringify(entry.new_values ?? {}, null, 2)}
                              </pre>
                            </div>
                          </div>
                          {entry.ip_address && (
                            <p className="mt-2 text-xs text-ink-500">From {entry.ip_address}</p>
                          )}
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </Table>

            {lastPage > 1 && (
              <div className="flex items-center justify-between border-t border-ink-200 px-5 py-3 text-sm">
                <span className="text-ink-500">
                  Page {meta?.current_page} of {lastPage}
                </span>
                <div className="flex gap-2">
                  <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                    Previous
                  </Button>
                  <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
                    Next
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>
    </>
  )
}
