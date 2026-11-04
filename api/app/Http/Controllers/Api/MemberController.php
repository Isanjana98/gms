<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Services\MembershipService;
use App\Services\NumberGenerator;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** SRS 4 / 29 - Member management. */
class MemberController extends Controller
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly MembershipService $memberships,
    ) {}

    /** GET /api/members */
    public function index(Request $request): AnonymousResourceCollection
    {
        $members = Member::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('trainer_id'), fn ($q) => $q->where('trainer_id', $request->integer('trainer_id')))
            ->when($request->boolean('expiring_soon'), fn ($q) => $q->expiringWithin(
                $request->integer('expiring_days') ?: (int) config('gms.membership.expiring_soon_days', 30),
            ))
            ->when($request->boolean('no_fingerprint'), fn ($q) => $q->whereDoesntHave('biometricUsers', fn ($b) => $b->where('enrollment_status', 'ENROLLED')))
            ->with(['currentMembership', 'trainer:id,full_name', 'branch:id,name,code'])
            ->orderBy($this->sortColumn($request), $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage($request));

        return MemberResource::collection($members);
    }

    /**
     * POST /api/members
     *
     * Optionally sells a membership in the same call, because reception
     * registers and enrols in one conversation with the member standing there
     * (SRS 32 steps 1-2).
     */
    public function store(StoreMemberRequest $request): JsonResponse
    {
        $data = $request->validated();

        $member = $this->numbers->withRetry(fn () => DB::transaction(function () use ($request, $data) {
            $member = Member::create([
                ...collect($data)->except([
                    'photo', 'membership_plan_id', 'membership_start_date', 'membership_discount',
                ])->all(),
                'branch_id' => $data['branch_id'] ?? $this->defaultBranchId($request),
                'membership_number' => $this->numbers->memberNumber(),
                'join_date' => $data['join_date'] ?? today(),
                'created_by' => $request->user()->id,
            ]);

            if ($request->hasFile('photo')) {
                $member->forceFill([
                    'photo_path' => $request->file('photo')->store('members', 'public'),
                ])->save();
            }

            return $member;
        }));

        if (! empty($data['membership_plan_id'])) {
            $this->memberships->create(
                $member,
                MembershipPlan::findOrFail($data['membership_plan_id']),
                [
                    'start_date' => $data['membership_start_date'] ?? null,
                    'discount' => $data['membership_discount'] ?? null,
                ],
            );

            $member->refresh();
        }

        $member->load(['currentMembership', 'branch:id,name,code', 'trainer:id,full_name']);

        return (new MemberResource($member))
            ->additional(['message' => "Member registered as {$member->membership_number}."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/members/{member} */
    public function show(Request $request, Member $member): MemberResource
    {
        $this->authorizeBranch($request, $member);

        $member->load([
            'branch:id,name,code',
            'trainer:id,full_name,specialization',
            'currentMembership.plan:id,name,code',
            'memberships.plan:id,name,code',
            'biometricUsers.device:id,name,code',
        ]);

        return new MemberResource($member);
    }

    /** PUT /api/members/{member} */
    public function update(UpdateMemberRequest $request, Member $member): MemberResource
    {
        $this->authorizeBranch($request, $member);

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            // Replace rather than accumulate: nobody ever goes back to clear
            // out the old profile photos.
            if ($member->photo_path) {
                Storage::disk('public')->delete($member->photo_path);
            }

            $data['photo_path'] = $request->file('photo')->store('members', 'public');
        }

        $member->update(collect($data)->except('photo')->all());

        $member->load(['currentMembership', 'branch:id,name,code', 'trainer:id,full_name']);

        return new MemberResource($member);
    }

    /**
     * POST /api/members/{member}/photo
     *
     * Its own endpoint rather than a field on the member form: a file forces
     * the whole request into multipart, where every other value arrives as a
     * string and a cleared date becomes "null" rather than null.
     *
     * The stored filename is a random hash, so the photo is not reachable by
     * guessing a member number even though the disk is public.
     */
    public function uploadPhoto(Request $request, Member $member): JsonResponse
    {
        $this->authorizeBranch($request, $member);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $previous = $member->photo_path;

        $member->forceFill([
            'photo_path' => $request->file('photo')->store('members', 'public'),
        ])->save();

        // Replace rather than accumulate - nobody ever goes back to clear out
        // superseded profile photos.
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $this->ok('Photo updated.', ['data' => new MemberResource($member->fresh())]);
    }

    /** DELETE /api/members/{member}/photo */
    public function deletePhoto(Request $request, Member $member): JsonResponse
    {
        $this->authorizeBranch($request, $member);

        if ($member->photo_path) {
            Storage::disk('public')->delete($member->photo_path);
            $member->forceFill(['photo_path' => null])->save();
        }

        return $this->ok('Photo removed.', ['data' => new MemberResource($member->fresh())]);
    }

    /**
     * DELETE /api/members/{member}
     *
     * Soft delete only. Attendance, payments and audit history reference the
     * member, and a gym that hard-deletes members loses the financial record
     * that goes with them.
     */
    public function destroy(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERS_DELETE), 403);
        $this->authorizeBranch($request, $member);

        $member->delete();

        return $this->ok("{$member->full_name} has been removed.");
    }

    /**
     * GET /api/members/{member}/duplicates
     *
     * Reception's safety net before registering someone twice, which is the
     * most common data-quality problem in a gym database.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $request->validate([
            'full_name' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'nic_passport' => ['nullable', 'string', 'max:30'],
        ]);

        $matches = Member::query()
            ->visibleTo($request->user())
            ->where(function ($q) use ($request) {
                $q->when($request->filled('telephone'), fn ($w) => $w->orWhere('telephone', $request->string('telephone')))
                    ->when($request->filled('nic_passport'), fn ($w) => $w->orWhere('nic_passport', $request->string('nic_passport')))
                    ->when($request->filled('full_name'), fn ($w) => $w->orWhere('full_name', $request->string('full_name')));
            })
            ->limit(10)
            ->get(['id', 'membership_number', 'full_name', 'telephone', 'nic_passport', 'status']);

        return response()->json(['data' => $matches]);
    }

    /** Confine a branch-bound user to their own branch's members (SRS 22). */
    private function authorizeBranch(Request $request, Member $member): void
    {
        $user = $request->user();

        abort_if(
            $user && ! $user->isMultiBranch() && $member->branch_id !== $user->branch_id,
            403,
            'This member belongs to another branch.',
        );
    }

    private function sortColumn(Request $request): string
    {
        // Allow-listed: an unchecked sort parameter is a direct path into the
        // query builder.
        $allowed = ['created_at', 'full_name', 'membership_number', 'join_date', 'membership_expires_on', 'last_visit_at', 'total_visits'];
        $requested = $request->string('sort')->toString();

        return in_array($requested, $allowed, true) ? $requested : 'created_at';
    }
}
