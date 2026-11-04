import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  EmptyState,
  Field,
  LoadingBlock,
  Modal,
  Select,
  Textarea,
} from './ui'
import { useToast } from './Toast'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date, dateTime } from '../lib/format'

/**
 * A member's PAR-Q screening.
 *
 * Two jobs on one screen, because they are two halves of the same
 * conversation at the counter: hand them the form, and deal with what comes
 * back. Everything else about a screening — the answers, the signature, the
 * provenance — is read-only here, because it is evidence and nothing in the
 * interface should imply otherwise.
 */

interface ScreeningRow {
  id: number
  reference: string
  status: string
  has_flags: boolean
  flag_count: number
  review_status: string
  signed_at: string | null
  signed_name: string | null
  expires_at: string | null
  link_usable: boolean
  reviewed_by: string | null
  reviewed_at: string | null
  review_notes: string | null
  created_at: string | null
}

interface ScreeningDetail extends ScreeningRow {
  answers: Record<string, { answer: boolean; detail: string | null }> | null
  flagged: Array<{ id: string; text: string; detail: string | null }>
  questionnaire_version: string | null
  terms_version: string | null
  ip_address: string | null
  user_agent: string | null
  record_hash: string | null
  record_intact: boolean
  issued_by: string | null
  document_id: number | null
}

const REVIEW_LABEL: Record<string, string> = {
  NOT_REQUIRED: 'No review needed',
  PENDING: 'Review outstanding',
  CLEARED: 'Cleared to train',
  DOCTOR_NOTE_REQUIRED: "Doctor's note required",
  RESTRICTED: 'Training restricted',
}

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

function reviewTone(status: string): Tone {
  switch (status) {
    case 'CLEARED':
      return 'success'
    // Outstanding, not failed: somebody still has to act on it.
    case 'PENDING':
      return 'warning'
    case 'DOCTOR_NOTE_REQUIRED':
    case 'RESTRICTED':
      return 'danger'
    default:
      return 'neutral'
  }
}

