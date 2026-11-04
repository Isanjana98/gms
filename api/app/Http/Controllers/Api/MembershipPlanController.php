<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipPlanResource;
use App\Models\MembershipPlan;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** SRS 5 - Membership packages. */
class MembershipPlanController extends Controller
{
    /** GET /api/membership-plans */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = MembershipPlan::query()
            ->when($request->filled('branch_id'), fn ($q) => $q->where(function ($w) use ($request) {
                // A plan with no branch is available everywhere.
                $w->whereNull('branch_id')->orWhere('branch_id', $request->integer('branch_id'));
            }))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->withCount(['memberships' => fn ($q) => $q->where('status', 'ACTIVE')])
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return MembershipPlanResource::collection($plans);
    }

    /** POST /api/membership-plans */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PLANS_MANAGE), 403);

        $data = $request->validate($this->rules());

        $plan = MembershipPlan::create($data);

        return (new MembershipPlanResource($plan))
            ->additional(['message' => "Plan \"{$plan->name}\" created."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/membership-plans/{plan} */
    public function show(MembershipPlan $membershipPlan): MembershipPlanResource
    {
        return new MembershipPlanResource($membershipPlan);
    }

    /**
     * PUT /api/membership-plans/{plan}
     *
     * Editing a plan never touches memberships already sold under it: those
     * carry their own copy of the name and price (see the memberships table).
     */
    public function update(Request $request, MembershipPlan $membershipPlan): MembershipPlanResource
    {
        abort_unless($request->user()->can(Permissions::PLANS_MANAGE), 403);

        $membershipPlan->update($request->validate($this->rules($membershipPlan->id)));

        return new MembershipPlanResource($membershipPlan);
    }

    /**
     * DELETE /api/membership-plans/{plan}
     *
     * Refuses while live memberships reference it. Deactivating is the right
     * move for a plan no longer sold - deleting would orphan the history.
     */
    public function destroy(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PLANS_MANAGE), 403);

        $active = $membershipPlan->memberships()->whereIn('status', ['ACTIVE', 'PENDING'])->count();

        if ($active > 0) {
            return response()->json([
                'message' => "{$active} active membership(s) still use this plan. Deactivate it instead of deleting it.",
            ], 422);
        }

        $membershipPlan->delete();

        return $this->ok("Plan \"{$membershipPlan->name}\" deleted.");
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('membership_plans', 'code')->ignore($ignoreId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_value' => ['required', 'integer', 'min:1', 'max:120'],
            'duration_unit' => ['required', Rule::in(['DAY', 'WEEK', 'MONTH', 'YEAR'])],
            'price' => ['required', 'numeric', 'min:0'],
            'default_discount' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
