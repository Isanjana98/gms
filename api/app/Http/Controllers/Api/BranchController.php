<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** SRS 22 - Branches. */
class BranchController extends Controller
{
    /** GET /api/branches */
    public function index(Request $request): AnonymousResourceCollection
    {
        $branches = Branch::query()
            // A branch-bound user still needs their own branch's details for
            // headers and receipts, just not the whole estate.
            ->when(! $request->user()->isMultiBranch(), fn ($q) => $q->whereKey($request->user()->branch_id))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->withCount(['members', 'devices'])
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    /** POST /api/branches */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BRANCHES_MANAGE), 403);

        $data = $request->validate($this->rules());

        $branch = DB::transaction(function () use ($data) {
            $branch = Branch::create($data);

            if ($branch->is_default) {
                $this->clearOtherDefaults($branch);
            }

            return $branch;
        });

        return (new BranchResource($branch))
            ->additional(['message' => "Branch \"{$branch->name}\" created."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/branches/{branch} */
    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch->loadCount(['members', 'devices']));
    }

    /** PUT /api/branches/{branch} */
    public function update(Request $request, Branch $branch): BranchResource
    {
        abort_unless($request->user()->can(Permissions::BRANCHES_MANAGE), 403);

        $data = $request->validate($this->rules($branch->id, updating: true));

        DB::transaction(function () use ($branch, $data) {
            $branch->update($data);

            if ($branch->is_default) {
                $this->clearOtherDefaults($branch);
            }
        });

        return new BranchResource($branch->fresh());
    }

    /** DELETE /api/branches/{branch} */
    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BRANCHES_MANAGE), 403);

        // Members and devices restrict deletion at the database level, so
        // check first and explain rather than surfacing a foreign-key error.
        $members = $branch->members()->count();

        if ($members > 0) {
            return response()->json([
                'message' => "This branch still has {$members} member(s). Move or remove them first, or deactivate the branch instead.",
            ], 422);
        }

        $branch->delete();

        return $this->ok("Branch \"{$branch->name}\" removed.");
    }

    /** Exactly one branch is the default at any time. */
    private function clearOtherDefaults(Branch $branch): void
    {
        Branch::query()->whereKeyNot($branch->getKey())->update(['is_default' => false]);
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:20', Rule::unique('branches', 'code')->ignore($ignoreId)->whereNull('deleted_at')],
            'name' => [$required, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
