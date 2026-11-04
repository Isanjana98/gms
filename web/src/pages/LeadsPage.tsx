import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  FilterBar,
  Input,
  LoadingBlock,
  Modal,
  PageHeader,
  Select,
  Table,
  Td,
  Textarea,
  Th,
  cx,
} from '../components/ui'
import { useConfirm } from '../components/Confirm'
import { Pager, RecordCard, RecordList } from '../components/RecordList'
import { useToast } from '../components/Toast'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { dateTime, relative } from '../lib/format'

interface Lead {
  id: number
  name: string
  telephone: string | null
  email: string | null
  message: string | null
  plan: string | null
  source: string
  status: 'NEW' | 'CONTACTED' | 'CONVERTED' | 'CLOSED'
  notes: string | null
  contacted_at: string | null
  handled_by: string | null
  converted_member: { id: number; full_name: string; membership_number: string } | null
  created_at: string
}

interface LeadPage {
  data: Lead[]
  meta?: { current_page: number; last_page: number; total: number }
  summary?: { new: number; contacted: number; converted: number; closed: number }
}

const STATUS_TONE: Record<Lead['status'], 'info' | 'warning' | 'success' | 'neutral'> = {
  NEW: 'info',
  CONTACTED: 'warning',
  CONVERTED: 'success',
  CLOSED: 'neutral',
}

/**
 * Enquiries from the website.
 *
 * The screen leads with how many are waiting rather than how many there are in
 * total: an enquiry nobody has rung is the only number here that needs acting
 * on today.
 */
