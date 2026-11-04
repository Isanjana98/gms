import { Badge, EmptyState, cx } from './ui'

export interface ScheduleExercise {
  id: number
  day_of_week: number | null
  exercise_name: string
  muscle_group?: string | null
  sets?: number | null
  repetitions?: string | null
  weight_kg?: number | string | null
  duration_seconds?: number | null
  rest_seconds?: number | null
  notes?: string | null
}

export interface ScheduleDay {
  day_of_week: number
  label: string
  exercises: ScheduleExercise[]
}

/** "3 × 8-10 @ 40kg" — the shorthand a trainer writes on a card. */
export function prescription(exercise: ScheduleExercise): string {
  const parts: string[] = []

  if (exercise.sets && exercise.repetitions) parts.push(`${exercise.sets} × ${exercise.repetitions}`)
  else if (exercise.sets) parts.push(`${exercise.sets} sets`)
  else if (exercise.repetitions) parts.push(`${exercise.repetitions} reps`)

  if (exercise.weight_kg && Number(exercise.weight_kg) > 0) parts.push(`${Number(exercise.weight_kg)}kg`)

  if (exercise.duration_seconds) {
    parts.push(
      exercise.duration_seconds >= 60
        ? `${Math.round(exercise.duration_seconds / 60)} min`
        : `${exercise.duration_seconds}s`,
    )
  }

  return parts.join(' · ')
}

/** Today, as 1 = Monday, to match day_of_week. */
export function todayDayOfWeek(): number {
  const js = new Date().getDay()
  return js === 0 ? 7 : js
}

/**
 * SRS 20 - A member's training week.
 *
 * Seven columns rather than a list, because the question a member actually
 * asks at the counter is "what am I doing today?" — and a grid answers it at a
 * glance, with today picked out.
 *
 * It stays a grid down to tablet width and becomes a stack of day cards on a
 * phone, where seven columns would be unreadable.
 */
export function WeeklySchedule({
  days,
  everySession = [],
  compact = false,
}: {
  days: ScheduleDay[]
  everySession?: ScheduleExercise[]
  compact?: boolean
}) {
  const today = todayDayOfWeek()
  const hasAnything = days.some((d) => d.exercises.length > 0) || everySession.length > 0

  if (!hasAnything) {
    return (
      <EmptyState
        title="No training days set"
        description="Add exercises to this schedule and assign each one a day of the week."
      />
    )
  }

  return (
    <div className="space-y-4">
      {everySession.length > 0 && (
        <div className="rounded-lg border border-brand-200 bg-brand-50 p-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-brand-800">Every session</p>
          <ul className="space-y-1.5">
            {everySession.map((exercise) => (
              <ExerciseLine key={exercise.id} exercise={exercise} />
            ))}
          </ul>
        </div>
      )}

      <div className={cx('grid gap-3', compact ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 xl:grid-cols-4')}>
        {days.map((day) => {
          const isToday = day.day_of_week === today
          const isRest = day.exercises.length === 0

          return (
            <div
              key={day.day_of_week}
              className={cx(
                'rounded-lg border p-3',
                isToday ? 'border-brand-400 bg-brand-50/60 ring-1 ring-brand-200' : 'border-ink-200 bg-white',
                isRest && !isToday && 'bg-ink-50',
              )}
            >
              <div className="mb-2 flex items-center justify-between gap-2">
                <p className={cx('text-xs font-semibold uppercase tracking-wide', isToday ? 'text-brand-800' : 'text-ink-600')}>
                  {day.label.slice(0, 3)}
                </p>
                {isToday && <Badge tone="info">today</Badge>}
              </div>

              {isRest ? (
                <p className="text-xs text-ink-400">Rest day</p>
              ) : (
                <>
                  {/* The muscle groups are the headline: a member scanning the
                      week wants "legs" before they want "back squat". */}
                  <p className="mb-2 text-sm font-medium text-ink-900">
                    {[...new Set(day.exercises.map((e) => e.muscle_group).filter(Boolean))].join(' · ') ||
                      `${day.exercises.length} exercises`}
                  </p>
                  <ul className="space-y-1.5">
                    {day.exercises.map((exercise) => (
                      <ExerciseLine key={exercise.id} exercise={exercise} />
                    ))}
                  </ul>
                </>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function ExerciseLine({ exercise }: { exercise: ScheduleExercise }) {
  const detail = prescription(exercise)

  return (
    <li className="text-xs leading-snug">
      <span className="block font-medium text-ink-800">{exercise.exercise_name}</span>
      {detail && <span className="block tabular text-ink-500">{detail}</span>}
      {exercise.notes && <span className="block italic text-ink-400">{exercise.notes}</span>}
    </li>
  )
}
