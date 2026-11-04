import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  LoadingBlock,
  Modal,
  PageHeader,
  Select,
  StatusBadge,
  FileButton,
  Table,
  Td,
  Textarea,
  Th,
  cx,
} from '../components/ui'
import { MemberDocuments } from '../components/MemberDocuments'
import { MemberHealth } from '../components/MemberHealth'
import { useToast } from '../components/Toast'
import { PhotoUpload } from '../components/PhotoUpload'
import { WeeklySchedule, type ScheduleDay, type ScheduleExercise } from '../components/WeeklySchedule'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, dateTime, duration, expiryLabel, money, relative, time } from '../lib/format'
import type {
  Attendance,
  BiometricDevice,
  Member,
  Membership,
  MembershipPlan,
  Paginated,
  Payment,
} from '../lib/types'

type Tab = 'memberships' | 'payments' | 'attendance' | 'schedule' | 'health' | 'documents' | 'fingerprint'

export function MemberDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()

  const [tab, setTab] = useState<Tab>('memberships')
  const [renewing, setRenewing] = useState<Membership | null>(null)
  const [paying, setPaying] = useState<Membership | null>(null)
  const [enrolling, setEnrolling] = useState(false)
  const [editing, setEditing] = useState(false)

  const memberQuery = useQuery({
    queryKey: ['member', id],
    queryFn: async () => (await api.get<{ data: Member }>(`/members/${id}`)).data.data,
    enabled: !!id,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['member', id] })
    queryClient.invalidateQueries({ queryKey: ['member-payments', id] })
    queryClient.invalidateQueries({ queryKey: ['member-attendance', id] })
    queryClient.invalidateQueries({ queryKey: ['member-schedule', Number(id)] })
  }

  if (memberQuery.isLoading) return <LoadingBlock />
  if (memberQuery.error) return <Alert>{errorMessage(memberQuery.error)}</Alert>

  const member = memberQuery.data
  if (!member) return null

  const current = member.current_membership
  const tabs: Array<{ key: Tab; label: string; show: boolean }> = [
    { key: 'memberships', label: 'Memberships', show: can(P.membershipsView) },
    { key: 'payments', label: 'Payments', show: can(P.paymentsView) },
    { key: 'attendance', label: 'Attendance', show: can(P.attendanceView) },
    { key: 'schedule', label: 'Gym schedule', show: can(P.workoutsView) },
    { key: 'health', label: 'Health', show: can(P.healthView) },
    { key: 'documents', label: 'Documents', show: can(P.documentsView) },
    { key: 'fingerprint', label: 'Fingerprint', show: can(P.devicesView) },
  ]
  const visibleTabs = tabs.filter((t) => t.show)

  return (
    <>
      <PageHeader
        title={member.full_name}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <span>{member.membership_number}</span>
            <StatusBadge status={member.status} />
            {member.trainer && <span>· Trainer: {member.trainer.full_name}</span>}
          </span>
        }
        actions={
          <>
            <Link to="/members">
              <Button variant="secondary" size="sm">
                Back to members
              </Button>
            </Link>
            {can(P.membersUpdate) && (
              <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
                Edit member
              </Button>
            )}
            {can(P.membershipsRenew) && current && (
              <Button size="sm" onClick={() => setRenewing(current)}>
                Renew membership
              </Button>
            )}
          </>
        }
      />

      {/* min-w-0 on the grid children: a grid track otherwise sizes to its
          content's intrinsic minimum and refuses to shrink on a phone. */}
      <div className="grid gap-6 lg:grid-cols-4">
        <div className="min-w-0 space-y-6 lg:col-span-1">
          <Card>
            <PhotoUpload
              memberId={member.id}
              photoUrl={member.photo_url}
              name={member.full_name}
              canEdit={can(P.membersUpdate)}
            />
            <p className="mt-3 text-center font-semibold text-ink-900">{member.full_name}</p>
            <p className="text-center text-xs text-ink-500">{member.membership_number}</p>

            <dl className="mt-5 space-y-2.5 text-sm">
              <Detail label="Telephone" value={member.telephone} />
              <Detail label="Email" value={member.email} />
              <Detail label="NIC / Passport" value={member.nic_passport} />
              <Detail label="Date of birth" value={member.date_of_birth ? `${date(member.date_of_birth)}${member.age ? ` (${member.age})` : ''}` : null} />
              <Detail label="Gender" value={member.gender} />
              <Detail label="Address" value={[member.address, member.city].filter(Boolean).join(', ') || null} />
              <Detail label="Joined" value={date(member.join_date)} />
              <Detail
                label="Emergency"
                value={
                  member.emergency_contact_name
                    ? `${member.emergency_contact_name} · ${member.emergency_contact_number ?? ''}`
                    : null
                }
              />
            </dl>
          </Card>

          <Card title="Activity">
            <dl className="space-y-2.5 text-sm">
              <Detail label="Total visits" value={String(member.total_visits)} />
              <Detail label="Last visit" value={member.last_visit_at ? relative(member.last_visit_at) : 'never'} />
            </dl>
          </Card>
        </div>

        <div className="min-w-0 space-y-6 lg:col-span-3">
          <Card title="Current membership">
            {current ? (
              <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="text-base font-semibold text-ink-900">{current.plan_name}</p>
                    <p className="text-sm text-ink-500">
                      {date(current.start_date)} – {date(current.end_date)} · {current.reference}
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <StatusBadge status={current.status} />
                    <StatusBadge status={current.payment_status} />
                  </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-4">
                  <Metric label="Value" value={money(current.final_amount)} />
                  <Metric label="Paid" value={money(current.paid_amount)} />
                  <Metric
                    label="Balance"
                    value={money(current.balance)}
                    tone={current.balance > 0 ? 'danger' : 'success'}
                  />
                  <Metric
                    label="Expires"
                    value={expiryLabel(current.days_remaining)}
                    tone={current.days_remaining <= 7 ? 'warning' : 'neutral'}
                  />
                </div>

                {current.balance > 0 && can(P.paymentsCreate) && (
                  // An already-active membership keeps admitting the member
                  // even with a balance on it — only a PENDING one is gated on
                  // payment. Saying otherwise would send reception to argue a
                  // point that is not true.
                  <Alert tone="warning">
                    {money(current.balance)} outstanding.{' '}
                    {current.status === 'PENDING'
                      ? 'The membership will not admit the member until it is settled.'
                      : 'The membership is active; this balance is still owed.'}{' '}
                    <button type="button" onClick={() => setPaying(current)} className="font-medium underline">
                      Take payment
                    </button>
                  </Alert>
                )}
              </div>
            ) : (
              <EmptyState
                title="No membership on record"
                description="Sell a membership so this member can be admitted."
              />
            )}
          </Card>

          <Card padded={false}>
            {/* Six tabs will not fit on a phone. A scrolling strip is the
                right answer for tabs — wrapping them onto two rows makes the
                header taller than the content it labels. */}
            <div className="flex gap-1 overflow-x-auto border-b border-ink-200 px-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
              {visibleTabs.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => setTab(item.key)}
                  className={cx(
                    'shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                    tab === item.key
                      ? 'border-brand-600 text-brand-700'
                      : 'border-transparent text-ink-500 hover:text-ink-800',
                  )}
                >
                  {item.label}
                </button>
              ))}
            </div>

            {tab === 'memberships' && (
              <MembershipsTab member={member} onRenew={setRenewing} onPay={setPaying} />
            )}
            {tab === 'payments' && <PaymentsTab memberId={member.id} />}
            {tab === 'attendance' && <AttendanceTab memberId={member.id} />}
            {tab === 'schedule' && <ScheduleTab memberId={member.id} />}
            {tab === 'health' && <MemberHealth memberId={member.id} />}
            {tab === 'documents' && (
              <MemberDocuments memberId={member.id} canManage={can(P.documentsManage)} />
            )}
            {tab === 'fingerprint' && (
              <FingerprintTab member={member} onEnrol={() => setEnrolling(true)} onChanged={refresh} />
            )}
          </Card>
        </div>
      </div>

      {renewing && (
        <RenewModal
          membership={renewing}
          onClose={() => setRenewing(null)}
          onDone={(message) => {
            setRenewing(null)
            toast.success('Membership renewed', message)
            refresh()
          }}
        />
      )}

      {paying && (
        <PaymentModal
          membership={paying}
          memberId={member.id}
          onClose={() => setPaying(null)}
          onDone={(message) => {
            setPaying(null)
            toast.success('Payment recorded', message)
            refresh()
          }}
        />
      )}

      {enrolling && (
        <EnrolModal
          memberId={member.id}
          onClose={() => setEnrolling(false)}
          onDone={(message) => {
            setEnrolling(false)
            toast.success('Fingerprint registered', message)
            refresh()
          }}
        />
      )}

      {editing && (
        <EditMemberModal
          member={member}
          onClose={() => setEditing(false)}
          onSaved={(statusChanged) => {
            setEditing(false)
            toast.success(
              'Member updated',
              statusChanged ? `${member.full_name} is now ${statusChanged.toLowerCase()}.` : undefined,
            )
            refresh()
            queryClient.invalidateQueries({ queryKey: ['members'] })
          }}
        />
      )}
    </>
  )
}

