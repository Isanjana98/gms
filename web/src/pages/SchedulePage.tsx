import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
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
  StatusBadge,
  Table,
  Td,
  Textarea,
  Th,
  cx,
} from '../components/ui'
import { WeeklySchedule, todayDayOfWeek, type ScheduleDay, type ScheduleExercise } from '../components/WeeklySchedule'
import { useToast } from '../components/Toast'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date } from '../lib/format'
import type { MemberSummary, Paginated, Trainer } from '../lib/types'

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const GOALS = [
  ['WEIGHT_LOSS', 'Weight loss'],
  ['MUSCLE_GAIN', 'Muscle gain'],
  ['ENDURANCE', 'Endurance'],
  ['STRENGTH', 'Strength'],
  ['GENERAL_FITNESS', 'General fitness'],
  ['REHAB', 'Rehabilitation'],
] as const

interface PlanRow {
  id: number
  member_id: number
  trainer_id: number | null
  title: string
  goal: string | null
  status: string
  start_date: string | null
  end_date: string | null
  exercises_count: number
  member?: MemberSummary
  trainer?: { id: number; full_name: string } | null
}

interface ScheduleResponse {
  member: { id: number; full_name: string; membership_number: string }
  plan: {
    id: number
    title: string
    description: string | null
    goal: string | null
    status: string
    start_date: string | null
    end_date: string | null
    trainer: string | null
    notes: string | null
  } | null
  days: ScheduleDay[]
  every_session: ScheduleExercise[]
}

