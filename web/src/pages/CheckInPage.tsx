import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Avatar, Badge, Button, Card, EmptyState, Input, PageHeader, Spinner } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, duration, expiryLabel, time } from '../lib/format'
import type { Attendance, EntryValidation, MemberSummary, Paginated } from '../lib/types'

interface ScanResult {
  ok: boolean
  message: string
  result: string
  member?: string
}

/**
 * SRS 9-12 - The reception desk.
 *
 * Fingerprint scans reach the database through the biometric agent, not
 * through this screen; what this gives the desk is the live picture of who is
 * in, and a way to sign someone in by hand when their finger will not read.
 *
 * It is built around one behaviour: search, look at the verdict, act. The
 * validity check runs before anything is recorded, so staff can see "expired
 * three days ago" and start a renewal conversation instead of recording a
 * refused entry and then explaining it.
 */
export function CheckInPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const searchRef = useRef<HTMLInputElement>(null)

  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [selected, setSelected] = useState<MemberSummary | null>(null)
  const [result, setResult] = useState<ScanResult | null>(null)

  // Reception types fast; firing a request per keystroke would put a query on
  // the database for every letter of a name.
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term.trim()), 250)
    return () => clearTimeout(timer)
  }, [term])

  useEffect(() => {
    searchRef.current?.focus()
  }, [])

  const { data: results, isFetching } = useQuery({
    queryKey: ['checkin-search', debounced],
    queryFn: async () =>
      (await api.get<Paginated<MemberSummary>>('/members', { params: { search: debounced, per_page: 8 } })).data,
    enabled: debounced.length >= 2,
  })

  const { data: validation, isFetching: validating } = useQuery({
    queryKey: ['validate-entry', selected?.id],
    queryFn: async () => (await api.get<EntryValidation>(`/attendance/validate/${selected!.id}`)).data,
    enabled: !!selected,
  })

  const { data: live } = useQuery({
    queryKey: ['attendance-live'],
    queryFn: async () => (await api.get<{ count: number; data: Attendance[] }>('/attendance/live')).data,
    refetchInterval: 30_000,
  })

  const record = useMutation({
    mutationFn: async ({ memberId, direction }: { memberId: number; direction: 'IN' | 'OUT' }) =>
      (await api.post('/attendance', { member_id: memberId, direction })).data,
    onSuccess: (data: { message: string; accepted: boolean; result: string }) => {
      setResult({ ok: data.accepted, message: data.message, result: data.result, member: selected?.full_name })
      queryClient.invalidateQueries({ queryKey: ['attendance-live'] })
      queryClient.invalidateQueries({ queryKey: ['validate-entry'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })

      // Clear the desk for the next person in the queue.
      setSelected(null)
      setTerm('')
      searchRef.current?.focus()
    },
    onError: (error) => {
      setResult({ ok: false, message: errorMessage(error), result: 'ERROR' })
    },
  })

  // The banner is a transient acknowledgement, not a permanent state.
  useEffect(() => {
    if (!result) return
    const timer = setTimeout(() => setResult(null), 8000)
    return () => clearTimeout(timer)
  }, [result])

  // "In the gym for 45m" has to keep counting while the screen sits open at
  // the desk. Held in state and ticked on a timer rather than read from the
  // clock during render, which would neither update nor be reproducible.
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60_000)
    return () => clearInterval(timer)
  }, [])

  const canManage = can(P.attendanceManage)

  return (
    <>
      <PageHeader
        title="Check-in desk"
        subtitle="Search a member to check their membership, or record entry by hand"
        actions={
          <Link to="/attendance">
            <Button variant="secondary" size="sm">
              Attendance history
            </Button>
          </Link>
        }
      />

      {result && (
        <div className="mb-5">
          <Alert tone={result.ok ? 'success' : result.result === 'DUPLICATE' ? 'warning' : 'danger'}>
            <span className="font-medium">{result.message}</span>
          </Alert>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <Card title="Find a member">
            <div className="relative">
              <Input
                ref={searchRef}
                value={term}
                onChange={(e) => {
                  setTerm(e.target.value)
                  setSelected(null)
                }}
                placeholder="Name, membership number, phone or NIC…"
                className="py-3 text-base"
                autoComplete="off"
              />
              {isFetching && (
                <Spinner className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
              )}
            </div>

            {debounced.length >= 2 && !selected && (
              <div className="mt-3 divide-y divide-ink-100 rounded-lg border border-ink-200">
                {results?.data?.length ? (
                  results.data.map((member) => (
                    <button
                      key={member.id}
                      type="button"
                      onClick={() => setSelected(member)}
                      className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition hover:bg-ink-50"
                    >
                      <Avatar name={member.full_name} url={member.photo_url} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-ink-900">{member.full_name}</span>
                        <span className="block text-xs text-ink-500">
                          {member.membership_number}
                          {member.telephone ? ` · ${member.telephone}` : ''}
                        </span>
                      </span>
                      <Badge tone={member.status === 'ACTIVE' ? 'success' : 'danger'}>
                        {member.status?.toLowerCase()}
                      </Badge>
                    </button>
                  ))
                ) : (
                  <p className="px-3 py-6 text-center text-sm text-ink-500">
                    No member matches “{debounced}”.
                  </p>
                )}
              </div>
            )}

            {selected && (
              <div className="mt-4 rounded-lg border border-ink-200 p-4">
                <div className="flex items-start gap-4">
                  <Avatar name={selected.full_name} url={selected.photo_url} size="lg" />
                  <div className="min-w-0 flex-1">
                    <Link to={`/members/${selected.id}`} className="text-base font-semibold hover:text-brand-700">
                      {selected.full_name}
                    </Link>
                    <p className="text-sm text-ink-500">{selected.membership_number}</p>

                    {validating ? (
                      <div className="mt-3 flex items-center gap-2 text-sm text-ink-500">
                        <Spinner className="h-4 w-4" /> Checking membership…
                      </div>
                    ) : validation ? (
                      <div className="mt-3 space-y-2">
                        <Alert tone={validation.valid ? 'success' : 'danger'}>{validation.message}</Alert>

                        {validation.membership && (
                          <p className="text-xs text-ink-500">
                            {validation.membership.plan_name} · until {date(validation.membership.end_date)} (
                            {expiryLabel(validation.membership.days_remaining)})
                          </p>
                        )}

                        {validation.currently_checked_in && (
                          <p className="text-xs text-amber-700">
                            Already checked in at {time(validation.checked_in_at)}.
                          </p>
                        )}
                      </div>
                    ) : null}
                  </div>
                </div>

                {canManage && (
                  <div className="mt-4 flex flex-wrap gap-2">
                    {/* Left enabled even when entry would be refused: whether
                        an invalid membership blocks the door is a per-branch
                        setting (SRS 10), so the server decides. It is styled
                        as a secondary action so it does not read as the
                        obvious next step when the verdict is a refusal. */}
                    <Button
                      size="lg"
                      variant={validation && !validation.valid ? 'secondary' : 'primary'}
                      loading={record.isPending}
                      disabled={validation?.currently_checked_in}
                      onClick={() => record.mutate({ memberId: selected.id, direction: 'IN' })}
                    >
                      {validation && !validation.valid ? 'Check in anyway' : 'Check in'}
                    </Button>
                    <Button
                      size="lg"
                      variant="secondary"
                      loading={record.isPending}
                      disabled={!validation?.currently_checked_in}
                      onClick={() => record.mutate({ memberId: selected.id, direction: 'OUT' })}
                    >
                      Check out
                    </Button>
                    <Button variant="ghost" size="lg" onClick={() => { setSelected(null); setTerm(''); searchRef.current?.focus() }}>
                      Cancel
                    </Button>
                  </div>
                )}

                {!validation?.valid && validation && can(P.membershipsRenew) && (
                  <p className="mt-3 text-xs text-ink-500">
                    Need to renew?{' '}
                    <Link to={`/members/${selected.id}`} className="font-medium text-brand-700 hover:underline">
                      Open their profile
                    </Link>
                  </p>
                )}
              </div>
            )}
          </Card>
        </div>

        <div className="lg:col-span-2">
          <Card
            title="In the gym now"
            subtitle={`${live?.count ?? 0} member${live?.count === 1 ? '' : 's'}`}
            padded={false}
          >
            {!live?.data?.length ? (
              <EmptyState title="Nobody is checked in" description="Members appear here as they scan in." />
            ) : (
              <ul className="max-h-[28rem] divide-y divide-ink-100 overflow-y-auto">
                {live.data.map((entry) => (
                  <li key={entry.id} className="flex items-center gap-3 px-4 py-2.5">
                    <Avatar name={entry.member?.full_name} url={entry.member?.photo_url} />
                    <div className="min-w-0 flex-1">
                      <Link
                        to={`/members/${entry.member_id}`}
                        className="block truncate text-sm font-medium hover:text-brand-700"
                      >
                        {entry.member?.full_name}
                      </Link>
                      <p className="text-xs text-ink-500">
                        since {time(entry.check_in_time)}
                        {entry.check_in_time &&
                          ` · ${duration(Math.max(0, Math.round((now - new Date(entry.check_in_time).getTime()) / 60000)))}`}
                      </p>
                    </div>
                    {!entry.membership_valid && <Badge tone="danger">invalid</Badge>}
                    {canManage && (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => record.mutate({ memberId: entry.member_id, direction: 'OUT' })}
                      >
                        Out
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </>
  )
}
