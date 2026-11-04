<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\WorkoutPlan;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** SRS 20 - Workout plans and the weekly training schedule. */
class WorkoutPlanController extends Controller
{
    /** 1 = Monday, matching the day_of_week column. */
    private const DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * A trainer may only open a schedule that is theirs, or belongs to a
     * member assigned to them. Mirrors the list filter, because a restriction
     * that only applies to the list is not a restriction - the ids are
     * sequential and guessable.
     */
    private function assertTrainerMaySee(Request $request, ?WorkoutPlan $plan, Member $member): void
    {
        if ($request->user()->can(Permissions::WORKOUTS_VIEW_ALL)) {
            return;
        }

        $trainer = $request->user()->trainer;

        // No trainer record means no members of their own, so nothing here is
        // theirs to read. Fail closed rather than waving them through.
        abort_if($trainer === null, 403, 'Your account is not linked to a trainer record.');

        $theirs = $member->trainer_id === $trainer->id || $plan?->trainer_id === $trainer->id;

        abort_unless($theirs, 403, 'This member is not assigned to you.');
    }

    /** GET /api/workout-plans */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_VIEW), 403);

        $plans = WorkoutPlan::query()
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('trainer_id'), fn ($q) => $q->where('trainer_id', $request->integer('trainer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // A trainer sees the schedules they wrote and those of the members
            // assigned to them - not the whole gym's. Reception and admins
            // hold WORKOUTS_VIEW_ALL and skip the restriction.
            //
            // Keyed on the permission, not on whether a trainer record exists:
            // keying on the record meant a Trainer-role account that had never
            // been linked to one fell through the filter and saw everything,
            // which is the opposite of what should happen when the link is
            // missing.
            ->unless(
                $request->user()->can(Permissions::WORKOUTS_VIEW_ALL),
                fn ($q) => $q->where(function ($w) use ($request) {
                    $trainerId = $request->user()->trainer?->id;

                    if ($trainerId === null) {
                        // No trainer record: no members of their own, so
                        // nothing to show. Fail closed.
                        $w->whereRaw('1 = 0');

                        return;
                    }

                    $w->where('trainer_id', $trainerId)
                        ->orWhereIn('member_id', fn ($sub) => $sub->select('id')->from('members')->where('trainer_id', $trainerId));
                }),
            )
            ->with(['member:id,full_name,membership_number', 'trainer:id,full_name'])
            ->withCount('exercises')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json($plans);
    }

    /**
     * GET /api/members/{member}/schedule
     *
     * SRS 20 - One member's training week, already grouped by day.
     *
     * The grouping is done here rather than in the interface because the
     * member profile, the schedule page and any future print view all want
     * the same shape, and three implementations of "which day is this on"
     * would eventually disagree.
     */
    public function schedule(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_VIEW), 403);

        $plan = WorkoutPlan::query()
            ->where('member_id', $member->getKey())
            ->whereIn('status', ['ACTIVE', 'DRAFT'])
            ->with(['exercises', 'trainer:id,full_name'])
            ->orderByRaw("FIELD(status, 'ACTIVE', 'DRAFT')")
            ->orderByDesc('start_date')
            ->first();

        $this->assertTrainerMaySee($request, $plan, $member);

        $exercises = $plan?->exercises ?? collect();

        return response()->json([
            'member' => [
                'id' => $member->getKey(),
                'full_name' => $member->full_name,
                'membership_number' => $member->membership_number,
            ],
            'plan' => $plan ? [
                'id' => $plan->id,
                'title' => $plan->title,
                'description' => $plan->description,
                'goal' => $plan->goal,
                'status' => $plan->status,
                'start_date' => $plan->start_date?->toDateString(),
                'end_date' => $plan->end_date?->toDateString(),
                'trainer' => $plan->trainer?->full_name,
                'notes' => $plan->notes,
            ] : null,
            'days' => collect(range(1, 7))->map(fn (int $day) => [
                'day_of_week' => $day,
                'label' => self::DAY_NAMES[$day],
                'exercises' => $exercises->where('day_of_week', $day)->values()->all(),
            ])->all(),
            // Exercises with no day set apply to every session - a warm-up,
            // or a programme written without fixed training days.
            'every_session' => $exercises->whereNull('day_of_week')->values()->all(),
        ]);
    }

    /**
     * POST /api/workout-plans
     *
     * The plan and its exercises are created together: a programme with no
     * exercises is not a programme, and two requests invite half of one.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_MANAGE), 403);

        $data = $request->validate($this->rules());

        $plan = DB::transaction(function () use ($data, $request) {
            $plan = WorkoutPlan::create([
                ...collect($data)->except('exercises')->all(),
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['exercises'] ?? [] as $index => $exercise) {
                $plan->exercises()->create([...$exercise, 'sort_order' => $exercise['sort_order'] ?? $index]);
            }

            return $plan;
        });

        return response()->json([
            'message' => "Workout plan \"{$plan->title}\" created.",
            'data' => $plan->load(['exercises', 'member:id,full_name', 'trainer:id,full_name']),
        ], 201);
    }

    /** GET /api/workout-plans/{workoutPlan} */
    public function show(Request $request, WorkoutPlan $workoutPlan): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_VIEW), 403);

        return response()->json([
            'data' => $workoutPlan->load(['exercises', 'member:id,full_name,membership_number', 'trainer:id,full_name']),
        ]);
    }

    /**
     * PUT /api/workout-plans/{workoutPlan}
     *
     * Exercises, when supplied, replace the existing set. Diffing them
     * individually would need stable client-side ids for rows a trainer
     * reorders freely; replacing is predictable and the set is small.
     */
    public function update(Request $request, WorkoutPlan $workoutPlan): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_MANAGE), 403);

        $data = $request->validate($this->rules(updating: true));

        DB::transaction(function () use ($workoutPlan, $data) {
            $workoutPlan->update(collect($data)->except('exercises')->all());

            if (array_key_exists('exercises', $data)) {
                $workoutPlan->exercises()->delete();

                foreach ($data['exercises'] as $index => $exercise) {
                    $workoutPlan->exercises()->create([...$exercise, 'sort_order' => $exercise['sort_order'] ?? $index]);
                }
            }
        });

        return response()->json(['data' => $workoutPlan->fresh()->load('exercises')]);
    }

    /** DELETE /api/workout-plans/{workoutPlan} */
    public function destroy(Request $request, WorkoutPlan $workoutPlan): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WORKOUTS_MANAGE), 403);

        $workoutPlan->delete();

        return $this->ok('Workout plan deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'member_id' => [$required, 'integer', 'exists:members,id'],
            'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'goal' => ['nullable', Rule::in(['WEIGHT_LOSS', 'MUSCLE_GAIN', 'ENDURANCE', 'STRENGTH', 'GENERAL_FITNESS', 'REHAB'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'])],
            'notes' => ['nullable', 'string', 'max:2000'],

            'exercises' => ['nullable', 'array', 'max:200'],
            'exercises.*.day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'exercises.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'exercises.*.exercise_name' => ['required', 'string', 'max:255'],
            'exercises.*.muscle_group' => ['nullable', 'string', 'max:100'],
            'exercises.*.sets' => ['nullable', 'integer', 'min:1', 'max:100'],
            'exercises.*.repetitions' => ['nullable', 'string', 'max:50'],
            'exercises.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'exercises.*.duration_seconds' => ['nullable', 'integer', 'min:0'],
            'exercises.*.rest_seconds' => ['nullable', 'integer', 'min:0'],
            'exercises.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
