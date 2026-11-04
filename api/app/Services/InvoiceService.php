<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Membership;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** SRS 18 - Builds invoices and keeps their settled totals honest. */
class InvoiceService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /**
     * Raise the invoice for a membership term: the term itself, and the
     * joining fee as a separate line when one was charged, because reception
     * is regularly asked to explain the difference.
     */
    public function forMembership(Membership $membership): Invoice
    {
        return DB::transaction(function () use ($membership) {
            $invoice = Invoice::create([
                'branch_id' => $membership->branch_id,
                'member_id' => $membership->member_id,
                'membership_id' => $membership->id,
                'invoice_number' => $this->numbers->invoiceNumber(),
                'issue_date' => today(),
                'due_date' => today(),
                'sub_total' => 0,
                'discount' => 0,
                'total' => 0,
                'status' => 'UNPAID',
                'created_by' => Auth::id(),
            ]);

            $isRenewal = $membership->renewed_from_id !== null;

            $invoice->items()->create([
                'item_type' => $isRenewal ? 'RENEWAL' : 'MEMBERSHIP',
                'description' => sprintf(
                    '%s (%s to %s)',
                    $membership->plan_name,
                    $membership->start_date->format('d M Y'),
                    $membership->end_date->format('d M Y'),
                ),
                'quantity' => 1,
                'unit_price' => $membership->price,
                'discount' => $membership->discount,
                'line_total' => round((float) $membership->price - (float) $membership->discount, 2),
            ]);

            if ((float) $membership->registration_fee > 0) {
                $invoice->items()->create([
                    'item_type' => 'REGISTRATION_FEE',
                    'description' => 'Registration fee',
                    'quantity' => 1,
                    'unit_price' => $membership->registration_fee,
                    'discount' => 0,
                    'line_total' => $membership->registration_fee,
                ]);
            }

            return $this->recalculate($invoice);
        });
    }

    /**
     * Recompute totals from the line items and the payment ledger.
     *
     * Totals are always derived, never incremented in place: an invoice whose
     * stored total has drifted from its own lines is a bug nobody notices
     * until an accountant does.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $invoice->loadMissing('items');

        $subTotal = round($invoice->items->sum(fn ($item) => (float) $item->unit_price * (float) $item->quantity), 2);
        $discount = round($invoice->items->sum(fn ($item) => (float) $item->discount), 2);
        $total = round(max(0, $subTotal - $discount) + (float) $invoice->tax, 2);

        $paid = round((float) $invoice->payments()->completed()->sum('amount'), 2);

        $status = $invoice->status === 'CANCELLED'
            ? 'CANCELLED'
            : PaymentStatus::fromAmounts($paid, $total)->value;

        $invoice->forceFill([
            'sub_total' => $subTotal,
            'discount' => $discount,
            'total' => $total,
            'paid_amount' => $paid,
            'status' => $status,
        ])->save();

        return $invoice;
    }
}
