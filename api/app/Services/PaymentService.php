<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SRS 17 - Taking money and keeping every derived total in step with it.
 *
 * The payments table is the ledger. Invoice and membership totals are views
 * over it that this service recomputes after every write, so no code path can
 * leave a membership reading PAID while the ledger disagrees.
 */
class PaymentService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly InvoiceService $invoices,
        private readonly MembershipService $memberships,
    ) {}

    /**
     * Record a payment.
     *
     * @param  array{amount:float, method?:string, payment_type?:string, invoice_id?:int|null, membership_id?:int|null, reference?:string|null, paid_at?:string|null, notes?:string|null, discount?:float|null}  $data
     */
    public function record(Member $member, array $data): Payment
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('A payment amount must be greater than zero. Use refund() to return money.');
        }

        return $this->numbers->withRetry(fn () => DB::transaction(function () use ($member, $data, $amount) {
            $membership = isset($data['membership_id'])
                ? Membership::whereKey($data['membership_id'])->where('member_id', $member->id)->first()
                : null;

            $invoice = isset($data['invoice_id'])
                ? Invoice::whereKey($data['invoice_id'])->where('member_id', $member->id)->first()
                // Settle the membership's own invoice when one was not named.
                : $membership?->invoices()->first();

            $payment = Payment::create([
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'invoice_id' => $invoice?->id,
                'membership_id' => $membership?->id ?? $invoice?->membership_id,
                'receipt_number' => $this->numbers->receiptNumber(),
                'payment_type' => $data['payment_type'] ?? $this->inferType($membership),
                'amount' => $amount,
                'discount' => round((float) ($data['discount'] ?? 0), 2),
                'method' => $data['method'] ?? PaymentMethod::CASH->value,
                'reference' => $data['reference'] ?? null,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'status' => 'COMPLETED',
                'notes' => $data['notes'] ?? null,
                'received_by' => Auth::id(),
            ]);

            $this->settle($payment);

            return $payment->refresh();
        }));
    }

    /**
     * SRS 17 - Refund a payment, in part or in full.
     *
     * Written as a new negative row rather than an edit to the original, so
     * the receipt the member was handed still exists in the ledger exactly as
     * it was printed.
     */
    public function refund(Payment $payment, ?float $amount = null, ?string $reason = null): Payment
    {
        if ($payment->is_refund) {
            throw new \InvalidArgumentException('A refund cannot itself be refunded.');
        }

        $alreadyRefunded = abs((float) Payment::query()
            ->where('refund_of_payment_id', $payment->id)
            ->sum('amount'));

        $refundable = round((float) $payment->amount - $alreadyRefunded, 2);
        $amount = round($amount ?? $refundable, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero.');
        }

        if ($amount > $refundable) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot refund %s: only %s of receipt %s remains unrefunded.',
                number_format($amount, 2),
                number_format($refundable, 2),
                $payment->receipt_number,
            ));
        }

        return $this->numbers->withRetry(fn () => DB::transaction(function () use ($payment, $amount, $reason, $refundable) {
            $refund = Payment::create([
                'branch_id' => $payment->branch_id,
                'member_id' => $payment->member_id,
                'invoice_id' => $payment->invoice_id,
                'membership_id' => $payment->membership_id,
                'refund_of_payment_id' => $payment->id,
                'receipt_number' => $this->numbers->receiptNumber(),
                'payment_type' => PaymentType::REFUND,
                'amount' => -$amount,
                'method' => $payment->method,
                'reference' => $payment->receipt_number,
                'paid_at' => now(),
                'status' => 'COMPLETED',
                'notes' => $reason,
                'received_by' => Auth::id(),
            ]);

            // Mark the original REFUNDED only when nothing is left on it.
            if (round($amount, 2) >= $refundable) {
                $payment->forceFill(['status' => 'REFUNDED'])->save();
            }

            $this->settle($refund);

            return $refund->refresh();
        }));
    }

    /**
     * Re-derive invoice and membership totals from the ledger, and activate a
     * membership that has just been paid off (SRS 32 steps 3 and 6).
     */
    private function settle(Payment $payment): void
    {
        if ($payment->invoice) {
            $this->invoices->recalculate($payment->invoice);
        }

        $membership = $payment->membership;

        if ($membership === null) {
            return;
        }

        $paid = round((float) $membership->payments()->completed()->sum('amount'), 2);

        $membership->forceFill([
            'paid_amount' => $paid,
            'payment_status' => PaymentStatus::fromAmounts($paid, (float) $membership->final_amount),
        ])->save();

        $fullyPaid = $membership->payment_status === PaymentStatus::PAID;

        if ($fullyPaid && $membership->status === MembershipStatus::PENDING) {
            $this->memberships->activate($membership);

            return;
        }

        $this->memberships->refreshMemberState($membership->member);
    }

    private function inferType(?Membership $membership): string
    {
        if ($membership === null) {
            return PaymentType::OTHER->value;
        }

        return $membership->renewed_from_id
            ? PaymentType::RENEWAL->value
            : PaymentType::MEMBERSHIP->value;
    }
}