export function LeadsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const navigate = useNavigate()

  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Lead | null>(null)

  const canManage = can(P.leadsManage)

  const { data, isLoading, error } = useQuery({
    queryKey: ['leads', status, search, page],
    queryFn: async () =>
      (
        await api.get<LeadPage>('/leads', {
          params: { status: status || undefined, search: search || undefined, page, per_page: 25 },
        })
      ).data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['leads'] })

  const setStatusFor = useMutation({
    mutationFn: async ({ lead, to }: { lead: Lead; to: Lead['status'] }) =>
      (await api.put(`/leads/${lead.id}`, { status: to })).data,
    onSuccess: () => {
      toast.success('Enquiry updated.')
      refresh()
    },
    onError: (err) => toast.error('Could not update the enquiry', errorMessage(err)),
  })

  const convert = useMutation({
    mutationFn: async (lead: Lead) => (await api.post(`/leads/${lead.id}/convert`)).data,
    onSuccess: (result: { message: string; member_id: number }) => {
      toast.success(result.message)
      refresh()
      // Straight to the new member, because the next thing reception does is
      // sell them a membership.
      navigate(`/members/${result.member_id}`)
    },
    onError: (err) => toast.error('Could not convert the enquiry', errorMessage(err)),
  })

  const askConvert = async (lead: Lead) => {
    const ok = await confirm({
      title: `Add ${lead.name} as a member?`,
      confirmLabel: 'Create member',
      message: (
        <>
          A member record will be created from this enquiry{lead.telephone ? ` with the number ${lead.telephone}` : ''}.
        </>
      ),
      detail: <>They will have no membership yet — you will be taken to their profile to sell them one.</>,
    })

    if (ok) convert.mutate(lead)
  }

  const askDelete = async (lead: Lead) => {
    const ok = await confirm({
      title: 'Delete this enquiry?',
      tone: 'danger',
      confirmLabel: 'Delete enquiry',
      message: (
        <>
          The enquiry from <strong>{lead.name}</strong> will be removed.
        </>
      ),
    })

    if (!ok) return

    try {
      await api.delete(`/leads/${lead.id}`)
      toast.success('Enquiry deleted.')
      refresh()
    } catch (err) {
      toast.error('Could not delete the enquiry', errorMessage(err))
    }
  }

  const summary = data?.summary
  const meta = data?.meta

  return (
    <>
      <PageHeader title="Enquiries" subtitle="People who contacted you through the website" />

      <div className="mb-5 grid gap-4 sm:grid-cols-4">
        {(
          [
            ['New', summary?.new ?? 0, 'text-brand-700'],
            ['Contacted', summary?.contacted ?? 0, 'text-amber-700'],
            ['Converted', summary?.converted ?? 0, 'text-emerald-700'],
            ['Closed', summary?.closed ?? 0, 'text-ink-500'],
          ] as const
        ).map(([label, value, tone]) => (
          <Card key={label}>
            <p className="text-xs uppercase tracking-wide text-ink-500">{label}</p>
            <p className={cx('mt-1 text-2xl font-semibold tabular', tone)}>{value}</p>
          </Card>
        ))}
      </div>

      <Card padded={false}>
        <FilterBar>
          <label className="text-xs font-medium text-ink-600">
            Status
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} className="mt-1">
              <option value="">All enquiries</option>
              <option value="NEW">New</option>
              <option value="CONTACTED">Contacted</option>
              <option value="CONVERTED">Converted</option>
              <option value="CLOSED">Closed</option>
            </Select>
          </label>
          <label className="text-xs font-medium text-ink-600">
            Search
            <Input
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
              placeholder="Name, phone or email"
              className="mt-1"
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
          <EmptyState
            title="No enquiries yet"
            description="Messages sent through the website's contact form appear here."
          />
        ) : (
          <>
            <RecordList
              items={data.data}
              keyOf={(lead) => lead.id}
              card={(lead) => (
                <RecordCard
                  title={lead.name}
                  subtitle={lead.telephone ?? lead.email ?? undefined}
                  badge={<Badge tone={STATUS_TONE[lead.status]}>{lead.status.toLowerCase()}</Badge>}
                  facts={[
                    { label: 'Received', value: relative(lead.created_at) },
                    { label: 'Interested in', value: lead.plan ?? '—' },
                  ]}
                  note={lead.message}
                  actions={
                    canManage && (
                      <>
                        {lead.status === 'NEW' && (
                          <Button size="sm" variant="ghost" onClick={() => setStatusFor.mutate({ lead, to: 'CONTACTED' })}>
                            Mark contacted
                          </Button>
                        )}
                        {!lead.converted_member && (
                          <Button size="sm" variant="ghost" onClick={() => askConvert(lead)}>
                            Convert
                          </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setEditing(lead)}>
                          Notes
                        </Button>
                      </>
                    )
                  }
                />
              )}
              table={
                <Table>
                  <thead>
                    <tr>
                      <Th>Name</Th>
                      <Th>Contact</Th>
                      <Th>Interested in</Th>
                      <Th>Received</Th>
                      <Th>Status</Th>
                      <Th align="right">Actions</Th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink-100">
                    {data.data.map((lead) => (
                      <tr key={lead.id} className="hover:bg-ink-50">
                        <Td>
                          <span className="font-medium">{lead.name}</span>
                          {lead.message && (
                            <span className="block max-w-sm truncate text-xs text-ink-500">{lead.message}</span>
                          )}
                        </Td>
                        <Td>
                          {lead.telephone && (
                            <a className="block text-sm text-brand-700" href={`tel:${lead.telephone}`}>
                              {lead.telephone}
                            </a>
                          )}
                          {lead.email && <span className="block text-xs text-ink-500">{lead.email}</span>}
                          {!lead.telephone && !lead.email && <span className="text-ink-400">—</span>}
                        </Td>
                        <Td>{lead.plan ?? <span className="text-ink-400">—</span>}</Td>
                        <Td>
                          <span className="block text-sm">{relative(lead.created_at)}</span>
                          <span className="block text-xs text-ink-500">{dateTime(lead.created_at)}</span>
                        </Td>
                        <Td>
                          <Badge tone={STATUS_TONE[lead.status]}>{lead.status.toLowerCase()}</Badge>
                          {lead.converted_member && (
                            <a
                              className="mt-1 block text-xs text-brand-700"
                              href={`/members/${lead.converted_member.id}`}
                            >
                              {lead.converted_member.membership_number}
                            </a>
                          )}
                        </Td>
                        <Td align="right">
                          {canManage && (
                            <div className="flex justify-end gap-1.5">
                              {lead.status === 'NEW' && (
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => setStatusFor.mutate({ lead, to: 'CONTACTED' })}
                                >
                                  Mark contacted
                                </Button>
                              )}
                              {!lead.converted_member && (
                                <Button size="sm" variant="ghost" onClick={() => askConvert(lead)}>
                                  Convert
                                </Button>
                              )}
                              <Button size="sm" variant="ghost" onClick={() => setEditing(lead)}>
                                Notes
                              </Button>
                            </div>
                          )}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              }
            />

            <Pager page={meta?.current_page ?? page} lastPage={meta?.last_page ?? 1} onChange={setPage} />
          </>
        )}
      </Card>

      {editing && (
        <LeadNotes
          lead={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
          onDelete={() => {
            const lead = editing
            setEditing(null)
            askDelete(lead)
          }}
        />
      )}
    </>
  )
}

function LeadNotes({
  lead,
  onClose,
  onSaved,
  onDelete,
}: {
  lead: Lead
  onClose: () => void
  onSaved: () => void
  onDelete: () => void
}) {
  const toast = useToast()
  const [notes, setNotes] = useState(lead.notes ?? '')
  const [status, setStatus] = useState<Lead['status']>(lead.status)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: async () => (await api.put(`/leads/${lead.id}`, { notes, status })).data,
    onSuccess: () => {
      toast.success('Enquiry updated.')
      onSaved()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={lead.name}
      footer={
        <>
          <Button variant="secondary" onClick={onDelete}>
            Delete
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="rounded-lg bg-ink-50 p-3 text-sm">
          <p className="text-ink-500">
            {dateTime(lead.created_at)} · {lead.source.toLowerCase()}
          </p>
          {lead.plan && <p className="mt-1">Asked about: {lead.plan}</p>}
          {lead.message && <p className="mt-1 wrap-anywhere text-ink-800">{lead.message}</p>}
          {lead.handled_by && <p className="mt-1 text-xs text-ink-500">Last handled by {lead.handled_by}</p>}
        </div>

        <Field label="Status">
          <Select value={status} onChange={(e) => setStatus(e.target.value as Lead['status'])}>
            <option value="NEW">New</option>
            <option value="CONTACTED">Contacted</option>
            <option value="CLOSED">Closed — not interested</option>
          </Select>
        </Field>

        <Field label="Notes" hint="What was discussed, and what happens next.">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}
