import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  Alert,
  Card,
  EmptyState,
  FileButton,
  Input,
  LoadingBlock,
  PageHeader,
  Table,
  Td,
  Th,
  cx,
} from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, duration, isoDaysAgo, money, number, todayIso } from '../lib/format'

// A categorical palette that stays distinguishable in greyscale, since these
// reports get printed.
const SERIES_COLOURS = ['#3182f6', '#0f9d76', '#c2410c', '#7c3aed', '#b45309']

type Tab = 'attendance' | 'revenue' | 'expenses' | 'profit' | 'outstanding'

/**
 * Every tab can be exported.
 *
 * Two entries where the screen shows two different things — a chart of daily
 * figures above a table of detail — because exporting only one of them means
 * the other has to be copied off the screen by hand.
 */
const EXPORTS: Record<Tab, Array<{ report: string; label: string }>> = {
  attendance: [
    { report: 'attendance-members', label: 'Export members CSV' },
    { report: 'attendance-daily', label: 'Daily CSV' },
  ],
  revenue: [
    { report: 'revenue-payments', label: 'Export payments CSV' },
    { report: 'revenue-daily', label: 'Daily CSV' },
  ],
  expenses: [
    { report: 'expenses', label: 'Export expenses CSV' },
    { report: 'expenses-by-category', label: 'By category CSV' },
  ],
  profit: [{ report: 'profit-loss', label: 'Export CSV' }],
  outstanding: [{ report: 'outstanding', label: 'Export CSV' }],
}

export function ReportsPage() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('attendance')
  const [from, setFrom] = useState(isoDaysAgo(29))
  const [to, setTo] = useState(todayIso())

  const canFinancial = can(P.reportsFinancial)
  const canExpenses = can(P.expensesView)

  const tabs: Array<{ key: Tab; label: string; show: boolean }> = [
    { key: 'attendance', label: 'Attendance', show: true },
    { key: 'revenue', label: 'Revenue', show: canFinancial },
    { key: 'expenses', label: 'Expenses', show: canExpenses },
    // Needs both halves of the books: a margin shown to someone who cannot see
    // the cost base is not a margin they can check.
    { key: 'profit', label: 'Profit & loss', show: canFinancial && canExpenses },
    { key: 'outstanding', label: 'Outstanding', show: canFinancial },
  ]

  return (
    <>
      <PageHeader
        title="Reports"
        subtitle="Attendance, money in, money out"
        actions={
          <div className="flex flex-wrap gap-2">
            {EXPORTS[tab].map((item, index) => (
              <FileButton
                key={item.report}
                url={`/reports/export/${item.report}?from=${from}&to=${to}`}
                filename={`${item.report}-${from}-to-${to}.csv`}
                mode="download"
                variant={index === 0 ? 'secondary' : 'ghost'}
              >
                {item.label}
              </FileButton>
            ))}
          </div>
        }
      />

      <div className="mb-5 flex flex-wrap items-end gap-3">
        <div className="flex flex-wrap gap-1 rounded-lg border border-ink-200 bg-white p-1">
          {tabs
            .filter((t) => t.show)
            .map((item) => (
              <button
                key={item.key}
                type="button"
                onClick={() => setTab(item.key)}
                className={cx(
                  'rounded px-3 py-1.5 text-sm font-medium transition',
                  tab === item.key ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100',
                )}
              >
                {item.label}
              </button>
            ))}
        </div>

        {tab !== 'outstanding' && (
          <>
            <label className="text-xs font-medium text-ink-600">
              From
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="mt-1" />
            </label>
            <label className="text-xs font-medium text-ink-600">
              To
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="mt-1" />
            </label>
          </>
        )}

        {tab === 'profit' && (
          <p className="pb-1.5 text-xs text-ink-500">Grouped by calendar month — pick a range of several months.</p>
        )}
      </div>

      {tab === 'attendance' && <AttendanceReport from={from} to={to} />}
      {tab === 'revenue' && canFinancial && <RevenueReport from={from} to={to} />}
      {tab === 'expenses' && canExpenses && <ExpenseReport from={from} to={to} />}
      {tab === 'profit' && canFinancial && canExpenses && <ProfitReport from={from} to={to} />}
      {tab === 'outstanding' && canFinancial && <OutstandingReport />}
    </>
  )
}

