import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  Alert,
  Badge,
  Card,
  EmptyState,
  LoadingBlock,
  MemberCell,
  PageHeader,
  StatusBadge,
  Table,
  Td,
  Th,
} from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, expiryLabel, money, number, time } from '../lib/format'
import type { DashboardPayload } from '../lib/types'

function Stat({
  label,
  value,
  hint,
  tone = 'neutral',
  to,
}: {
  label: string
  value: string
  hint?: string
  tone?: 'neutral' | 'success' | 'warning' | 'danger'
  to?: string
}) {
  const toneClass = {
    neutral: 'text-ink-900',
    success: 'text-emerald-700',
    warning: 'text-amber-700',
    danger: 'text-red-700',
  }[tone]

  const body = (
    <div className="rounded-xl border border-ink-200 bg-white p-4 shadow-sm transition hover:border-ink-300">
      <p className="text-xs font-medium uppercase tracking-wide text-ink-500">{label}</p>
      <p className={`mt-1.5 text-2xl font-semibold tabular ${toneClass}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-ink-500">{hint}</p>}
    </div>
  )

  return to ? <Link to={to}>{body}</Link> : body
}

export function DashboardPage() {
  const { user, can } = useAuth()

  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<DashboardPayload>('/dashboard')).data,
    // The floor numbers move all day; a minute keeps them honest without
    // making the page chatty.
    refetchInterval: 60_000,
  })

  if (isLoading) return <LoadingBlock label="Loading dashboard…" />
  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (!data) return null

  const { summary, attendance_trend, recent_check_ins, expiring_soon, top_members } = data
  const firstName = user?.name?.split(' ')[0] ?? 'there'

  return (
    <>
      <PageHeader
        title={`Good ${new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 17 ? 'afternoon' : 'evening'}, ${firstName}`}
        subtitle={`${date(new Date())} · ${summary.attendance.currently_in} member${
          summary.attendance.currently_in === 1 ? '' : 's'
        } in the gym right now`}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat
          label="Active members"
          value={number(summary.members.active)}
          hint={`${number(summary.members.total)} on file`}
          to="/members?status=ACTIVE"
        />
        <Stat
          label="Today's attendance"
          value={number(summary.attendance.today)}
          hint={`${number(summary.attendance.yesterday)} yesterday`}
          to="/attendance"
        />
        {can(P.reportsFinancial) ? (
          <Stat label="Today's revenue" value={money(summary.revenue.today)} hint={`${money(summary.revenue.this_month)} this month`} to="/payments" />
        ) : (
          <Stat label="New members this month" value={number(summary.members.new_this_month)} />
        )}
        <Stat
          label="Expiring soon"
          value={number(summary.memberships.expiring_soon)}
          hint="within 30 days"
          tone={summary.memberships.expiring_soon > 0 ? 'warning' : 'neutral'}
          to="/members?expiring_soon=1"
        />
      </div>

      {summary.memberships.pending_payment > 0 && can(P.paymentsView) && (
        <div className="mt-4">
          <Alert tone="warning">
            {summary.memberships.pending_payment} membership
            {summary.memberships.pending_payment === 1 ? ' is' : 's are'} awaiting payment and will not admit the member
            until settled.{' '}
            <Link to="/memberships?payment_status=UNPAID" className="font-medium underline">
              Review them
            </Link>
            .
          </Alert>
        </div>
      )}

      {/* items-start so each card is its own natural height. Stretching them
          to match leaves a band of empty card under whichever is shorter. */}
      <div className="mt-6 grid gap-6 lg:grid-cols-3 lg:items-start">
        <Card title="Attendance trend" subtitle="Visits per day, last 30 days" className="lg:col-span-2">
          {attendance_trend.length === 0 ? (
            <EmptyState title="No attendance recorded yet" />
          ) : (
            // Taller than it needs to be at the minimum, and allowed to grow:
            // the grid row is sized by the check-in list beside it, and a
            // fixed-height chart left a band of empty card below it.
            <div className="h-64 lg:h-[26rem]">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={attendance_trend} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
                  <defs>
                    <linearGradient id="visits" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#3182f6" stopOpacity={0.35} />
                      <stop offset="100%" stopColor="#3182f6" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#eef0f4" vertical={false} />
                  <XAxis
                    dataKey="date"
                    tickFormatter={(value: string) => value.slice(8)}
                    tick={{ fontSize: 11, fill: '#74809a' }}
                    axisLine={false}
                    tickLine={false}
                    minTickGap={16}
                  />
                  <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: '#74809a' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <Tooltip
                    labelFormatter={(value) => date(String(value))}
                    formatter={(value) => [`${Number(value)} visits`, '']}
                    contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                  />
                  <Area type="monotone" dataKey="count" stroke="#3182f6" strokeWidth={2} fill="url(#visits)" />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          )}
        </Card>

        <Card title="Recent check-ins" padded={false}>
          {recent_check_ins.length === 0 ? (
            <EmptyState title="Nobody has checked in yet today" />
          ) : (
            // Uncapped: the cards no longer stretch to match each other, so
            // all ten can show. Hiding the last three behind a scrollbar that
            // gives no sign it exists is worse than a slightly taller card.
            <ul className="divide-y divide-ink-100">
              {recent_check_ins.map((entry) => (
                <li key={entry.id}>
                  {/* The whole row is the link, not just the name: a 20px
                      line of text is not a tap target on a phone. */}
                  <Link
                    to={`/members/${entry.member_id}`}
                    className="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-ink-50"
                  >
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-ink-900">{entry.member_name}</span>
                      <span className="block text-xs text-ink-500">
                        {time(entry.check_in_time)}
                        {entry.check_out_time ? ` – ${time(entry.check_out_time)}` : ''}
                      </span>
                    </span>
                    {entry.membership_valid ? (
                      <StatusBadge status={entry.status} />
                    ) : (
                      <Badge tone="danger">invalid</Badge>
                    )}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2 lg:items-start">
        <Card
          title="Memberships expiring soon"
          subtitle="Worth a renewal call"
          padded={false}
          actions={
            <Link
              to="/memberships"
              className="-my-1 rounded px-2 py-2 text-xs font-medium text-brand-700 hover:bg-brand-50"
            >
              View all
            </Link>
          }
        >
          {expiring_soon.length === 0 ? (
            <EmptyState title="Nothing expiring in the next 30 days" />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Member</Th>
                  <Th>Plan</Th>
                  <Th align="right">Expires</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {expiring_soon.map((row) => (
                  <tr key={row.membership_id}>
                    <Td>
                      <MemberCell
                        member={{
                          id: row.member_id,
                          full_name: row.member_name,
                          membership_number: row.membership_number,
                        }}
                      />
                    </Td>
                    <Td>{row.plan_name}</Td>
                    <Td align="right">
                      <span className="block">{date(row.end_date)}</span>
                      <span className={`block text-xs ${row.days_remaining <= 3 ? 'text-red-600' : 'text-ink-500'}`}>
                        {expiryLabel(row.days_remaining)}
                      </span>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>

        <Card title="Most active members" subtitle="Last 30 days" padded={false}>
          {top_members.length === 0 ? (
            <EmptyState title="No visits recorded in the last 30 days" />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Member</Th>
                  <Th align="right">Visits</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {top_members.map((row) => (
                  <tr key={row.member_id}>
                    <Td>
                      <MemberCell
                        member={{
                          id: row.member_id,
                          full_name: row.member_name,
                          membership_number: row.membership_number,
                        }}
                      />
                    </Td>
                    <Td align="right">
                      <span className="font-semibold">{row.visits}</span>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      </div>
    </>
  )
}
