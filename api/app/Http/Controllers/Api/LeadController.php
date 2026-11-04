<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Member;
use App\Services\NumberGenerator;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Enquiries from the website, and what reception does with them. */
class LeadController extends Controller
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /** GET /api/leads */
    public function index(Request $request): JsonResponse
    {
        $leads = Lead::query()
            ->with(['plan:id,name', 'convertedMember:id,full_name,membership_number', 'handledBy:id,name'])
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                    ->orWhere('telephone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            }))
            // Newest first: an enquiry answered today is worth more than one
            // answered next week.
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $leads->getCollection()->map(fn (Lead $lead) => $this->payload($lead))->all(),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
            ],
            'summary' => $this->summary($request),
        ]);
    }

    /** PUT /api/leads/{lead} - record what happened. */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::LEADS_MANAGE), 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['NEW', 'CONTACTED', 'CONVERTED', 'CLOSED'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        // Stamped the first time somebody marks it contacted, and kept, so
        // "how quickly do we reply to enquiries" stays answerable.
        if (($data['status'] ?? null) === 'CONTACTED' && $lead->contacted_at === null) {
            $data['contacted_at'] = now();
        }

        $lead->update([...$data, 'handled_by' => $request->user()->getKey()]);

        return $this->ok('Enquiry updated.', ['data' => $this->payload($lead->refresh())]);
    }

    /**
     * POST /api/leads/{lead}/convert - turn an enquiry into a member.
     *
     * The whole point of keeping leads in the system: reception should not
     * have to retype a name and telephone number they already have, and the
     * link back to the lead is what lets the gym see whether the website is
     * paying for itself.
     */
    public function convert(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::LEADS_MANAGE), 403);
        abort_unless($request->user()->can(Permissions::MEMBERS_CREATE), 403);

        if ($lead->converted_member_id !== null) {
            return response()->json([
                'message' => 'This enquiry has already been converted.',
                'member_id' => $lead->converted_member_id,
            ], 422);
        }

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $member = $this->numbers->withRetry(fn () => DB::transaction(function () use ($lead, $data, $request) {
            $member = Member::create([
                'branch_id' => $lead->branch_id ?? $this->defaultBranchId($request),
                'membership_number' => $this->numbers->memberNumber(),
                'full_name' => $data['full_name'] ?? $lead->name,
                'telephone' => $data['telephone'] ?? $lead->telephone,
                'email' => $data['email'] ?? $lead->email,
                'join_date' => today(),
                'created_by' => $request->user()->getKey(),
            ]);

            $lead->update([
                'status' => 'CONVERTED',
                'converted_member_id' => $member->getKey(),
                'handled_by' => $request->user()->getKey(),
                'contacted_at' => $lead->contacted_at ?? now(),
            ]);

            return $member;
        }));

        return $this->ok(
            "{$member->full_name} added as a member. Sell them a membership to activate it.",
            ['member_id' => $member->getKey(), 'membership_number' => $member->membership_number],
            201,
        );
    }

    /** DELETE /api/leads/{lead} */
    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::LEADS_MANAGE), 403);

        $lead->delete();

        return $this->ok('Enquiry deleted.');
    }

    /**
     * How many are waiting, so the screen can lead with the number that
     * matters rather than a total nobody acts on.
     *
     * @return array<string, int>
     */
    private function summary(Request $request): array
    {
        $counts = Lead::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'new' => (int) ($counts['NEW'] ?? 0),
            'contacted' => (int) ($counts['CONTACTED'] ?? 0),
            'converted' => (int) ($counts['CONVERTED'] ?? 0),
            'closed' => (int) ($counts['CLOSED'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'telephone' => $lead->telephone,
            'email' => $lead->email,
            'message' => $lead->message,
            'plan' => $lead->plan?->name,
            'source' => $lead->source,
            'status' => $lead->status,
            'notes' => $lead->notes,
            'contacted_at' => $lead->contacted_at?->toIso8601String(),
            'handled_by' => $lead->handledBy?->name,
            'converted_member' => $lead->convertedMember ? [
                'id' => $lead->convertedMember->id,
                'full_name' => $lead->convertedMember->full_name,
                'membership_number' => $lead->convertedMember->membership_number,
            ] : null,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