interface ExpenseReportData {
  total: number
  recurring: number
  one_off: number
  by_category: Array<{ category_id: number; category: string; is_recurring: boolean; count: number; total: number }>
  by_method: Array<{ method: string; count: number; total: number }>
  daily: Array<{ date: string; total: number; count: number }>
}

function ExpenseReport({ from, to }: { from: string; to: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['report-expenses', from, to],
    queryFn: async () => (await api.get<ExpenseReportData>('/reports/expenses', { params: { from, to } })).data,
  })

  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (isLoading) return <LoadingBlock />
  if (!data) return null

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Total spent</p>
          <p className="mt-1 text-2xl font-semibold tabular text-red-700">{money(data.total)}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Recurring</p>
          <p className="mt-1 text-2xl font-semibold tabular">{money(data.recurring)}</p>
          <p className="text-xs text-ink-500">What the gym costs to keep open</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">One-off</p>
          <p className="mt-1 text-2xl font-semibold tabular">{money(data.one_off)}</p>
        </Card>
      </div>

      <Card title="Spending by day">
        {data.daily.length === 0 ? (
          <EmptyState title="No expenses in this range" />
        ) : (
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.daily} margin={{ top: 4, right: 8, bottom: 0, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#eef0f4" vertical={false} />
                <XAxis
                  dataKey="date"
                  tickFormatter={(value: string) => value.slice(5)}
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  minTickGap={14}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={(value: number) => (value >= 1000 ? `${value / 1000}k` : String(value))}
                />
                <Tooltip
                  labelFormatter={(value) => date(String(value))}
                  formatter={(value) => [money(Number(value)), 'Spent']}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                />
                <Bar dataKey="total" fill="#c2410c" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="By category">
          {data.by_category.length === 0 ? (
            <EmptyState title="No expenses" />
          ) : (
            <div className="flex flex-wrap items-center gap-6">
              <div className="h-48 w-48 shrink-0">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={data.by_category} dataKey="total" nameKey="category" innerRadius={45} outerRadius={72}>
                      {data.by_category.map((_, index) => (
                        <Cell key={index} fill={SERIES_COLOURS[index % SERIES_COLOURS.length]} />
                      ))}
                    </Pie>
                    <Tooltip
                      formatter={(value) => money(Number(value))}
                      contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                    />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="min-w-0 flex-1 space-y-2 text-sm">
                {data.by_category.map((row, index) => (
                  <li key={row.category_id} className="flex items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-2">
                      <span
                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                        style={{ background: SERIES_COLOURS[index % SERIES_COLOURS.length] }}
                      />
                      <span className="truncate">{row.category}</span>
                    </span>
                    <span className="tabular shrink-0 font-medium">{money(row.total)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>

        <Card title="By payment method" padded={false}>
          <Table>
            <thead>
              <tr>
                <Th>Method</Th>
                <Th align="right">Count</Th>
                <Th align="right">Total</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.by_method.map((row) => (
                <tr key={row.method}>
                  <Td>{row.method.replace(/_/g, ' ').toLowerCase()}</Td>
                  <Td align="right">{row.count}</Td>
                  <Td align="right">{money(row.total)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>
    </div>
  )
}

interface ProfitReportData {
  income: number
  gross_income: number
  refunds: number
  expenses: number
  profit: number
  margin_percent: number | null
  by_month: Array<{ period: string; label: string; income: number; expenses: number; profit: number }>
  expense_by_category: Array<{ category_id: number; category: string; total: number }>
}

function ProfitReport({ from, to }: { from: string; to: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['report-profit', from, to],
    queryFn: async () => (await api.get<ProfitReportData>('/reports/profit-loss', { params: { from, to } })).data,
  })

  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (isLoading) return <LoadingBlock />
  if (!data) return null

  const profitable = data.profit >= 0

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Income</p>
          <p className="mt-1 text-2xl font-semibold tabular text-emerald-700">{money(data.income)}</p>
          {data.refunds !== 0 && (
            <p className="text-xs text-ink-500">after {money(Math.abs(data.refunds))} refunded</p>
          )}
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Expenses</p>
          <p className="mt-1 text-2xl font-semibold tabular text-red-700">{money(data.expenses)}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">{profitable ? 'Profit' : 'Loss'}</p>
          <p className={cx('mt-1 text-2xl font-semibold tabular', profitable ? 'text-emerald-700' : 'text-red-700')}>
            {money(Math.abs(data.profit))}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Margin</p>
          <p className="mt-1 text-2xl font-semibold tabular">
            {/* Null when nothing came in — a month with costs and no income has
                no meaningful margin, and 0% would read as a fact. */}
            {data.margin_percent === null ? '—' : `${data.margin_percent}%`}
          </p>
        </Card>
      </div>

      <Card title="Income against expenses" subtitle="By calendar month">
        {data.by_month.length === 0 ? (
          <EmptyState title="Nothing in this range" />
        ) : (
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.by_month} margin={{ top: 4, right: 8, bottom: 0, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#eef0f4" vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#74809a' }} axisLine={false} tickLine={false} />
                <YAxis
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={(value: number) => (Math.abs(value) >= 1000 ? `${value / 1000}k` : String(value))}
                />
                <Tooltip
                  formatter={(value, name) => [money(Number(value)), String(name)]}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="income" name="Income" fill="#0f9d76" radius={[3, 3, 0, 0]} />
                <Bar dataKey="expenses" name="Expenses" fill="#c2410c" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>

      <Card title="Month by month" padded={false}>
        <Table>
          <thead>
            <tr>
              <Th>Month</Th>
              <Th align="right">Income</Th>
              <Th align="right">Expenses</Th>
              <Th align="right">Profit</Th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-100">
            {data.by_month.map((row) => (
              <tr key={row.period}>
                <Td>{row.label}</Td>
                <Td align="right">{money(row.income)}</Td>
                <Td align="right">{money(row.expenses)}</Td>
                <Td align="right">
                  <span className={cx('font-medium', row.profit >= 0 ? 'text-emerald-700' : 'text-red-600')}>
                    {money(row.profit)}
                  </span>
                </Td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-ink-200 bg-ink-50">
              <Td>
                <span className="font-semibold">Total</span>
              </Td>
              <Td align="right">
                <span className="font-semibold">{money(data.income)}</span>
              </Td>
              <Td align="right">
                <span className="font-semibold">{money(data.expenses)}</span>
              </Td>
              <Td align="right">
                <span className={cx('font-semibold', profitable ? 'text-emerald-700' : 'text-red-600')}>
                  {money(data.profit)}
                </span>
              </Td>
            </tr>
          </tfoot>
        </Table>
      </Card>
    </div>
  )
}

function AttendanceReport({ from, to }: { from: string; to: string }) {
  const daily = useQuery({
    queryKey: ['report-daily', from, to],
    queryFn: async () =>
      (
        await api.get<{ data: Array<{ date: string; visits: number; unique_members: number; avg_duration_minutes: number | null }> }>(
          '/reports/attendance/daily',
          { params: { from, to } },
        )
      ).data.data,
  })

  const members = useQuery({
    queryKey: ['report-members', from, to],
    queryFn: async () =>
      (
        await api.get<{ data: Array<{ member_id: number; full_name: string; membership_number: string; visits: number; last_visit: string; avg_duration_minutes: number | null }> }>(
          '/reports/attendance/members',
          { params: { from, to } },
        )
      ).data.data,
  })

  if (daily.error) return <Alert>{errorMessage(daily.error)}</Alert>
  if (daily.isLoading) return <LoadingBlock />

  const rows = daily.data ?? []
  const totalVisits = rows.reduce((sum, row) => sum + row.visits, 0)
  const busiest = rows.reduce((best, row) => (row.visits > (best?.visits ?? -1) ? row : best), rows[0])

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Total visits</p>
          <p className="mt-1 text-2xl font-semibold tabular">{number(totalVisits)}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Busiest day</p>
          <p className="mt-1 text-2xl font-semibold tabular">{busiest ? number(busiest.visits) : '—'}</p>
          <p className="text-xs text-ink-500">{busiest ? date(busiest.date) : ''}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Unique members</p>
          <p className="mt-1 text-2xl font-semibold tabular">{number(members.data?.length ?? 0)}</p>
        </Card>
      </div>

      <Card title="Daily attendance">
        {rows.length === 0 ? (
          <EmptyState title="No attendance in this range" />
        ) : (
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={rows} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#eef0f4" vertical={false} />
                <XAxis
                  dataKey="date"
                  tickFormatter={(value: string) => value.slice(5)}
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  minTickGap={14}
                />
                <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#74809a' }} axisLine={false} tickLine={false} />
                <Tooltip
                  labelFormatter={(value) => date(String(value))}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                />
                <Bar dataKey="visits" fill="#3182f6" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>

      <Card title="Visits per member" padded={false}>
        {members.isLoading ? (
          <LoadingBlock />
        ) : !members.data?.length ? (
          <EmptyState title="No member visits in this range" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Member</Th>
                <Th>Number</Th>
                <Th align="right">Visits</Th>
                <Th align="right">Avg session</Th>
                <Th>Last visit</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {members.data.slice(0, 50).map((row) => (
                <tr key={row.member_id}>
                  <Td>{row.full_name}</Td>
                  <Td>
                    <span className="text-xs text-ink-500">{row.membership_number}</span>
                  </Td>
                  <Td align="right">
                    <span className="font-medium">{row.visits}</span>
                  </Td>
                  <Td align="right">{duration(row.avg_duration_minutes ? Math.round(row.avg_duration_minutes) : null)}</Td>
                  <Td>{date(row.last_visit)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  )
}

function RevenueReport({ from, to }: { from: string; to: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['report-revenue', from, to],
    queryFn: async () =>
      (
        await api.get<{
          gross: number
          refunds: number
          net: number
          by_method: Array<{ method: string; count: number; total: number }>
          by_type: Array<{ payment_type: string; count: number; total: number }>
          daily: Array<{ date: string; total: number; count: number }>
        }>('/reports/revenue', { params: { from, to } })
      ).data,
  })

  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (isLoading) return <LoadingBlock />
  if (!data) return null

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Collected</p>
          <p className="mt-1 text-2xl font-semibold tabular text-emerald-700">{money(data.gross)}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Refunded</p>
          <p className="mt-1 text-2xl font-semibold tabular text-red-700">{money(Math.abs(data.refunds))}</p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-ink-500">Net</p>
          <p className="mt-1 text-2xl font-semibold tabular">{money(data.net)}</p>
        </Card>
      </div>

      <Card title="Daily takings">
        {data.daily.length === 0 ? (
          <EmptyState title="No payments in this range" />
        ) : (
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.daily} margin={{ top: 4, right: 8, bottom: 0, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#eef0f4" vertical={false} />
                <XAxis
                  dataKey="date"
                  tickFormatter={(value: string) => value.slice(5)}
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  minTickGap={14}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: '#74809a' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={(value: number) => (value >= 1000 ? `${value / 1000}k` : String(value))}
                />
                <Tooltip
                  labelFormatter={(value) => date(String(value))}
                  formatter={(value) => [money(Number(value)), 'Taken']}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                />
                <Bar dataKey="total" fill="#0f9d76" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="By payment method">
          {data.by_method.length === 0 ? (
            <EmptyState title="No payments" />
          ) : (
            <div className="flex flex-wrap items-center gap-6">
              {/*
                flex-wrap and min-w-0, not a plain row: a 192px pie beside a
                legend whose longest line is "bank transfer  LKR 125,000.00"
                does not fit 390px, and the row pushed the page sideways.
              */}
              <div className="h-48 w-48 shrink-0">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={data.by_method} dataKey="total" nameKey="method" innerRadius={45} outerRadius={72}>
                      {data.by_method.map((_, index) => (
                        <Cell key={index} fill={SERIES_COLOURS[index % SERIES_COLOURS.length]} />
                      ))}
                    </Pie>
                    <Tooltip
                      formatter={(value) => money(Number(value))}
                      contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #dde1e9' }}
                    />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="min-w-0 flex-1 space-y-2 text-sm">
                {data.by_method.map((row, index) => (
                  <li key={row.method} className="flex items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-2">
                      <span
                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                        style={{ background: SERIES_COLOURS[index % SERIES_COLOURS.length] }}
                      />
                      <span className="truncate">{row.method.replace(/_/g, ' ').toLowerCase()}</span>
                    </span>
                    <span className="tabular shrink-0 font-medium">{money(row.total)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>

        <Card title="By charge type" padded={false}>
          <Table>
            <thead>
              <tr>
                <Th>Type</Th>
                <Th align="right">Count</Th>
                <Th align="right">Total</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.by_type.map((row) => (
                <tr key={row.payment_type}>
                  <Td>{row.payment_type.replace(/_/g, ' ').toLowerCase()}</Td>
                  <Td align="right">{row.count}</Td>
                  <Td align="right">{money(row.total)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>
    </div>
  )
}

function OutstandingReport() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['report-outstanding'],
    queryFn: async () =>
      (
        await api.get<{
          data: Array<{
            invoice_id: number
            invoice_number: string
            member_id: number
            member_name: string
            membership_number: string
            telephone: string | null
            due_date: string | null
            total: number
            paid: number
            balance: number
            days_overdue: number
          }>
        }>('/reports/outstanding')
      ).data.data,
  })

  if (error) return <Alert>{errorMessage(error)}</Alert>
  if (isLoading) return <LoadingBlock />

  const rows = data ?? []
  const total = rows.reduce((sum, row) => sum + row.balance, 0)

  return (
    <Card
      title="Outstanding payments"
      subtitle={`${rows.length} unsettled invoice${rows.length === 1 ? '' : 's'} · ${money(total)}`}
      padded={false}
    >
      {rows.length === 0 ? (
        <EmptyState title="Nothing outstanding" description="Every invoice is settled." />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Invoice</Th>
              <Th>Member</Th>
              <Th>Contact</Th>
              <Th>Due</Th>
              <Th align="right">Total</Th>
              <Th align="right">Balance</Th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-100">
            {rows.map((row) => (
              <tr key={row.invoice_id} className="hover:bg-ink-50">
                <Td>
                  <FileButton
                    url={`/invoices/${row.invoice_id}/pdf`}
                    filename={`invoice-${row.invoice_number}.pdf`}
                    variant="ghost"
                  >
                    {row.invoice_number}
                  </FileButton>
                </Td>
                <Td>
                  <span className="block text-sm">{row.member_name}</span>
                  <span className="block text-xs text-ink-500">{row.membership_number}</span>
                </Td>
                <Td>{row.telephone ?? '—'}</Td>
                <Td>
                  <span className="block text-sm">{date(row.due_date)}</span>
                  {row.days_overdue > 0 && (
                    <span className="block text-xs text-red-600">{row.days_overdue} days overdue</span>
                  )}
                </Td>
                <Td align="right">{money(row.total)}</Td>
                <Td align="right">
                  <span className="font-medium text-red-600">{money(row.balance)}</span>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}
