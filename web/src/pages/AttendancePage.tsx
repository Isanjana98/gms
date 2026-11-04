import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Card,
  EmptyState,
  FilterBar,
  FileButton,
  Input,
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
import { date, duration, isoDaysAgo, time, todayIso } from '../lib/format'
import type { Attendance, Paginated } from '../lib/types'

/** SRS 16 - Attendance history with the filters the SRS asks for. */
export function AttendancePage() {
  const [from, setFrom] = useState(isoDaysAgo(6))
  const [to, setTo] = useState(todayIso())
  const [status, setStatus] = useState('')
  const [invalidOnly, setInvalidOnly] = useState(false)
  const [page, setPage] = useState(1)

  const { data, isLoading, error } = useQuery({
    queryKey: ['attendance', from, to, status, invalidOnly, page],
    queryFn: async () =>
      (
        await api.get<Paginated<Attendance>>('/attendance', {
          params: {
            from,
            to,
            status: status || undefined,
            invalid_only: invalidOnly ? 1 : undefined,
            page,
            per_page: 50,
          },
        })
      ).data,
  })

  const meta = data?.meta ?? data
  const lastPage = meta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Attendance"
        subtitle={meta?.total != null ? `${meta.total.toLocaleString()} visits in this range` : undefined}
        actions={
          <FileButton
            url={`/reports/attendance/export?from=${from}&to=${to}`}
            filename={`attendance-${from}-to-${to}.csv`}
            mode="download"
          >
            Export CSV
          </FileButton>
        }
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
            Status
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} className="mt-1">
              <option value="">All</option>
              <option value="OPEN">Still in</option>
              <option value="COMPLETED">Completed</option>
              <option value="AUTO_CLOSED">Auto-closed</option>
            </Select>
          </label>
          {/* py-2 on the label, not the box: the whole label is the tap
              target, and 20px of checkbox is a miss waiting to happen. */}
          <label className="flex items-center gap-2 py-2 text-sm text-ink-600">
            <input
              type="checkbox"
              checked={invalidOnly}
              onChange={(e) => { setInvalidOnly(e.target.checked); setPage(1) }}
              className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
            />
            Invalid memberships only
          </label>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState title="No attendance in this range" />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(visit) => visit.id}
              card={(visit) => (
                <RecordCard
                  title={visit.member?.full_name ?? '—'}
                  subtitle={visit.member?.membership_number}
                  to={visit.member_id ? `/members/${visit.member_id}` : undefined}
                  badge={
                    <>
                      {!visit.membership_valid && <Badge tone="danger">invalid</Badge>}
                      <StatusBadge status={visit.status} />
                    </>
                  }
                  facts={[
                    { label: 'Date', value: date(visit.attendance_date) },
                    { label: 'Duration', value: duration(visit.duration_minutes) },
                    { label: 'In', value: time(visit.check_in_time) },
                    { label: 'Out', value: time(visit.check_out_time) },
                  ]}
                  note={visit.check_in_device?.name ?? visit.attendance_type.toLowerCase()}
                />
              )}
              table={
                <Table>
              <thead>
                <tr>
                  <Th>Member</Th>
                  <Th>Date</Th>
                  <Th>In</Th>
                  <Th>Out</Th>
                  <Th align="right">Duration</Th>
                  <Th>Device</Th>
                  <Th align="right">Status</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {data.data.map((visit) => (
                  <tr key={visit.id} className="hover:bg-ink-50">
                    <Td>
                      <MemberCell member={visit.member} />
                    </Td>
                    <Td>{date(visit.attendance_date)}</Td>
                    <Td>{time(visit.check_in_time)}</Td>
                    <Td>{time(visit.check_out_time)}</Td>
                    <Td align="right">{duration(visit.duration_minutes)}</Td>
                    <Td>
                      <span className="text-xs text-ink-500">
                        {visit.check_in_device?.name ?? visit.attendance_type.toLowerCase()}
                      </span>
                    </Td>
                    <Td align="right">
                      <div className="flex justify-end gap-1.5">
                        {!visit.membership_valid && <Badge tone="danger">invalid</Badge>}
                        <StatusBadge status={visit.status} />
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
