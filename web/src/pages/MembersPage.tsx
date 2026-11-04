import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Field,
  FilterBar,
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
import { Pager, RecordCard, RecordList } from '../components/RecordList'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, expiryLabel, relative } from '../lib/format'
import type { Member, MembershipPlan, Paginated } from '../lib/types'

const STATUSES = ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'EXPIRED', 'BLACKLISTED']

export function MembersPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()

  const [search, setSearch] = useState(params.get('search') ?? '')
  const [debounced, setDebounced] = useState(search)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)

  const status = params.get('status') ?? ''
  const expiringSoon = params.get('expiring_soon') === '1'

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search.trim())
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data, isLoading, error } = useQuery({
    queryKey: ['members', debounced, status, expiringSoon, page],
    queryFn: async () =>
      (
        await api.get<Paginated<Member>>('/members', {
          params: {
            search: debounced || undefined,
            status: status || undefined,
            expiring_soon: expiringSoon ? 1 : undefined,
            page,
            per_page: 25,
          },
        })
      ).data,
  })

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
    setPage(1)
  }

  const meta = data?.meta ?? data
  const lastPage = meta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Members"
        subtitle={meta?.total != null ? `${meta.total.toLocaleString()} on file` : undefined}
        actions={
          can(P.membersCreate) && (
            <Button onClick={() => setCreating(true)}>
              <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4">
                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
              </svg>
              Register member
            </Button>
          )
        }
      />

      <Card padded={false}>
        <FilterBar>
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search name, number, phone or NIC…"
            className="max-w-xs"
          />
          <Select value={status} onChange={(e) => setFilter('status', e.target.value)} className="max-w-[10rem]">
            <option value="">All statuses</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {value.charAt(0) + value.slice(1).toLowerCase()}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 py-2 text-sm text-ink-600">
            <input
              type="checkbox"
              checked={expiringSoon}
              onChange={(e) => setFilter('expiring_soon', e.target.checked ? '1' : '')}
              className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
            />
            Expiring soon
          </label>
        </FilterBar>

        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState
            title="No members match these filters"
            description={debounced ? `Nothing found for “${debounced}”.` : 'Register the first member to get started.'}
          />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(member) => member.id}
              card={(member) => (
                <RecordCard
                  to={`/members/${member.id}`}
                  title={member.full_name}
                  subtitle={member.membership_number}
                  badge={<StatusBadge status={member.status} />}
                  facts={[
                    { label: 'Phone', value: member.telephone ?? '—' },
                    {
                      label: 'Membership',
                      value: member.current_membership?.plan_name ?? <span className="text-ink-400">none</span>,
                    },
                    {
                      label: 'Expires',
                      value: member.membership_expires_on ? (
                        <>
                          {date(member.membership_expires_on)}
                          <span className="block text-xs text-ink-500">
                            {expiryLabel(member.current_membership?.days_remaining)}
                          </span>
                        </>
                      ) : (
                        '—'
                      ),
                    },
                    {
                      label: 'Last visit',
                      value: member.last_visit_at ? relative(member.last_visit_at) : 'never',
                    },
                  ]}
                />
              )}
              table={
                <Table>
                  <thead>
                    <tr>
                      <Th>Member</Th>
                      <Th>Contact</Th>
                      <Th>Membership</Th>
                      <Th>Expires</Th>
                      <Th>Last visit</Th>
                      <Th align="right">Status</Th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink-100">
                    {data.data.map((member) => (
                      <tr key={member.id} className="hover:bg-ink-50">
                        <Td>
                          <MemberCell member={member} />
                        </Td>
                        <Td>
                          <span className="block text-sm">{member.telephone ?? '—'}</span>
                          <span className="block text-xs text-ink-500">{member.email ?? ''}</span>
                        </Td>
                        <Td>{member.current_membership?.plan_name ?? <span className="text-ink-400">none</span>}</Td>
                        <Td>
                          {member.membership_expires_on ? (
                            <>
                              <span className="block text-sm">{date(member.membership_expires_on)}</span>
                              <span className="block text-xs text-ink-500">
                                {expiryLabel(member.current_membership?.days_remaining)}
                              </span>
                            </>
                          ) : (
                            <span className="text-ink-400">—</span>
                          )}
                        </Td>
                        <Td>
                          <span className="text-sm">{member.last_visit_at ? relative(member.last_visit_at) : '—'}</span>
                          <span className="block text-xs text-ink-500">{member.total_visits} visits</span>
                        </Td>
                        <Td align="right">
                          <StatusBadge status={member.status} />
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

      <RegisterMemberModal
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={(message) => {
          setCreating(false)
          toast.success('Member registered', message)
          queryClient.invalidateQueries({ queryKey: ['members'] })
        }}
      />
    </>
  )
}

/**
 * SRS 32 steps 1-2 - registration and the membership sale in one form,
 * because that is one conversation at the counter, not two.
 */
function RegisterMemberModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: (message?: string) => void
}) {
  const [form, setForm] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: plans } = useQuery({
    queryKey: ['plans'],
    queryFn: async () => (await api.get<{ data: MembershipPlan[] }>('/membership-plans')).data.data,
    enabled: open,
  })

  const create = useMutation({
    mutationFn: async (payload: Record<string, string>) => (await api.post('/members', payload)).data,
    onSuccess: (result: { message?: string }) => {
      setForm({})
      setErrors({})
      setError(null)
      onCreated(result?.message)
    },
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = (key: string) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const submit = (event: FormEvent) => {
    event.preventDefault()
    create.mutate(Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')))
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Register a new member"
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="register-member" type="submit" loading={create.isPending}>
            Register member
          </Button>
        </>
      }
    >
      <form id="register-member" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label="Full name" required error={errors.full_name}>
              <Input value={form.full_name ?? ''} onChange={set('full_name')} required autoFocus />
            </Field>
          </div>

          <Field label="Telephone" error={errors.telephone}>
            <Input value={form.telephone ?? ''} onChange={set('telephone')} inputMode="tel" />
          </Field>

          <Field label="NIC / Passport" error={errors.nic_passport}>
            <Input value={form.nic_passport ?? ''} onChange={set('nic_passport')} />
          </Field>

          <Field label="Email" error={errors.email}>
            <Input type="email" value={form.email ?? ''} onChange={set('email')} />
          </Field>

          <Field label="Date of birth" error={errors.date_of_birth}>
            <Input type="date" value={form.date_of_birth ?? ''} onChange={set('date_of_birth')} />
          </Field>

          <Field label="Gender">
            <Select value={form.gender ?? ''} onChange={set('gender')}>
              <option value="">Not stated</option>
              <option value="MALE">Male</option>
              <option value="FEMALE">Female</option>
              <option value="OTHER">Other</option>
            </Select>
          </Field>

          <Field label="City">
            <Input value={form.city ?? ''} onChange={set('city')} />
          </Field>

          <div className="sm:col-span-2">
            <Field label="Address">
              <Input value={form.address ?? ''} onChange={set('address')} />
            </Field>
          </div>

          <Field label="Emergency contact name">
            <Input value={form.emergency_contact_name ?? ''} onChange={set('emergency_contact_name')} />
          </Field>

          <Field label="Emergency contact number">
            <Input value={form.emergency_contact_number ?? ''} onChange={set('emergency_contact_number')} />
          </Field>
        </div>

        <div className="rounded-lg border border-ink-200 bg-ink-50 p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-600">
            Membership (optional)
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Package" hint="An invoice is raised; the membership activates once paid.">
              <Select value={form.membership_plan_id ?? ''} onChange={set('membership_plan_id')}>
                <option value="">Add later</option>
                {plans?.map((plan) => (
                  <option key={plan.id} value={plan.id}>
                    {plan.name} — {plan.duration_label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Start date" hint="Defaults to today.">
              <Input type="date" value={form.membership_start_date ?? ''} onChange={set('membership_start_date')} />
            </Field>
          </div>
        </div>
      </form>
    </Modal>
  )
}
