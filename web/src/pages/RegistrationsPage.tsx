import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
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
} from '../components/ui'
import { Pager } from '../components/RecordList'
import { useToast } from '../components/Toast'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { dateTime, relative } from '../lib/format'

type Status = 'PENDING' | 'APPROVED' | 'REJECTED'

interface Registration {
  id: number
  full_name: string
  nic_passport: string | null
  date_of_birth: string | null
  gender: string | null
  address: string | null
  city: string | null
  telephone: string | null
  email: string | null
  emergency_contact_name: string | null
  emergency_contact_number: string | null
  parq_flag_count: number
  custom_answers: CustomAnswer[] | null
  signed_name: string | null
  signed_at: string | null
  declaration: string | null
  status: Status
  review_notes: string | null
  reviewed_at: string | null
  created_at: string
  reviewed_by: { id: number; name: string } | null
  member: { id: number; membership_number: string; full_name: string } | null
}

interface Answer {
  id: string
  text: string
  answer: boolean | null
  detail: string | null
}

/**
 * One answer to a question the gym added itself.
 *
 * `label` is stored with the answer rather than looked up, so editing the
 * question later cannot change what this person appears to have been asked.
 */
interface CustomAnswer {
  key: string
  label: string
  type: 'BOOLEAN' | 'TEXT' | 'TEXTAREA' | 'CHOICE'
  answer: string | boolean
  detail?: string | null
}

interface Question {
  id: number
  key: string
  label: string
  help: string | null
  type: 'BOOLEAN' | 'TEXT' | 'TEXTAREA' | 'CHOICE'
  options: string[] | null
  is_required: boolean
  sort_order: number
  is_active: boolean
}

interface Paginated {
  data: Registration[]
  current_page: number
  last_page: number
  total: number
}

const STATUS_TONE: Record<Status, 'warning' | 'success' | 'neutral'> = {
  PENDING: 'warning',
  APPROVED: 'success',
  REJECTED: 'neutral',
}

/**
 * Registrations people filled in themselves, from the QR code at the desk.
 *
 * Nothing on this page has created a member yet. That is the whole reason it
 * exists: the form is open to anyone who can see a printed code, so a human
 * decides which submissions become members.
 */
