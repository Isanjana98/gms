<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Http\Resources\TrainerResource;
use App\Models\Trainer;
use App\Services\NumberGenerator;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** SRS 19 - Trainers. */
class TrainerController extends Controller
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /** GET /api/trainers */
    public function index(Request $request): AnonymousResourceCollection
    {
        $trainers = Trainer::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->withCount('members')
            ->with('branch:id,name,code')
            ->orderBy('full_name')
            ->get();

        return TrainerResource::collection($trainers);
    }

    /** POST /api/trainers */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::TRAINERS_MANAGE), 403);

        $data = $request->validate($this->rules());

        $trainer = $this->numbers->withRetry(fn () => Trainer::create([
            ...$data,
            'branch_id' => $data['branch_id'] ?? $this->defaultBranchId($request),
            'trainer_code' => $this->numbers->trainerCode(),
        ]));

        return (new TrainerResource($trainer))
            ->additional(['message' => "Trainer {$trainer->trainer_code} added."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/trainers/{trainer} */
    public function show(Trainer $trainer): TrainerResource
    {
        $trainer->loadCount('members')->load('branch:id,name,code');

        return new TrainerResource($trainer);
    }

    /** PUT /api/trainers/{trainer} */
    public function update(Request $request, Trainer $trainer): TrainerResource
    {
        abort_unless($request->user()->can(Permissions::TRAINERS_MANAGE), 403);

        $trainer->update($request->validate($this->rules(updating: true)));

        return new TrainerResource($trainer);
    }

    /** DELETE /api/trainers/{trainer} */
    public function destroy(Request $request, Trainer $trainer): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::TRAINERS_MANAGE), 403);

        // Members keep their history but lose the assignment, rather than the
        // delete failing and leaving the trainer in place.
        $trainer->members()->update(['trainer_id' => null]);
        $trainer->delete();

        return $this->ok("{$trainer->full_name} removed. Their members are now unassigned.");
    }

    /** GET /api/trainers/{trainer}/members - SRS 19 assigned members. */
    public function members(Request $request, Trainer $trainer): AnonymousResourceCollection
    {
        $members = $trainer->members()
            ->with('currentMembership')
            ->orderBy('full_name')
            ->paginate($this->perPage($request, 50));

        return MemberResource::collection($members);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('trainers', 'user_id')->whereNull('deleted_at')],
            'full_name' => [$required, 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