function Detail({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="shrink-0 text-xs text-ink-500">{label}</dt>
      {/* min-w-0 and wrap-anywhere together: without them an email address
          with no spaces sets a floor on this column's width, which on a phone
          widened the whole page and made it scroll sideways. */}
      <dd className="min-w-0 wrap-anywhere text-right text-sm text-ink-800">
        {value || <span className="text-ink-400">—</span>}
      </dd>
    </div>
  )
}

function Metric({
  label,
  value,
  tone = 'neutral',
}: {
  label: string
  value: string
  tone?: 'neutral' | 'success' | 'warning' | 'danger'
}) {
  const toneClass = {
    neutral: 'text-ink-900',
    success: 'text-emerald-700',
    warning: 'text-amber-700',
    danger: 'text-red-700',
  }[tone]

  return (
    <div className="rounded-lg bg-ink-50 px-3 py-2.5">
      <p className="text-[11px] uppercase tracking-wide text-ink-500">{label}</p>
      <p className={cx('mt-0.5 text-sm font-semibold tabular', toneClass)}>{value}</p>
    </div>
  )
}

/* ---------------------------------------------------------------- Tabs */

function MembershipsTab({
  member,
  onRenew,
  onPay,
}: {
  member: Member
  onRenew: (m: Membership) => void
  onPay: (m: Membership) => void
}) {
  const { can } = useAuth()
  const memberships = member.memberships ?? []

  if (!memberships.length) return <EmptyState title="No memberships yet" />

  return (
    <Table>
      <thead>
        <tr>
          <Th>Reference</Th>
          <Th>Plan</Th>
          <Th>Period</Th>
          <Th align="right">Value</Th>
          <Th align="right">Balance</Th>
          <Th align="right">Status</Th>
          <Th />
        </tr>
      </thead>
      <tbody className="divide-y divide-ink-100">
        {memberships.map((m) => (
          <tr key={m.id}>
            <Td>
              <span className="font-medium">{m.reference}</span>
              {m.renewed_from_id && <span className="block text-xs text-ink-500">renewal</span>}
            </Td>
            <Td>{m.plan_name}</Td>
            <Td>
              <span className="block text-sm">{date(m.start_date)}</span>
              <span className="block text-xs text-ink-500">to {date(m.end_date)}</span>
            </Td>
            <Td align="right">{money(m.final_amount)}</Td>
            <Td align="right">
              <span className={m.balance > 0 ? 'font-medium text-red-600' : 'text-ink-500'}>{money(m.balance)}</span>
            </Td>
            <Td align="right">
              <div className="flex justify-end gap-1.5">
                <StatusBadge status={m.status} />
                <StatusBadge status={m.payment_status} />
              </div>
            </Td>
            <Td align="right">
              <div className="flex justify-end gap-1.5">
                {m.balance > 0 && can(P.paymentsCreate) && (
                  <Button size="sm" variant="secondary" onClick={() => onPay(m)}>
                    Pay
                  </Button>
                )}
                {can(P.membershipsRenew) && (
                  <Button size="sm" variant="ghost" onClick={() => onRenew(m)}>
                    Renew
                  </Button>
                )}
              </div>
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

function PaymentsTab({ memberId }: { memberId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['member-payments', memberId],
    queryFn: async () => (await api.get<Paginated<Payment>>(`/members/${memberId}/payments`)).data,
  })

  if (isLoading) return <LoadingBlock />
  if (!data?.data?.length) return <EmptyState title="No payments recorded" />

  return (
    <Table>
      <thead>
        <tr>
          <Th>Receipt</Th>
          <Th>Date</Th>
          <Th>For</Th>
          <Th>Method</Th>
          <Th align="right">Amount</Th>
          <Th />
        </tr>
      </thead>
      <tbody className="divide-y divide-ink-100">
        {data.data.map((payment) => (
          <tr key={payment.id}>
            <Td>
              <span className="font-medium">{payment.receipt_number}</span>
            </Td>
            <Td>{dateTime(payment.paid_at)}</Td>
            <Td>{payment.payment_type_label}</Td>
            <Td>{payment.method_label}</Td>
            <Td align="right">
              <span className={payment.is_refund ? 'font-medium text-red-600' : ''}>{money(payment.amount)}</span>
            </Td>
            <Td align="right">
              <FileButton
                url={`/payments/${payment.id}/receipt`}
                filename={`receipt-${payment.receipt_number}.pdf`}
                variant="ghost"
              >
                Receipt
              </FileButton>
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

function AttendanceTab({ memberId }: { memberId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['member-attendance', memberId],
    queryFn: async () => (await api.get<Paginated<Attendance>>(`/attendance/member/${memberId}`)).data,
  })

  if (isLoading) return <LoadingBlock />
  if (!data?.data?.length) return <EmptyState title="No visits recorded" />

  return (
    <Table>
      <thead>
        <tr>
          <Th>Date</Th>
          <Th>In</Th>
          <Th>Out</Th>
          <Th align="right">Duration</Th>
          <Th>How</Th>
          <Th align="right">Status</Th>
        </tr>
      </thead>
      <tbody className="divide-y divide-ink-100">
        {data.data.map((visit) => (
          <tr key={visit.id}>
            <Td>{date(visit.attendance_date)}</Td>
            <Td>{time(visit.check_in_time)}</Td>
            <Td>{time(visit.check_out_time)}</Td>
            <Td align="right">{duration(visit.duration_minutes)}</Td>
            <Td>
              <span className="text-xs text-ink-500">
                {visit.attendance_type === 'BIOMETRIC' ? visit.verification_type.toLowerCase() : visit.attendance_type.toLowerCase()}
              </span>
            </Td>
            <Td align="right">
              {visit.membership_valid ? (
                <StatusBadge status={visit.status} />
              ) : (
                <Badge tone="danger">invalid membership</Badge>
              )}
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

/** SRS 20 - the member's training week, as reception sees it at the counter. */
function ScheduleTab({ memberId }: { memberId: number }) {
  const { can } = useAuth()

  const { data, isLoading, error } = useQuery({
    queryKey: ['member-schedule', memberId],
    queryFn: async () =>
      (
        await api.get<{
          plan: {
            id: number
            title: string
            goal: string | null
            status: string
            start_date: string | null
            end_date: string | null
            trainer: string | null
            notes: string | null
          } | null
          days: ScheduleDay[]
          every_session: ScheduleExercise[]
        }>(`/members/${memberId}/schedule`)
      ).data,
  })

  if (isLoading) return <LoadingBlock />
  if (error) return <div className="p-5"><Alert>{errorMessage(error)}</Alert></div>

  if (!data?.plan) {
    return (
      <EmptyState
        title="No gym schedule yet"
        description="Create a weekly training programme for this member."
        action={
          can(P.workoutsManage) && (
            <Link to="/schedule">
              <Button>Go to gym schedules</Button>
            </Link>
          )
        }
      />
    )
  }

  return (
    <div className="p-5">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-semibold text-ink-900">{data.plan.title}</p>
          <p className="text-xs text-ink-500">
            {data.plan.goal ? data.plan.goal.replace(/_/g, ' ').toLowerCase() : 'no goal set'}
            {data.plan.trainer ? ` · ${data.plan.trainer}` : ' · unassigned'}
            {data.plan.start_date ? ` · from ${date(data.plan.start_date)}` : ''}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={data.plan.status} />
          {can(P.workoutsManage) && (
            <Link to="/schedule">
              <Button size="sm" variant="secondary">
                Edit
              </Button>
            </Link>
          )}
        </div>
      </div>

      <WeeklySchedule days={data.days} everySession={data.every_session} compact />

      {data.plan.notes && <p className="mt-4 rounded-lg bg-ink-50 p-3 text-xs text-ink-600">{data.plan.notes}</p>}
    </div>
  )
}

/** SRS 8 - fingerprint registration and its verification. */
function FingerprintTab({
  member,
  onEnrol,
  onChanged,
}: {
  member: Member
  onEnrol: () => void
  onChanged: () => void
}) {
  const { can } = useAuth()
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null)

  const verify = useMutation({
    mutationFn: async (mappingId: number) => (await api.post(`/biometric/users/${mappingId}/verify`)).data,
    onSuccess: (data: { message: string }) => {
      setMessage({ tone: 'success', text: data.message })
      onChanged()
    },
    onError: (error) => setMessage({ tone: 'danger', text: errorMessage(error) }),
  })

  const mappings = member.biometric_users ?? []

  return (
    <div className="p-5">
      {message && (
        <div className="mb-4">
          <Alert tone={message.tone}>{message.text}</Alert>
        </div>
      )}

      {mappings.length === 0 ? (
        <EmptyState
          title="No fingerprint registered"
          description="Create the member on a reader, then have them enrol their fingerprint at the device."
          action={
            can(P.enrollmentManage) && <Button onClick={onEnrol}>Register fingerprint</Button>
          }
        />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Device</Th>
                <Th>Biometric ID</Th>
                <Th>Fingerprints</Th>
                <Th>Status</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {mappings.map((mapping) => (
                <tr key={mapping.id}>
                  <Td>{mapping.device?.name ?? `Device ${mapping.device_id}`}</Td>
                  <Td>
                    <code className="rounded bg-ink-100 px-1.5 py-0.5 text-xs">{mapping.biometric_user_id}</code>
                  </Td>
                  <Td>{mapping.fingerprint_count}</Td>
                  <Td>
                    <div className="flex gap-1.5">
                      <StatusBadge status={mapping.enrollment_status} />
                      {mapping.sync_status === 'FAILED' && <Badge tone="danger">not on device</Badge>}
                    </div>
                    {mapping.sync_error && <p className="mt-1 text-xs text-red-600">{mapping.sync_error}</p>}
                  </Td>
                  <Td align="right">
                    {can(P.enrollmentManage) && (
                      <Button
                        size="sm"
                        variant="secondary"
                        loading={verify.isPending}
                        onClick={() => verify.mutate(mapping.id)}
                      >
                        Verify on device
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>

          {can(P.enrollmentManage) && (
            <div className="mt-4">
              <Button variant="secondary" size="sm" onClick={onEnrol}>
                Register on another device
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

/* -------------------------------------------------------------- Modals */

function RenewModal({
  membership,
  onClose,
  onDone,
}: {
  membership: Membership
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [planId, setPlanId] = useState<string>(String(membership.membership_plan_id))
  const [error, setError] = useState<string | null>(null)

  const { data: plans } = useQuery({
    queryKey: ['plans'],
    queryFn: async () => (await api.get<{ data: MembershipPlan[] }>('/membership-plans')).data.data,
  })

  // The quote comes from the server so the dates reception sees are the dates
  // that will actually be written - no second implementation of the rules here.
  const { data: preview, isFetching } = useQuery({
    queryKey: ['renewal-preview', membership.id, planId],
    queryFn: async () =>
      (
        await api.get(`/memberships/${membership.id}/renewal-preview`, {
          params: { membership_plan_id: planId || undefined },
        })
      ).data,
  })

  const renew = useMutation({
    mutationFn: async () =>
      (await api.post(`/memberships/${membership.id}/renew`, { membership_plan_id: Number(planId) })).data,
    onSuccess: (result: { message: string }) => onDone(result.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Renew membership"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={renew.isPending} onClick={() => renew.mutate()}>
            Confirm renewal
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <p className="text-sm text-ink-600">
          Current term runs to <strong>{date(membership.end_date)}</strong>.
        </p>

        <Field label="Package">
          <Select value={planId} onChange={(e) => setPlanId(e.target.value)}>
            {plans?.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.name} — {plan.duration_label} — {money(plan.price)}
              </option>
            ))}
          </Select>
        </Field>

        {isFetching ? (
          <LoadingBlock label="Calculating dates…" />
        ) : (
          preview && (
            <div className="rounded-lg border border-ink-200 bg-ink-50 p-4 text-sm">
              <div className="flex justify-between py-1">
                <span className="text-ink-500">New period</span>
                <span className="font-medium">
                  {date(preview.start_date)} – {date(preview.end_date)}
                </span>
              </div>
              <div className="flex justify-between py-1">
                <span className="text-ink-500">Price</span>
                <span className="tabular">{money(preview.price)}</span>
              </div>
              {preview.discount > 0 && (
                <div className="flex justify-between py-1">
                  <span className="text-ink-500">Discount</span>
                  <span className="tabular">− {money(preview.discount)}</span>
                </div>
              )}
              <div className="mt-1 flex justify-between border-t border-ink-200 pt-2 font-semibold">
                <span>Total</span>
                <span className="tabular">{money(preview.final_amount)}</span>
              </div>
              {preview.continues_from_previous && (
                <p className="mt-2 text-xs text-emerald-700">
                  Continues from the current expiry date — no days are lost.
                </p>
              )}
            </div>
          )
        )}
      </div>
    </Modal>
  )
}

function PaymentModal({
  membership,
  memberId,
  onClose,
  onDone,
}: {
  membership: Membership
  memberId: number
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [amount, setAmount] = useState(String(membership.balance))
  const [method, setMethod] = useState('CASH')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<string | null>(null)

  const pay = useMutation({
    mutationFn: async () =>
      (
        await api.post('/payments', {
          member_id: memberId,
          membership_id: membership.id,
          amount: Number(amount),
          method,
          reference: reference || undefined,
        })
      ).data,
    onSuccess: (result: { message: string }) => onDone(result.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Record payment"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={pay.isPending} onClick={() => pay.mutate()}>
            Record payment
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="rounded-lg bg-ink-50 p-3 text-sm">
          <div className="flex justify-between">
            <span className="text-ink-500">{membership.plan_name}</span>
            <span>{membership.reference}</span>
          </div>
          <div className="mt-1 flex justify-between font-medium">
            <span>Outstanding</span>
            <span className="tabular">{money(membership.balance)}</span>
          </div>
        </div>

        <Field label="Amount received" required>
          <Input type="number" step="0.01" min="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </Field>

        <Field label="Method" required>
          <Select value={method} onChange={(e) => setMethod(e.target.value)}>
            <option value="CASH">Cash</option>
            <option value="CARD">Card</option>
            <option value="BANK_TRANSFER">Bank transfer</option>
            <option value="ONLINE">Online</option>
          </Select>
        </Field>

        {method !== 'CASH' && (
          <Field label="Reference" hint="Card approval code, transfer reference or transaction id.">
            <Input value={reference} onChange={(e) => setReference(e.target.value)} />
          </Field>
        )}

        <p className="text-xs text-ink-500">
          Paying the balance in full activates the membership immediately.
        </p>
      </div>
    </Modal>
  )
}

/**
 * SRS 4 - Edit a member, including their status.
 *
 * The status field needs explaining, because two different things share one
 * column. SUSPENDED and BLACKLISTED are decisions a person makes about a
 * person, and the system never overwrites them. ACTIVE, INACTIVE and EXPIRED
 * are derived from the membership and are recomputed on every membership
 * change — setting one of those by hand looks like it worked and then quietly
 * reverts, which is worse than not offering it. So the form says so.
 */
function EditMemberModal({
  member,
  onClose,
  onSaved,
}: {
  member: Member
  onClose: () => void
  /** Reports the new status when it changed, so the toast can say so. */
  onSaved: (statusChanged?: string) => void
}) {
  const [form, setForm] = useState<Record<string, string>>({
    full_name: member.full_name ?? '',
    telephone: member.telephone ?? '',
    email: member.email ?? '',
    nic_passport: member.nic_passport ?? '',
    date_of_birth: member.date_of_birth ?? '',
    gender: member.gender ?? '',
    address: member.address ?? '',
    city: member.city ?? '',
    emergency_contact_name: member.emergency_contact_name ?? '',
    emergency_contact_number: member.emergency_contact_number ?? '',
    status: member.status,
    notes: member.notes ?? '',
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      // Send blanks as null rather than '' so clearing a field actually
      // clears it instead of storing an empty string.
      const payload = Object.fromEntries(
        Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]),
      )

      return (await api.put(`/members/${member.id}`, payload)).data
    },
    onSuccess: () => onSaved(statusChanged ? status : undefined),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = (key: string) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const status = form.status
  const isEnforced = status === 'SUSPENDED' || status === 'BLACKLISTED'
  const isDerived = status === 'ACTIVE' || status === 'INACTIVE' || status === 'EXPIRED'
  const statusChanged = status !== member.status

  return (
    <Modal
      open
      onClose={onClose}
      title={`Edit ${member.full_name}`}
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={isEnforced && statusChanged ? 'danger' : 'primary'}
            loading={save.isPending}
            onClick={() => save.mutate()}
          >
            {isEnforced && statusChanged
              ? status === 'BLACKLISTED'
                ? 'Save and blacklist'
                : 'Save and suspend'
              : 'Save changes'}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label="Full name" required error={errors.full_name}>
              <Input value={form.full_name} onChange={set('full_name')} required />
            </Field>
          </div>

          <Field label="Telephone" error={errors.telephone}>
            <Input value={form.telephone} onChange={set('telephone')} inputMode="tel" />
          </Field>

          <Field label="NIC / Passport" error={errors.nic_passport}>
            <Input value={form.nic_passport} onChange={set('nic_passport')} />
          </Field>

          <Field label="Email" error={errors.email}>
            <Input type="email" value={form.email} onChange={set('email')} />
          </Field>

          <Field label="Date of birth" error={errors.date_of_birth}>
            <Input type="date" value={form.date_of_birth} onChange={set('date_of_birth')} />
          </Field>

          <Field label="Gender">
            <Select value={form.gender} onChange={set('gender')}>
              <option value="">Not stated</option>
              <option value="MALE">Male</option>
              <option value="FEMALE">Female</option>
              <option value="OTHER">Other</option>
            </Select>
          </Field>

          <Field label="City">
            <Input value={form.city} onChange={set('city')} />
          </Field>

          <div className="sm:col-span-2">
            <Field label="Address">
              <Input value={form.address} onChange={set('address')} />
            </Field>
          </div>

          <Field label="Emergency contact name">
            <Input value={form.emergency_contact_name} onChange={set('emergency_contact_name')} />
          </Field>

          <Field label="Emergency contact number">
            <Input value={form.emergency_contact_number} onChange={set('emergency_contact_number')} />
          </Field>
        </div>

        <div className="rounded-lg border border-ink-200 bg-ink-50 p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-600">Access</p>

          <Field label="Member status">
            <Select value={form.status} onChange={set('status')}>
              <optgroup label="Set by you — the system will not change these">
                <option value="SUSPENDED">Suspended — temporarily barred from entry</option>
                <option value="BLACKLISTED">Blacklisted — permanently barred from entry</option>
              </optgroup>
              <optgroup label="Managed automatically from the membership">
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="EXPIRED">Expired</option>
              </optgroup>
            </Select>
          </Field>

          {isEnforced && (
            <Alert tone={status === 'BLACKLISTED' ? 'danger' : 'warning'}>
              {status === 'BLACKLISTED'
                ? 'This member will be refused at the door, whatever their membership says, until the status is changed back.'
                : 'This member will be refused at the door while suspended, even with a paid membership.'}
            </Alert>
          )}

          {isDerived && statusChanged && (
            <Alert tone="warning">
              This status is worked out from the member&apos;s membership and will be recalculated the next time
              anything changes. To stop someone entering, use <strong>Suspended</strong> or{' '}
              <strong>Blacklisted</strong> instead — or suspend the membership itself.
            </Alert>
          )}

          <div className="mt-4">
            <Field label="Notes" hint="Why the status was changed, for the next person who looks.">
              <Textarea value={form.notes} onChange={set('notes')} rows={2} />
            </Field>
          </div>
        </div>
      </div>
    </Modal>
  )
}

/** SRS 8 steps 2-4. */
function EnrolModal({
  memberId,
  onClose,
  onDone,
}: {
  memberId: number
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [deviceId, setDeviceId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  const { data: devices } = useQuery({
    queryKey: ['devices'],
    queryFn: async () => (await api.get<{ data: BiometricDevice[] }>('/biometric/devices')).data.data,
  })

  const enrol = useMutation({
    mutationFn: async () =>
      (await api.post('/biometric/users', { member_id: memberId, device_id: Number(deviceId) })).data,
    onSuccess: (data: { message: string }) => {
      setSuccess(data.message)
      setError(null)
    },
    onError: (err) => {
      setError(errorMessage(err))
      setSuccess(null)
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Register fingerprint"
      footer={
        <>
          <Button variant="secondary" onClick={() => (success ? onDone(success) : onClose())}>
            {success ? 'Done' : 'Cancel'}
          </Button>
          {!success && (
            <Button loading={enrol.isPending} disabled={!deviceId} onClick={() => enrol.mutate()}>
              Create on device
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {success && <Alert tone="success">{success}</Alert>}

        <Field label="Device" required>
          <Select value={deviceId} onChange={(e) => setDeviceId(e.target.value)}>
            <option value="">Choose a reader…</option>
            {devices?.map((device) => (
              <option key={device.id} value={device.id}>
                {device.name} ({device.ip_address}) — {device.connection_status.toLowerCase()}
              </option>
            ))}
          </Select>
        </Field>

        <div className="rounded-lg bg-ink-50 p-3 text-xs text-ink-600">
          <p className="font-medium text-ink-800">What happens next</p>
          <ol className="mt-1.5 list-decimal space-y-1 pl-4">
            <li>A user is created on the reader with a unique biometric ID.</li>
            <li>The member enrols their fingerprint at the device itself.</li>
            <li>Press “Verify on device” here to confirm it took.</li>
          </ol>
          <p className="mt-2">
            The fingerprint never leaves the reader — only the ID mapping is stored here.
          </p>
        </div>
      </div>
    </Modal>
  )
}