export function RegistrationsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [status, setStatus] = useState('PENDING')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [viewing, setViewing] = useState<number | null>(null)
  const [showQr, setShowQr] = useState(false)
  const [showQuestions, setShowQuestions] = useState(false)

  const canReview = can(P.registrationsReview)

  const { data, isLoading, error } = useQuery({
    queryKey: ['member-registrations', status, search, page],
    queryFn: async () =>
      (
        await api.get<Paginated>('/member-registrations', {
          params: { status: status || undefined, search: search || undefined, page, per_page: 25 },
        })
      ).data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['member-registrations'] })

  return (
    <>
      <PageHeader
        title="Self-registrations"
        subtitle="People who filled in the joining form on their own phone. Nobody becomes a member until you approve them."
        actions={
          <div className="flex flex-wrap gap-2">
            {can(P.settingsManage) && (
              <Button variant="ghost" onClick={() => setShowQuestions(true)}>
                Form questions
              </Button>
            )}
            <Button variant="secondary" onClick={() => setShowQr(true)}>
              Show the QR code
            </Button>
          </div>
        }
      />

      <FilterBar>
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} aria-label="Status">
          <option value="PENDING">Waiting for review</option>
          <option value="APPROVED">Approved</option>
          <option value="REJECTED">Rejected</option>
          <option value="">All</option>
        </Select>
        <Input
          placeholder="Name, phone or NIC"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          aria-label="Search registrations"
        />
      </FilterBar>

      {error && <Alert tone="danger">{errorMessage(error)}</Alert>}

      {isLoading ? (
        <LoadingBlock />
      ) : (data?.data.length ?? 0) === 0 ? (
        <EmptyState
          title="Nothing waiting"
          description="When somebody scans the QR code and fills the form in, they appear here for you to check."
        />
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>Name</Th>
                <Th>Contact</Th>
                <Th>Health</Th>
                <Th>Sent</Th>
                <Th>Status</Th>
                <Th> </Th>
              </tr>
            </thead>
            <tbody>
              {data?.data.map((r) => (
                <tr key={r.id}>
                  <Td>
                    <span className="font-medium text-ink-900">{r.full_name}</span>
                    {r.nic_passport && <span className="block text-xs text-ink-500">{r.nic_passport}</span>}
                  </Td>
                  <Td>
                    {r.telephone ?? <span className="text-ink-400">no number</span>}
                    {r.email && <span className="block text-xs text-ink-500">{r.email}</span>}
                  </Td>
                  <Td>
                    {r.parq_flag_count > 0 ? (
                      <Badge tone="warning">
                        {r.parq_flag_count} to discuss
                      </Badge>
                    ) : (
                      <span className="text-ink-500">Clear</span>
                    )}
                  </Td>
                  <Td>
                    {/* Td takes no title, so the exact timestamp hangs off a
                        span inside it rather than the cell. */}
                    <span title={dateTime(r.created_at)}>{relative(r.created_at)}</span>
                  </Td>
                  <Td>
                    <Badge tone={STATUS_TONE[r.status]}>{r.status.toLowerCase()}</Badge>
                    {r.member && (
                      <span className="block text-xs text-ink-500">{r.member.membership_number}</span>
                    )}
                  </Td>
                  <Td>
                    <Button size="sm" variant="ghost" onClick={() => setViewing(r.id)}>
                      Open
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>

          <Pager
            page={data?.current_page ?? 1}
            lastPage={data?.last_page ?? 1}
            onChange={setPage}
            className="mt-4"
          />
        </Card>
      )}

      {viewing !== null && (
        <RegistrationModal
          id={viewing}
          canReview={canReview}
          onClose={() => setViewing(null)}
          onDone={(memberId) => {
            setViewing(null)
            refresh()
            // Straight to the new member: the next thing reception does is
            // sell them a membership.
            if (memberId) navigate(`/members/${memberId}`)
          }}
        />
      )}

      {showQr && <QrModal onClose={() => setShowQr(false)} />}
      {showQuestions && <QuestionsModal onClose={() => setShowQuestions(false)} />}
    </>
  )
}

/**
 * The gym's own extra questions.
 *
 * The seven PAR-Q questions are not here and cannot be edited: their wording
 * is a standard instrument, and changing it would make the answers mean
 * something only this gym recognises.
 */
function QuestionsModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()

  const [label, setLabel] = useState('')
  const [help, setHelp] = useState('')
  const [type, setType] = useState<Question['type']>('TEXT')
  const [options, setOptions] = useState('')
  const [required, setRequired] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['registration-questions'],
    queryFn: async () => (await api.get<{ data: Question[] }>('/registration-questions')).data.data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['registration-questions'] })

  const add = useMutation({
    mutationFn: async () =>
      (
        await api.post('/registration-questions', {
          label,
          help: help || undefined,
          type,
          is_required: required,
          options:
            type === 'CHOICE'
              ? options.split('\n').map((o) => o.trim()).filter(Boolean)
              : undefined,
          sort_order: (data?.length ?? 0) + 1,
        })
      ).data,
    onSuccess: () => {
      toast.success('Question added.')
      setLabel('')
      setHelp('')
      setOptions('')
      setRequired(false)
      refresh()
    },
    onError: (err) => toast.error('Could not add the question', errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: async (q: Question) => (await api.delete(`/registration-questions/${q.id}`)).data,
    onSuccess: () => {
      toast.success('Question removed from the form.')
      refresh()
    },
    onError: (err) => toast.error('Could not remove the question', errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      wide
      title="Questions on the joining form"
      footer={
        <Button variant="secondary" onClick={onClose}>
          Done
        </Button>
      }
    >
      <div className="space-y-5">
        <Alert tone="info">
          The seven standard health questions are fixed and always asked. Anything you add here appears below
          them. Editing a question later never changes what somebody who already signed was asked.
        </Alert>

        {isLoading ? (
          <LoadingBlock />
        ) : (data?.length ?? 0) === 0 ? (
          <p className="text-sm text-ink-500">You have not added any questions of your own yet.</p>
        ) : (
          <ul className="divide-y divide-ink-100 rounded-lg border border-ink-200">
            {data?.map((q) => (
              <li key={q.id} className="flex items-start gap-3 p-3">
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-medium text-ink-900">{q.label}</span>
                  {q.help && <span className="block text-xs text-ink-500">{q.help}</span>}
                  <span className="mt-1 flex flex-wrap gap-1">
                    <Badge>{q.type.toLowerCase()}</Badge>
                    {q.is_required && <Badge tone="info">required</Badge>}
                    {!q.is_active && <Badge tone="neutral">hidden</Badge>}
                  </span>
                </span>
                <Button
                  size="sm"
                  variant="ghost"
                  loading={remove.isPending && remove.variables?.id === q.id}
                  onClick={() => remove.mutate(q)}
                >
                  Remove
                </Button>
              </li>
            ))}
          </ul>
        )}

        <div className="rounded-lg border border-ink-200 p-4">
          <h3 className="mb-3 text-sm font-semibold text-ink-900">Add a question</h3>

          <div className="space-y-3">
            <Input
              placeholder="e.g. Do you have any injuries we should know about?"
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              maxLength={255}
              aria-label="Question"
            />

            <Input
              placeholder="Helper text, shown underneath (optional)"
              value={help}
              onChange={(e) => setHelp(e.target.value)}
              maxLength={255}
              aria-label="Helper text"
            />

            <Select value={type} onChange={(e) => setType(e.target.value as Question['type'])} aria-label="Answer type">
              <option value="TEXT">Short answer</option>
              <option value="TEXTAREA">Long answer</option>
              <option value="BOOLEAN">Yes / No (with a box for detail on yes)</option>
              <option value="CHOICE">Choose one</option>
            </Select>

            {type === 'CHOICE' && (
              <Textarea
                placeholder={'One option per line\nFacebook\nA friend\nWalked past'}
                value={options}
                onChange={(e) => setOptions(e.target.value)}
                rows={4}
                aria-label="Options"
              />
            )}

            <label className="flex items-center gap-2 text-sm text-ink-700">
              <input type="checkbox" checked={required} onChange={(e) => setRequired(e.target.checked)} />
              They must answer this
            </label>

            <Button
              disabled={label.trim().length < 3}
              loading={add.isPending}
              onClick={() => add.mutate()}
            >
              Add question
            </Button>
          </div>
        </div>
      </div>
    </Modal>
  )
}

/** One registration: what they typed, what they answered, what they signed. */
function RegistrationModal({
  id,
  canReview,
  onClose,
  onDone,
}: {
  id: number
  canReview: boolean
  onClose: () => void
  onDone: (memberId?: number) => void
}) {
  const toast = useToast()
  const [notes, setNotes] = useState('')
  const [rejecting, setRejecting] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['member-registration', id],
    queryFn: async () =>
      (
        await api.get<{ data: Registration; questions: Answer[]; has_signature: boolean }>(
          `/member-registrations/${id}`,
        )
      ).data,
  })

  const approve = useMutation({
    mutationFn: async () => (await api.post(`/member-registrations/${id}/approve`)).data,
    onSuccess: (result: { message: string; member: { id: number } }) => {
      toast.success(result.message)
      onDone(result.member.id)
    },
    onError: (err) => toast.error('Could not approve this registration', errorMessage(err)),
  })

  const reject = useMutation({
    mutationFn: async () => (await api.post(`/member-registrations/${id}/reject`, { notes: notes || undefined })).data,
    onSuccess: () => {
      toast.success('Registration rejected.')
      onDone()
    },
    onError: (err) => toast.error('Could not reject this registration', errorMessage(err)),
  })

  const r = data?.data
  const pending = r?.status === 'PENDING'

  return (
    <Modal
      open
      onClose={onClose}
      wide
      title={r ? r.full_name : 'Registration'}
      footer={
        canReview && pending ? (
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="ghost" onClick={() => setRejecting((v) => !v)}>
              {rejecting ? 'Cancel' : 'Reject'}
            </Button>
            {rejecting ? (
              <Button variant="danger" loading={reject.isPending} onClick={() => reject.mutate()}>
                Confirm rejection
              </Button>
            ) : (
              <Button loading={approve.isPending} onClick={() => approve.mutate()}>
                Approve and create member
              </Button>
            )}
          </div>
        ) : (
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
        )
      }
    >
      {isLoading || !r ? (
        <LoadingBlock />
      ) : (
        <div className="space-y-5">
          {r.parq_flag_count > 0 && (
            <Alert tone="warning">
              They answered yes to {r.parq_flag_count} health {r.parq_flag_count === 1 ? 'question' : 'questions'}.
              Approving is fine — it just means somebody should talk it through before they start.
            </Alert>
          )}

          {r.member && (
            <Alert tone="success">
              Already approved as {r.member.membership_number}.
            </Alert>
          )}

          <section>
            <h3 className="mb-2 text-sm font-semibold text-ink-900">Their details</h3>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              <Detail label="Phone" value={r.telephone} />
              <Detail label="E-mail" value={r.email} />
              <Detail label="NIC / passport" value={r.nic_passport} />
              <Detail label="Date of birth" value={r.date_of_birth} />
              <Detail label="Gender" value={r.gender} />
              <Detail label="City" value={r.city} />
              <Detail label="Address" value={r.address} />
              <Detail label="Emergency contact" value={r.emergency_contact_name} />
              <Detail label="Emergency number" value={r.emergency_contact_number} />
            </dl>
          </section>

          <section>
            <h3 className="mb-2 text-sm font-semibold text-ink-900">Health questions</h3>
            <ul className="space-y-2 text-sm">
              {data.questions.map((q, i) => (
                <li key={q.id} className="flex gap-3">
                  <span className="text-ink-400">{i + 1}.</span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-ink-700">{q.text}</span>
                    {q.detail && <span className="mt-1 block text-xs text-ink-500">{q.detail}</span>}
                  </span>
                  <Badge tone={q.answer ? 'warning' : 'neutral'}>{q.answer ? 'Yes' : 'No'}</Badge>
                </li>
              ))}
            </ul>
          </section>

          {r.custom_answers && r.custom_answers.length > 0 && (
            <section>
              <h3 className="mb-2 text-sm font-semibold text-ink-900">Your own questions</h3>
              <dl className="space-y-2 text-sm">
                {r.custom_answers.map((a) => (
                  <div key={a.key}>
                    {/* The wording stored with the answer, not today's. */}
                    <dt className="text-xs uppercase tracking-wide text-ink-500">{a.label}</dt>
                    <dd className="text-ink-800">
                      {typeof a.answer === 'boolean' ? (a.answer ? 'Yes' : 'No') : a.answer}
                      {a.detail && <span className="block text-xs text-ink-500">{a.detail}</span>}
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          )}

          <section>
            <h3 className="mb-2 text-sm font-semibold text-ink-900">Signature</h3>
            {data.has_signature ? (
              <>
                {/*
                  Loaded straight from the API: the session is an httpOnly
                  cookie and this is a same-origin GET, so the browser sends
                  it without any work here. The endpoint streams from the
                  private disk and is never publicly reachable.
                */}
                <img
                  src={`/api/member-registrations/${r.id}/signature`}
                  alt={`Signature of ${r.signed_name ?? r.full_name}`}
                  className="max-h-40 rounded-lg border border-ink-200 bg-white"
                />
                <p className="mt-2 text-xs text-ink-500">
                  Signed as <span className="font-medium text-ink-700">{r.signed_name}</span>
                  {r.signed_at && <> on {dateTime(r.signed_at)}</>}
                </p>
              </>
            ) : (
              <p className="text-sm text-ink-500">No signature was stored.</p>
            )}
          </section>

          {rejecting && (
            <section>
              <h3 className="mb-2 text-sm font-semibold text-ink-900">Why are you rejecting this?</h3>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={3}
                maxLength={500}
                placeholder="Duplicate of an existing member, test submission, obvious nonsense…"
              />
              <p className="mt-1 text-xs text-ink-500">
                Only staff see this. The person is not told.
              </p>
            </section>
          )}

          {r.reviewed_by && (
            <p className="text-xs text-ink-500">
              Reviewed by {r.reviewed_by.name}
              {r.reviewed_at && <> on {dateTime(r.reviewed_at)}</>}
              {r.review_notes && <> — {r.review_notes}</>}
            </p>
          )}
        </div>
      )}
    </Modal>
  )
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-ink-500">{label}</dt>
      <dd className="text-ink-800">{value || <span className="text-ink-400">—</span>}</dd>
    </div>
  )
}

/** The code to print and put on the wall. */
function QrModal({ onClose }: { onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['registration-qr'],
    queryFn: async () =>
      (await api.get<{ branch: { name: string }; url: string; qr_svg: string }>('/member-registrations/qr')).data,
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Joining QR code"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
          <Button onClick={() => window.print()}>Print</Button>
        </div>
      }
    >
      {isLoading || !data ? (
        <LoadingBlock />
      ) : (
        <div className="text-center">
          <p className="mb-4 text-sm text-ink-600">
            Print this and put it where new people can see it. It never expires, so the same sheet keeps working.
          </p>

          <div
            className="mx-auto w-fit rounded-xl border border-ink-200 bg-white p-4"
            // The service returns a self-contained SVG with no script in it.
            dangerouslySetInnerHTML={{ __html: data.qr_svg }}
          />

          <p className="mt-4 text-sm font-medium text-ink-900">{data.branch.name}</p>
          <p className="mt-1 break-all text-xs text-ink-500">{data.url}</p>
        </div>
      )}
    </Modal>
  )
}
