<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthScreening;
use App\Models\Member;
use App\Services\HealthScreeningService;
use App\Services\QrCode;
use App\Services\ScreeningRenderer;
use App\Support\ParQ;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Health screenings, from the staff side.
 *
 * Issuing is the counter job; reviewing is the one that matters. The split
 * between HEALTH_ISSUE and HEALTH_REVIEW exists because handing somebody a QR
 * and deciding whether a man with chest pain should be on a treadmill are not
 * the same decision and should not need the same person.
 */
class HealthScreeningController extends Controller
{
    public function __construct(
        private readonly HealthScreeningService $screenings,
        private readonly ScreeningRenderer $renderer,
        private readonly QrCode $qr,
    ) {}

    /**
     * GET /api/health-screenings
     *
     * Defaults to the review queue, because that is the only view with
     * anything time-sensitive in it.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::HEALTH_VIEW), 403);

        $screenings = HealthScreening::query()
            ->with(['member:id,full_name,membership_number,telephone', 'reviewedBy:id,name'])
            ->forBranch($this->branchScope($request))
            ->when(
                $request->boolean('outstanding', true),
                fn ($q) => $q->outstandingReview(),
                fn ($q) => $q->when(
                    $request->string('status')->toString(),
                    fn ($q, $status) => $q->where('status', $status),
                ),
            )
            ->when($request->integer('member_id'), fn ($q, $id) => $q->where('member_id', $id))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', "%{$term}%")
                        ->orWhere('membership_number', 'like', "%{$term}%")),
            ))
            // Oldest flag first: a review waiting three days matters more than
            // one raised this morning.
            ->orderByRaw('CASE WHEN review_status = ? THEN 0 ELSE 1 END', ['PENDING'])
            ->orderBy('signed_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $screenings->getCollection()->map(fn (HealthScreening $s) => $this->payload($s))->all(),
            'meta' => [
                'current_page' => $screenings->currentPage(),
                'last_page' => $screenings->lastPage(),
                'per_page' => $screenings->perPage(),
                'total' => $screenings->total(),
            ],
        ]);
    }

    /**
     * POST /api/members/{member}/health-screenings
     *
     * Issue a link, and hand back the QR that opens it.
     *
     * The plain token appears in this response and nowhere else, ever. If the
     * member walks off without scanning, issue another one — that supersedes
     * this, which is cheaper than any way of recovering it.
     */
    public function issue(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::HEALTH_ISSUE), 403);

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $issued = $this->screenings->issue($member, $request->user(), $data['hours'] ?? null);

        return response()->json([
            'message' => 'Health form ready. Ask them to scan it.',
            'data' => $this->payload($issued['screening']),
            'url' => $issued['url'],
            'qr_svg' => $this->qr->svg($issued['url']),
            'expires_at' => $issued['screening']->token_expires_at?->toIso8601String(),
        ], 201);
    }

    /** GET /api/members/{member}/health-screenings */
    public function forMember(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::HEALTH_VIEW), 403);

        $screenings = HealthScreening::query()
            // issuedBy as well as reviewedBy: the `current` entry below is
            // rendered with full: true, which reads both. Lazy loading is
            // disabled in this application, so a missing relation here is a
            // 500 rather than an extra query.
            ->with(['reviewedBy:id,name', 'issuedBy:id,name'])
            ->where('member_id', $member->getKey())
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $screenings->map(fn (HealthScreening $s) => $this->payload($s))->all(),
            // What reception actually wants to know at the counter.
            'current' => $screenings->firstWhere('status', 'COMPLETED')
                ? $this->payload($screenings->firstWhere('status', 'COMPLETED'), full: true)
                : null,
            'pending_link' => $screenings->first(fn (HealthScreening $s) => $s->linkIsUsable())?->reference,
        ]);
    }

    /** GET /api/health-screenings/{screening} */
    public function show(Request $request, HealthScreening $screening): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::HEALTH_VIEW), 403);

        $screening->load(['member:id,full_name,membership_number,telephone', 'reviewedBy:id,name', 'issuedBy:id,name']);

        return response()->json([
            'data' => $this->payload($screening, full: true),
            'questions' => ParQ::questions(),
            'terms' => $this->screenings->terms($screening->branch_id),
        ]);
    }

    /**
     * POST /api/health-screenings/{screening}/review
     *
     * Record what was decided about the flags, and by whom.
     */
    public function review(Request $request, HealthScreening $screening): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::HEALTH_REVIEW), 403);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(['CLEARED', 'DOCTOR_NOTE_REQUIRED', 'RESTRICTED'])],
            // Required, not optional. A clinical decision recorded with no
            // reasoning is the thing that looks worst a year later.
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        abort_if(
            $screening->status !== 'COMPLETED',
            422,
            'That screening has not been completed yet.',
        );

        $this->screenings->review($screening, $request->user(), $data['outcome'], $data['notes']);

        return response()->json([
            'message' => 'Review recorded.',
            'data' => $this->payload($screening->fresh(), full: true),
        ]);
    }

    /** GET /api/health-screenings/{screening}/pdf */
    public function pdf(Request $request, HealthScreening $screening): Response
    {
        abort_unless($request->user()->can(Permissions::HEALTH_VIEW), 403);
        abort_unless($screening->isCompleted(), 404, 'That screening has not been completed.');

        return $this->renderer->render($screening);
    }

    /* ---------------------------------------------------------- payload */

    /**
     * @return array<string, mixed>
     */
    private function payload(HealthScreening $screening, bool $full = false): array
    {
        $base = [
            'id' => $screening->id,
            'reference' => $screening->reference,
            'member' => $screening->relationLoaded('member') && $screening->member ? [
                'id' => $screening->member->id,
                'full_name' => $screening->member->full_name,
                'membership_number' => $screening->member->membership_number,
                'telephone' => $screening->member->telephone,
            ] : null,
            'status' => $screening->status,
            'has_flags' => $screening->has_flags,
            'flag_count' => $screening->flag_count,
            'review_status' => $screening->review_status,
            'signed_at' => $screening->signed_at?->toIso8601String(),
            'signed_name' => $screening->signed_name,
            'expires_at' => $screening->token_expires_at?->toIso8601String(),
            'link_usable' => $screening->linkIsUsable(),
            'reviewed_by' => $screening->reviewedBy?->name,
            'reviewed_at' => $screening->reviewed_at?->toIso8601String(),
            'review_notes' => $screening->review_notes,
            'created_at' => $screening->created_at?->toIso8601String(),
        ];

        if (! $full) {
            // The list shows that there are flags and how many, never which.
            // "Three flags" is what a queue needs; the detail is a click away
            // behind HEALTH_VIEW, not scrolling past on a shared screen.
            return $base;
        }

        return $base + [
            'answers' => $screening->answers,
            'flagged' => $screening->flaggedQuestions(),
            'questionnaire_version' => $screening->questionnaire_version,
            'terms_version' => $screening->terms_version,
            'ip_address' => $screening->ip_address,
            'user_agent' => $screening->user_agent,
            'record_hash' => $screening->record_hash,
            // Surfaced rather than merely stored: a tampered record that
            // nobody ever checks is the same as no hash at all.
            'record_intact' => $this->screenings->verify($screening),
            'issued_by' => $screening->issuedBy?->name,
            'document_id' => $screening->document_id,
        ];
    }
}