/** SRS 20 - Gym schedules across all members. */
export function SchedulePage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()

  const [status, setStatus] = useState('ACTIVE')
  const [trainerId, setTrainerId] = useState('')
  const [viewing, setViewing] = useState<number | null>(null)
  const [editing, setEditing] = useState<PlanRow | 'new' | null>(null)

  const plans = useQuery({
    queryKey: ['schedules', status, trainerId],
    queryFn: async () =>
      (
        await api.get<Paginated<PlanRow>>('/workout-plans', {
          params: { status: status || undefined, trainer_id: trainerId || undefined, per_page: 100 },
        })
      ).data,
  })

  const trainers = useQuery({
    queryKey: ['trainers'],
    queryFn: async () => (await api.get<{ data: Trainer[] }>('/trainers')).data.data,
  })

  const canManage = can(P.workoutsManage)
  const rows = plans.data?.data ?? []

  return (
    <>
      <PageHeader
        title="Gym schedules"
        subtitle="Weekly training programmes"
        actions={canManage && <Button onClick={() => setEditing('new')}>Create schedule</Button>}
      />

      <Card padded={false}>
        <FilterBar>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="max-w-[11rem]">
            <option value="ACTIVE">Active</option>
            <option value="DRAFT">Draft</option>
            <option value="COMPLETED">Completed</option>
            <option value="">All</option>
          </Select>

          <Select value={trainerId} onChange={(e) => setTrainerId(e.target.value)} className="max-w-[13rem]">
            <option value="">All trainers</option>
            {trainers.data?.map((trainer) => (
              <option key={trainer.id} value={trainer.id}>
                {trainer.full_name}
              </option>
            ))}
          </Select>

          <span className="ml-auto text-xs text-ink-500">
            {rows.length} schedule{rows.length === 1 ? '' : 's'}
          </span>
        </FilterBar>

        {plans.error ? (
          <div className="p-5">
            <Alert>{errorMessage(plans.error)}</Alert>
          </div>
        ) : plans.isLoading ? (
          <LoadingBlock />
        ) : rows.length === 0 ? (
          <EmptyState
            title="No schedules yet"
            description="Create a weekly training programme for a member."
            action={canManage && <Button onClick={() => setEditing('new')}>Create schedule</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Member</Th>
                <Th>Programme</Th>
                <Th>Trainer</Th>
                <Th>Training days</Th>
                <Th>Period</Th>
                <Th align="right">Status</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {rows.map((plan) => (
                <tr key={plan.id} className="hover:bg-ink-50">
                  <Td>
                    {plan.member ? (
                      <Link to={`/members/${plan.member_id}`} className="font-medium hover:text-brand-700">
                        {plan.member.full_name}
                        <span className="block text-xs font-normal text-ink-500">{plan.member.membership_number}</span>
                      </Link>
                    ) : (
                      <span className="text-ink-400">—</span>
                    )}
                  </Td>
                  <Td>
                    <span className="font-medium">{plan.title}</span>
                    <span className="block text-xs text-ink-500">
                      {plan.goal ? plan.goal.replace(/_/g, ' ').toLowerCase() : ''} · {plan.exercises_count} exercises
                    </span>
                  </Td>
                  <Td>{plan.trainer?.full_name ?? <span className="text-ink-400">unassigned</span>}</Td>
                  <Td>
                    <DayDots planId={plan.id} />
                  </Td>
                  <Td>
                    <span className="text-xs text-ink-600">
                      {plan.start_date ? date(plan.start_date) : '—'}
                      {plan.end_date ? ` → ${date(plan.end_date)}` : ''}
                    </span>
                  </Td>
                  <Td align="right">
                    <StatusBadge status={plan.status} />
                  </Td>
                  <Td align="right">
                    <div className="flex justify-end gap-1.5">
                      <Button size="sm" variant="secondary" onClick={() => setViewing(plan.member_id)}>
                        View week
                      </Button>
                      {canManage && (
                        <Button size="sm" variant="ghost" onClick={() => setEditing(plan)}>
                          Edit
                        </Button>
                      )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {viewing !== null && <ViewWeekModal memberId={viewing} onClose={() => setViewing(null)} />}

      {editing && (
        <ScheduleEditor
          plan={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            toast.success(message ?? 'Schedule saved.')
            setEditing(null)
            queryClient.invalidateQueries({ queryKey: ['schedules'] })
            queryClient.invalidateQueries({ queryKey: ['member-schedule'] })
          }}
        />
      )}
    </>
  )
}

/**
 * Which days of the week this programme covers, as seven dots.
 *
 * Cheap to scan down a column, and it answers the question a trainer has when
 * they open this list: is anyone's week lopsided or empty?
 */
function DayDots({ planId }: { planId: number }) {
  const { data } = useQuery({
    queryKey: ['plan-days', planId],
    queryFn: async () => (await api.get<{ data: { exercises: ScheduleExercise[] } }>(`/workout-plans/${planId}`)).data.data,
    staleTime: 5 * 60_000,
  })

  const active = new Set((data?.exercises ?? []).map((e) => e.day_of_week).filter(Boolean) as number[])
  const today = todayDayOfWeek()

  return (
    <div className="flex gap-1">
      {DAY_LABELS.map((label, index) => {
        const day = index + 1
        const on = active.has(day)

        return (
          <span
            key={label}
            title={label}
            className={cx(
              'flex h-5 w-6 items-center justify-center rounded text-[10px] font-medium',
              on ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-400',
              day === today && 'ring-2 ring-brand-300 ring-offset-1',
            )}
          >
            {label[0]}
          </span>
        )
      })}
    </div>
  )
}

function ViewWeekModal({ memberId, onClose }: { memberId: number; onClose: () => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['member-schedule', memberId],
    queryFn: async () => (await api.get<ScheduleResponse>(`/members/${memberId}/schedule`)).data,
  })

  return (
    <Modal
      open
      onClose={onClose}
      wide
      title={data ? `${data.member.full_name} — training week` : 'Training week'}
      footer={
        <>
          <Button variant="secondary" onClick={() => window.print()}>
            Print
          </Button>
          <Button onClick={onClose}>Close</Button>
        </>
      }
    >
      {error ? (
        <Alert>{errorMessage(error)}</Alert>
      ) : isLoading ? (
        <LoadingBlock />
      ) : !data?.plan ? (
        <EmptyState title="This member has no schedule yet" />
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="font-semibold text-ink-900">{data.plan.title}</p>
              <p className="text-xs text-ink-500">
                {data.plan.goal ? data.plan.goal.replace(/_/g, ' ').toLowerCase() : 'no goal set'}
                {data.plan.trainer ? ` · ${data.plan.trainer}` : ''}
              </p>
            </div>
            <StatusBadge status={data.plan.status} />
          </div>

          <WeeklySchedule days={data.days} everySession={data.every_session} compact />

          {data.plan.notes && <p className="rounded-lg bg-ink-50 p-3 text-xs text-ink-600">{data.plan.notes}</p>}
        </div>
      )}
    </Modal>
  )
}

/* ------------------------------------------------------------------ */
/* Editor                                                              */
/* ------------------------------------------------------------------ */

interface ExerciseDraft {
  key: string
  day_of_week: string
  exercise_name: string
  muscle_group: string
  sets: string
  repetitions: string
  weight_kg: string
  duration_seconds: string
  notes: string
}

const emptyExercise = (day: string): ExerciseDraft => ({
  key: Math.random().toString(36).slice(2),
  day_of_week: day,
  exercise_name: '',
  muscle_group: '',
  sets: '',
  repetitions: '',
  weight_kg: '',
  duration_seconds: '',
  notes: '',
})

/**
 * The schedule editor.
 *
 * Built day by day rather than as one flat list, because that is how a trainer
 * writes a programme: they decide Monday is legs, then fill Monday in. A flat
 * list with a day dropdown on every row makes them say "Monday" six times.
 */
function ScheduleEditor({
  plan,
  onClose,
  onSaved,
}: {
  plan: PlanRow | null
  onClose: () => void
  onSaved: (message?: string) => void
}) {
  const [memberId, setMemberId] = useState(plan ? String(plan.member_id) : '')
  const [memberSearch, setMemberSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [form, setForm] = useState({
    title: plan?.title ?? '',
    goal: plan?.goal ?? '',
    trainer_id: plan?.trainer_id ? String(plan.trainer_id) : '',
    start_date: plan?.start_date ?? '',
    end_date: plan?.end_date ?? '',
    status: plan?.status ?? 'ACTIVE',
    notes: '',
  })
  const [exercises, setExercises] = useState<ExerciseDraft[]>([])
  const [openDay, setOpenDay] = useState<number>(todayDayOfWeek())
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(memberSearch.trim()), 250)
    return () => clearTimeout(timer)
  }, [memberSearch])

  // Load the existing programme into the editor.
  const existing = useQuery({
    queryKey: ['plan-detail', plan?.id],
    queryFn: async () => (await api.get(`/workout-plans/${plan!.id}`)).data.data,
    enabled: !!plan,
  })

  useEffect(() => {
    if (!existing.data) return

    setForm((f) => ({ ...f, notes: existing.data.notes ?? '' }))
    setExercises(
      (existing.data.exercises ?? []).map((e: ScheduleExercise) => ({
        key: String(e.id),
        day_of_week: e.day_of_week ? String(e.day_of_week) : '',
        exercise_name: e.exercise_name ?? '',
        muscle_group: e.muscle_group ?? '',
        sets: e.sets ? String(e.sets) : '',
        repetitions: e.repetitions ?? '',
        weight_kg: e.weight_kg ? String(e.weight_kg) : '',
        duration_seconds: e.duration_seconds ? String(e.duration_seconds) : '',
        notes: e.notes ?? '',
      })),
    )
  }, [existing.data])

  const members = useQuery({
    queryKey: ['schedule-member-search', debouncedSearch],
    queryFn: async () =>
      (await api.get<Paginated<MemberSummary>>('/members', { params: { search: debouncedSearch, per_page: 8 } })).data
        .data,
    enabled: !plan && debouncedSearch.length >= 2,
  })

  const trainers = useQuery({
    queryKey: ['trainers'],
    queryFn: async () => (await api.get<{ data: Trainer[] }>('/trainers')).data.data,
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        member_id: Number(memberId),
        title: form.title,
        goal: form.goal || null,
        trainer_id: form.trainer_id ? Number(form.trainer_id) : null,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        status: form.status,
        notes: form.notes || null,
        exercises: exercises
          // A blank row is someone who clicked "Add" and changed their mind;
          // sending it would just fail validation on a field they never filled.
          .filter((e) => e.exercise_name.trim() !== '')
          .map((e, index) => ({
            day_of_week: e.day_of_week ? Number(e.day_of_week) : null,
            sort_order: index,
            exercise_name: e.exercise_name.trim(),
            muscle_group: e.muscle_group || null,
            sets: e.sets ? Number(e.sets) : null,
            repetitions: e.repetitions || null,
            weight_kg: e.weight_kg ? Number(e.weight_kg) : null,
            duration_seconds: e.duration_seconds ? Number(e.duration_seconds) : null,
            notes: e.notes || null,
          })),
      }

      return plan
        ? (await api.put(`/workout-plans/${plan.id}`, payload)).data
        : (await api.post('/workout-plans', payload)).data
    },
    onSuccess: (result: { message?: string }) => onSaved(result?.message),
    onError: (err) => setError(errorMessage(err)),
  })

  const forDay = (day: number) => exercises.filter((e) => e.day_of_week === String(day))
  const everySession = exercises.filter((e) => e.day_of_week === '')

  const update = (key: string, field: keyof ExerciseDraft, value: string) =>
    setExercises((prev) => prev.map((e) => (e.key === key ? { ...e, [field]: value } : e)))

  const remove = (key: string) => setExercises((prev) => prev.filter((e) => e.key !== key))

  const selectedMember = members.data?.find((m) => String(m.id) === memberId)

  return (
    <Modal
      open
      onClose={onClose}
      wide
      title={plan ? `Edit schedule — ${plan.member?.full_name ?? ''}` : 'Create gym schedule'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={!memberId || !form.title} onClick={() => save.mutate()}>
            {plan ? 'Save schedule' : 'Create schedule'}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}

        {!plan && (
          <Field label="Member" required>
            {memberId && selectedMember ? (
              <div className="flex items-center justify-between rounded-lg border border-ink-200 px-3 py-2">
                <span className="text-sm">
                  {selectedMember.full_name}
                  <span className="ml-2 text-xs text-ink-500">{selectedMember.membership_number}</span>
                </span>
                <Button size="sm" variant="ghost" onClick={() => setMemberId('')}>
                  Change
                </Button>
              </div>
            ) : (
              <>
                <Input
                  value={memberSearch}
                  onChange={(e) => setMemberSearch(e.target.value)}
                  placeholder="Search by name or membership number…"
                />
                {members.data && members.data.length > 0 && (
                  <div className="mt-1.5 divide-y divide-ink-100 rounded-lg border border-ink-200">
                    {members.data.map((member) => (
                      <button
                        key={member.id}
                        type="button"
                        onClick={() => setMemberId(String(member.id))}
                        className="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-ink-50"
                      >
                        <span>{member.full_name}</span>
                        <span className="text-xs text-ink-500">{member.membership_number}</span>
                      </button>
                    ))}
                  </div>
                )}
              </>
            )}
          </Field>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label="Programme name" required>
              <Input
                value={form.title}
                onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
                placeholder="Beginner strength — 3 days"
              />
            </Field>
          </div>

          <Field label="Goal">
            <Select value={form.goal} onChange={(e) => setForm((f) => ({ ...f, goal: e.target.value }))}>
              <option value="">Not set</option>
              {GOALS.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Trainer">
            <Select value={form.trainer_id} onChange={(e) => setForm((f) => ({ ...f, trainer_id: e.target.value }))}>
              <option value="">Unassigned</option>
              {trainers.data?.map((trainer) => (
                <option key={trainer.id} value={trainer.id}>
                  {trainer.full_name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Starts">
            <Input
              type="date"
              value={form.start_date}
              onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))}
            />
          </Field>

          <Field label="Ends">
            <Input
              type="date"
              value={form.end_date}
              onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))}
            />
          </Field>

          <Field label="Status">
            <Select value={form.status} onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}>
              <option value="ACTIVE">Active</option>
              <option value="DRAFT">Draft</option>
              <option value="COMPLETED">Completed</option>
              <option value="CANCELLED">Cancelled</option>
            </Select>
          </Field>
        </div>

        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-600">Training week</p>

          <div className="mb-3 flex flex-wrap gap-1">
            {DAY_LABELS.map((label, index) => {
              const day = index + 1
              const count = forDay(day).length

              return (
                <button
                  key={label}
                  type="button"
                  onClick={() => setOpenDay(day)}
                  className={cx(
                    'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    openDay === day ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
                  )}
                >
                  {label}
                  {count > 0 && (
                    <span
                      className={cx(
                        'rounded px-1 text-[10px]',
                        openDay === day ? 'bg-white/25' : 'bg-white text-ink-600',
                      )}
                    >
                      {count}
                    </span>
                  )}
                </button>
              )
            })}
            <button
              type="button"
              onClick={() => setOpenDay(0)}
              className={cx(
                'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                openDay === 0 ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
              )}
            >
              Every session
              {everySession.length > 0 && (
                <span className={cx('rounded px-1 text-[10px]', openDay === 0 ? 'bg-white/25' : 'bg-white text-ink-600')}>
                  {everySession.length}
                </span>
              )}
            </button>
          </div>

          <div className="space-y-2 rounded-lg border border-ink-200 p-3">
            {(openDay === 0 ? everySession : forDay(openDay)).map((exercise) => (
              <div key={exercise.key} className="grid gap-2 rounded-lg bg-ink-50 p-2.5 sm:grid-cols-12">
                <div className="sm:col-span-4">
                  <Input
                    value={exercise.exercise_name}
                    onChange={(e) => update(exercise.key, 'exercise_name', e.target.value)}
                    placeholder="Exercise"
                  />
                </div>
                <div className="sm:col-span-3">
                  <Input
                    value={exercise.muscle_group}
                    onChange={(e) => update(exercise.key, 'muscle_group', e.target.value)}
                    placeholder="Muscle group"
                  />
                </div>
                <div className="sm:col-span-1">
                  <Input
                    value={exercise.sets}
                    onChange={(e) => update(exercise.key, 'sets', e.target.value)}
                    placeholder="Sets"
                    inputMode="numeric"
                  />
                </div>
                <div className="sm:col-span-2">
                  <Input
                    value={exercise.repetitions}
                    onChange={(e) => update(exercise.key, 'repetitions', e.target.value)}
                    placeholder="Reps"
                  />
                </div>
                <div className="sm:col-span-1">
                  <Input
                    value={exercise.weight_kg}
                    onChange={(e) => update(exercise.key, 'weight_kg', e.target.value)}
                    placeholder="kg"
                    inputMode="decimal"
                  />
                </div>
                <div className="flex items-center justify-end sm:col-span-1">
                  <Button size="sm" variant="ghost" onClick={() => remove(exercise.key)} aria-label="Remove exercise">
                    ✕
                  </Button>
                </div>
              </div>
            ))}

            {(openDay === 0 ? everySession : forDay(openDay)).length === 0 && (
              <p className="py-3 text-center text-xs text-ink-400">
                {openDay === 0 ? 'Nothing applies to every session.' : 'Rest day — no exercises set.'}
              </p>
            )}

            <Button
              size="sm"
              variant="secondary"
              onClick={() => setExercises((prev) => [...prev, emptyExercise(openDay === 0 ? '' : String(openDay))])}
            >
              + Add exercise
            </Button>
          </div>
        </div>

        <Field label="Notes for the member">
          <Textarea value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} rows={2} />
        </Field>

        {exercises.filter((e) => e.exercise_name.trim()).length === 0 && (
          <Badge tone="warning">A schedule with no exercises will be saved as an empty programme.</Badge>
        )}
      </div>
    </Modal>
  )
}
