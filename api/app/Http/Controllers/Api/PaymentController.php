<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Member;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** SRS 17 / 18 - Payments, receipts and refunds. */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** GET /api/payments */
    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('payment_type'), fn ($q) => $q->where('payment_type', $request->string('payment_type')))
            ->when($request->filled('receipt_number'), fn ($q) => $q->where('receipt_number', 'like', '%'.$request->string('receipt_number').'%'))
            ->when($request->filled('from') && $request->filled('to'), fn ($q) => $q->between(
                $request->string('from')->toString(),
                $request->string('to')->toString(),
            ))
            ->with(['member:id,full_name,membership_number,photo_path', 'receivedBy:id,name'])
            ->orderByDesc('paid_at')
            ->paginate($this->perPage($request));

        return PaymentResource::collection($payments);
    }

    /** POST /api/payments */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_CREATE), 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'membership_id' => ['nullable', 'integer', 'exists:memberships,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'payment_type' => ['nullable', Rule::in(PaymentType::values())],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $member = Member::findOrFail($data['member_id']);

        try {
            $payment = $this->payments->record($member, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment->load(['member:id,full_name,membership_number', 'receivedBy:id,name']);

        return (new PaymentResource($payment))
            ->additional(['message' => "Receipt {$payment->receipt_number} recorded."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/payments/{payment} */
    public function show(Payment $payment): PaymentResource
    {
        $payment->load(['member:id,full_name,membership_number,telephone', 'receivedBy:id,name', 'invoice.items']);

        return new PaymentResource($payment);
    }

    /**
     * POST /api/payments/{payment}/refund
     *
     * SRS 17 - Recorded as a new negative entry; the original receipt is
     * never altered.
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_REFUND), 403);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $refund = $this->payments->refund($payment, $data['amount'] ?? null, $data['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new PaymentResource($refund->load('member:id,full_name,membership_number')))
            ->additional([
                'message' => sprintf(
                    'Refund of %s recorded against receipt %s.',
                    number_format(abs((float) $refund->amount), 2),
                    $payment->receipt_number,
                ),
            ])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/members/{member}/payments - a member's payment history. */
    public function forMember(Request $request, Member $member): AnonymousResourceCollection
    {
        $payments = $member->payments()
            ->with('receivedBy:id,name')
            ->orderByDesc('paid_at')
            ->paginate($this->perPage($request, 50));

        return PaymentResource::collection($payments);
    }
}