export function MemberHealth({ memberId }: { memberId: number }) {
  const { can } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()

  const [qr, setQr] = useState<{ svg: string; url: string; expires: string | null } | null>(null)
  const [viewing, setViewing] = useState<number | null>(null)

  const query = useQuery({
    queryKey: ['member-health', memberId],
    queryFn: async () =>
      (
        await api.get<{ data: ScreeningRow[]; current: ScreeningDetail | null; pending_link: string | null }>(
          `/members/${memberId}/health-screenings`,
        )
      ).data,
  })

  const issue = useMutation({
    mutationFn: async () =>
      (await api.post<{ qr_svg: string; url: string; expires_at: string | null }>(
        `/members/${memberId}/health-screenings`,
        {},
      )).data,
    onSuccess: (data) => {
      setQr({ svg: data.qr_svg, url: data.url, expires: data.expires_at })
      void queryClient.invalidateQueries({ queryKey: ['member-health', memberId] })
      void queryClient.invalidateQueries({ queryKey: ['health-queue'] })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  if (query.isLoading) return <LoadingBlock />
  if (query.error) return <Alert tone="danger">{errorMessage(query.error)}</Alert>

  const rows = query.data?.data ?? []
  const current = query.data?.current ?? null

  return (
    <div className="space-y-4">
      {/* ------------------------------------------------- what we know */}
      {current ? (
        <div
          className={`rounded-lg border p-4 ${
            current.review_status === 'PENDING'
              ? 'border-amber-300 bg-amber-50 dark:border-amber-800/60 dark:bg-amber-950/30'
              : current.has_flags
                ? 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40'
                : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/30'
          }`}
        >
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={reviewTone(current.review_status)}>{REVIEW_LABEL[current.review_status]}</Badge>
            <span className="text-sm text-slate-600 dark:text-slate-400">
              {current.reference} &middot; signed {dateTime(current.signed_at)}
            </span>
          </div>

          <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">
            {current.has_flags
              ? `Answered yes to ${current.flag_count} of 7 questions.`
              : 'Answered no to all seven questions.'}
          </p>

          {!current.record_intact && (
            <Alert tone="danger">
              This record does not match the hash taken when it was signed. It has been altered since.
            </Alert>
          )}

          <div className="mt-3 flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => setViewing(current.id)}>
              See the answers
            </Button>
            {can(P.healthIssue) && (
              <Button variant="ghost" onClick={() => issue.mutate()} loading={issue.isPending}>
                Screen them again
              </Button>
            )}
          </div>
        </div>
      ) : (
        <EmptyState
          title="No health form on file"
          description="A PAR-Q tells you whether somebody should speak to a doctor before they start. Issue one and let them fill it in on their own phone."
          action={
            can(P.healthIssue) ? (
              <Button onClick={() => issue.mutate()} loading={issue.isPending}>
                Issue a health form
              </Button>
            ) : undefined
          }
        />
      )}

      {/* --------------------------------------------------- the history */}
      {rows.length > 0 && (
        <div className="rounded-lg border border-slate-200 dark:border-slate-700">
          <div className="border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
            All screenings
          </div>
          <ul className="divide-y divide-slate-200 dark:divide-slate-700">
            {rows.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-medium">{row.reference}</div>
                  <div className="text-xs text-slate-500 dark:text-slate-400">
                    {row.status === 'COMPLETED'
                      ? `Signed ${dateTime(row.signed_at)}`
                      : row.link_usable
                        ? `Link sent, expires ${dateTime(row.expires_at)}`
                        : row.status === 'PENDING'
                          ? 'Link expired, never completed'
                          : 'Superseded'}
                  </div>
                </div>
                {row.status === 'COMPLETED' ? (
                  <>
                    <Badge tone={row.has_flags ? 'warning' : 'success'}>
                      {row.has_flags ? `${row.flag_count} flagged` : 'All clear'}
                    </Badge>
                    <Button variant="ghost" onClick={() => setViewing(row.id)}>
                      Open
                    </Button>
                  </>
                ) : (
                  <Badge tone="neutral">{row.status.toLowerCase()}</Badge>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* ------------------------------------------------------- the QR */}
      <Modal
        open={qr !== null}
        onClose={() => setQr(null)}
        title="Ask them to scan this"
      >
        {qr && (
          <div className="space-y-4 text-center">
            <p className="text-sm text-slate-600 dark:text-slate-400">
              They fill the form in on their own phone. It saves here the moment they sign.
            </p>

            {/* The SVG is generated on our server from a URL we built, so it
                carries nothing a member supplied. */}
            <div
              className="mx-auto w-fit rounded-lg bg-white p-4"
              dangerouslySetInnerHTML={{ __html: qr.svg }}
            />

            <div className="space-y-1">
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Or send them this link &mdash; it expires {dateTime(qr.expires)}.
              </p>
              <div className="flex gap-2">
                <input
                  readOnly
                  value={qr.url}
                  onFocus={(event) => event.currentTarget.select()}
                  className="min-w-0 flex-1 rounded border border-slate-300 bg-slate-50 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-800"
                />
                <Button
                  variant="secondary"
                  onClick={() => {
                    void navigator.clipboard?.writeText(qr.url)
                    toast.success('Link copied.')
                  }}
                >
                  Copy
                </Button>
              </div>
            </div>

            <Alert tone="warning">
              This QR opens one person&rsquo;s health form. Do not leave it on screen once they have scanned it.
            </Alert>
          </div>
        )}
      </Modal>

      {viewing !== null && (
        <ScreeningModal id={viewing} onClose={() => setViewing(null)} memberId={memberId} />
      )}
    </div>
  )
}

/* -------------------------------------------------------- the detail view */

function ScreeningModal({
  id,
  memberId,
  onClose,
}: {
  id: number
  memberId: number
  onClose: () => void
}) {
  const { can } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()

  const [outcome, setOutcome] = useState('CLEARED')
  const [notes, setNotes] = useState('')

  const query = useQuery({
    queryKey: ['health-screening', id],
    queryFn: async () =>
      (
        await api.get<{
          data: ScreeningDetail
          questions: Array<{ id: string; text: string }>
        }>(`/health-screenings/${id}`)
      ).data,
  })

  const review = useMutation({
    mutationFn: async () =>
      (await api.post(`/health-screenings/${id}/review`, { outcome, notes })).data,
    onSuccess: () => {
      toast.success('Review recorded.')
      void queryClient.invalidateQueries({ queryKey: ['health-screening', id] })
      void queryClient.invalidateQueries({ queryKey: ['member-health', memberId] })
      void queryClient.invalidateQueries({ queryKey: ['health-queue'] })
      setNotes('')
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  const screening = query.data?.data
  const questions = query.data?.questions ?? []

  return (
    <Modal open onClose={onClose} title={screening?.reference ?? 'Health screening'} wide>
      {query.isLoading && <LoadingBlock />}
      {query.error && <Alert tone="danger">{errorMessage(query.error)}</Alert>}

      {screening && (
        <div className="space-y-5">
          {!screening.record_intact && (
            <Alert tone="danger">
              This record does not match the hash taken when it was signed. Treat it as unreliable and find out
              who changed it.
            </Alert>
          )}

          {/* ------------------------------------------------- answers */}
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
              The questions, as they were asked
            </h3>
            <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
              {questions.map((question, index) => {
                const answer = screening.answers?.[question.id]
                const yes = Boolean(answer?.answer)

                return (
                  <li key={question.id} className="flex gap-3 px-3 py-2.5">
                    <span className="w-4 shrink-0 text-xs text-slate-400">{index + 1}.</span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm">{question.text}</p>
                      {yes && answer?.detail && (
                        <p className="mt-1 border-l-2 border-red-400 bg-red-50 px-2 py-1 text-sm text-slate-700 dark:bg-red-950/30 dark:text-slate-300">
                          {answer.detail}
                        </p>
                      )}
                    </div>
                    <span
                      className={`shrink-0 text-sm font-bold ${yes ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'}`}
                    >
                      {yes ? 'YES' : 'NO'}
                    </span>
                  </li>
                )
              })}
            </ul>
          </div>

          {/* ---------------------------------------------- provenance */}
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Signature
            </h3>
            <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
              <dt className="text-slate-500 dark:text-slate-400">Signed by</dt>
              <dd className="font-medium">{screening.signed_name}</dd>
              <dt className="text-slate-500 dark:text-slate-400">When</dt>
              <dd>{dateTime(screening.signed_at)}</dd>
              <dt className="text-slate-500 dark:text-slate-400">From</dt>
              <dd>{screening.ip_address ?? '—'}</dd>
              <dt className="text-slate-500 dark:text-slate-400">Terms</dt>
              <dd>
                v{screening.terms_version} &middot; PAR-Q v{screening.questionnaire_version}
              </dd>
            </dl>
            <p className="mt-2 break-all font-mono text-[11px] text-slate-400">
              {screening.record_hash}
            </p>
          </div>

          {/* -------------------------------------------------- review */}
          {screening.review_status === 'PENDING' && can(P.healthReview) ? (
            <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800/60 dark:bg-amber-950/30">
              <h3 className="text-sm font-semibold">Record your review</h3>
              <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Speak to them first. What you write here is the record that this was dealt with.
              </p>

              <div className="mt-3 space-y-3">
                <Field label="Outcome">
                  <Select value={outcome} onChange={(event) => setOutcome(event.target.value)}>
                    <option value="CLEARED">Cleared to train</option>
                    <option value="DOCTOR_NOTE_REQUIRED">Doctor&rsquo;s note required first</option>
                    <option value="RESTRICTED">Training restricted</option>
                  </Select>
                </Field>

                <Field label="What was decided, and why">
                  <Textarea
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    rows={3}
                    placeholder="Spoke to them about the shoulder; no overhead pressing for now."
                  />
                </Field>

                <Button onClick={() => review.mutate()} loading={review.isPending} disabled={notes.trim().length < 3}>
                  Record review
                </Button>
              </div>
            </div>
          ) : screening.reviewed_at ? (
            <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={reviewTone(screening.review_status)}>
                  {REVIEW_LABEL[screening.review_status]}
                </Badge>
                <span className="text-sm text-slate-500 dark:text-slate-400">
                  by {screening.reviewed_by}, {date(screening.reviewed_at)}
                </span>
              </div>
              {screening.review_notes && (
                <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">{screening.review_notes}</p>
              )}
            </div>
          ) : screening.review_status === 'PENDING' ? (
            <Alert tone="warning">
              This needs a review, which your account cannot record. Ask a trainer or an administrator.
            </Alert>
          ) : null}

          <div className="flex justify-end">
            <Button
              variant="secondary"
              onClick={() => {
                // Through the API client, so it carries the session and the
                // file is never on a public URL.
                void api
                  .get(`/health-screenings/${id}/pdf`, { responseType: 'blob' })
                  .then((response) => {
                    const url = URL.createObjectURL(response.data as Blob)
                    window.open(url, '_blank')
                    setTimeout(() => URL.revokeObjectURL(url), 60_000)
                  })
                  .catch((error) => toast.error(errorMessage(error)))
              }}
            >
              Open the signed PDF
            </Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
