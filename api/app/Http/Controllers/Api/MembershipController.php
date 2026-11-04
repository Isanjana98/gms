<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\MembershipService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** SRS 5 / 6 / 29 - Memberships and renewals. */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /** GET /api/memberships */
    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = Membership::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('plan_id'), fn ($q) => $q->where('membership_plan_id', $request->integer('plan_id')))
            ->when($request->filled('expiring_before'), fn ($q) => $q->whereDate('end_date', '<=', $request->date('expiring_before')))
            ->with(['member:id,full_name,membership_number,telephone,status,photo_path,membership_expires_on', 'plan:id,name,code'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return MembershipResource::collection($memberships);
    }

    /** POST /api/memberships */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERSHIPS_CREATE), 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'start_date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);

        $membership = $this->memberships->create($member, $plan, $data);

        $membership->load(['member:id,full_name,membership_number', 'plan:id,name,code', 'invoices']);

        return (new MembershipResource($membership))
            ->additional(['message' => "Membership {$membership->reference} created."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/memberships/{membership} */
    public function show(Membership $membership): MembershipResource
    {
        $membership->load([
            'member:id,full_name,membership_number,telephone,email,status,photo_path',
            'plan:id,name,code',
            'payments.receivedBy:id,name',
            'invoices.items',
            'renewedFrom:id,reference,start_date,end_date',
        ]);

        return new MembershipResource($membership);
    }

    /**
     * POST /api/memberships/{membership}/renew
     *
     * SRS 6 - The new start and end dates are worked out by the service from
     * the existing membership and the chosen plan; the caller may override the
     * start date but does not have to reason about continuity.
     */
    public function renew(Request $request, Membership $membership): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERSHIPS_RENEW), 403);

        $data = $request->validate([
            'membership_plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'start_date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $plan = isset($data['membership_plan_id'])
            ? MembershipPlan::findOrFail($data['membership_plan_id'])
            : null;

        $renewal = $this->memberships->renew($membership, $plan, $data);

        $renewal->load(['member:id,full_name,membership_number', 'plan:id,name,code', 'invoices']);

        return (new MembershipResource($renewal))
            ->additional([
                'message' => sprintf(
                    'Renewed as %s, running %s to %s.',
                    $renewal->reference,
                    $renewal->start_date->format('d M Y'),
                    $renewal->end_date->format('d M Y'),
                ),
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/memberships/{membership}/renewal-preview
     *
     * Lets reception quote dates and price before committing, which is what
     * the member asks about first.
     */
    public function renewalPreview(Request $request, Membership $membership): JsonResponse
    {
        $plan = $request->filled('membership_plan_id')
            ? MembershipPlan::findOrFail($request->integer('membership_plan_id'))
            : $membership->plan;

        abort_if($plan === null, 422, 'Choose a plan: the original plan no longer exists.');

        $start = $this->memberships->renewalStartDate($membership, $request->string('start_date')->toString() ?: null);
        $end = $plan->calculateEndDate($start);
        $discount = $request->filled('discount') ? (float) $request->input('discount') : (float) $plan->default_discount;

        return response()->json([
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'duration_label' => $plan->duration_label],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'continues_from_previous' => $start->greaterThan(today()),
            'price' => (float) $plan->price,
            'discount' => $discount,
            'final_amount' => round(max(0, (float) $plan->price - $discount), 2),
        ]);
    }

    /** POST /api/memberships/{membership}/activate */
    public function activate(Request $request, Membership $membership): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERSHIPS_CREATE), 403);

        $membership = $this->memberships->activate($membership);

        return $this->ok("Membership {$membership->reference} is now active.", [
            'data' => new MembershipResource($membership),
        ]);
    }

    /** POST /api/memberships/{membership}/suspend */
    public function suspend(Request $request, Membership $membership): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERSHIPS_CANCEL), 403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $membership = $this->memberships->suspend($membership, $data['reason'] ?? null);

        return $this->ok("Membership {$membership->reference} suspended.", [
            'data' => new MembershipResource($membership),
        ]);
    }

    /** DELETE /api/memberships/{membership} - cancel, never erase. */
    public function cancel(Request $request, Membership $membership): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEMBERSHIPS_CANCEL), 403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $membership = $this->memberships->cancel($membership, $data['reason'] ?? null);

        return $this->ok("Membership {$membership->reference} cancelled.", [
            'data' => new MembershipResource($membership),
        ]);
    }
}
