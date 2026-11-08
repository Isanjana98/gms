<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MemberRegistration;
use App\Services\MemberRegistrationService;
use App\Services\QrCode;
use App\Support\ParQ;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reviewing what came in from the QR code at the desk.
 *
 * Nothing here creates a member except approve(), and that is the only method
 * behind REGISTRATIONS_REVIEW. Everything else is reading.
 */
class MemberRegistrationController extends Controller
{
    public function __construct(
        private readonly MemberRegistrationService $registrations,
        private readonly QrCode $qr,
    ) {}

    /** GET /api/member-registrations */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_VIEW), 403);

        $query = MemberRegistration::query()
            ->with(['reviewedBy:id,name', 'member:id,membership_number,full_name'])
            // Pending first, then newest. A reviewer opens this page to deal
            // with what is waiting, not to browse what they already handled.
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('telephone', 'like', "%{$search}%")
                    ->orWhere('nic_passport', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    /** GET /api/member-registrations/{registration} */
    public function show(Request $request, MemberRegistration $registration): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_VIEW), 403);

        $registration->load(['reviewedBy:id,name', 'member:id,membership_number,full_name']);

        return response()->json([
            'data' => $registration,
            // The answers mean nothing without the questions they answered,
            // and the wording may have changed since. Send the pairing.
            'questions' => collect(ParQ::questions())->map(fn (array $q) => [
                'id' => $q['id'],
                'text' => $q['text'],
                'answer' => $registration->parq_answers[$q['id']]['answer'] ?? null,
                'detail' => $registration->parq_answers[$q['id']]['detail'] ?? null,
            ])->values(),
            'has_signature' => $registration->signature_path !== null,
        ]);
    }

    /**
     * GET /api/member-registrations/{registration}/signature
     *
     * Streamed from the private disk rather than linked. A signature is
     * somebody's mark on a declaration; it has no business being on a URL
     * that works without a session.
     */
    public function signature(Request $request, MemberRegistration $registration): StreamedResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_VIEW), 403);
        abort_if($registration->signature_path === null, 404);

        $disk = Storage::disk(MemberRegistration::DISK);

        abort_unless($disk->exists($registration->signature_path), 404);

        return $disk->response($registration->signature_path, 'signature.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** POST /api/member-registrations/{registration}/approve */
    public function approve(Request $request, MemberRegistration $registration): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_REVIEW), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        try {
            $member = $this->registrations->approve(
                $registration,
                $request->user(),
                $data['branch_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], SymfonyResponse::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => "Approved. {$member->full_name} is now member {$member->membership_number}.",
            'member' => $member->only(['id', 'membership_number', 'full_name']),
        ]);
    }

    /** POST /api/member-registrations/{registration}/reject */
    public function reject(Request $request, MemberRegistration $registration): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_REVIEW), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->registrations->reject($registration, $request->user(), $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], SymfonyResponse::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Registration rejected.']);
    }

    /**
     * GET /api/member-registrations/qr
     *
     * The code to print and stick on the wall. It carries no secret, so it
     * never expires and the same printout keeps working - which is the only
     * way a printed thing is any use.
     */
    public function qr(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_VIEW), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branch = isset($data['branch_id'])
            ? Branch::findOrFail($data['branch_id'])
            : Branch::query()->where('is_default', true)->firstOrFail();

        $url = URL::to("/join/{$branch->code}");

        return response()->json([
            'branch' => $branch->only(['id', 'code', 'name']),
            'url' => $url,
            'qr_svg' => $this->qr->svg($url),
        ]);
    }
}
