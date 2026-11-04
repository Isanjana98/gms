<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\GymSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rendering a receipt as a PDF.
 *
 * Extracted from the invoice controller because two very different callers
 * need the identical document: reception printing it at the counter, and a
 * member downloading their own copy from the app. They differ only in who is
 * allowed to ask — which is the controller's business — and not at all in
 * what comes out, which is this class's.
 *
 * Keeping the paper size and the template in one place matters more than it
 * looks: the A5 choice and the font subsetting were both arrived at for
 * specific reasons, and a second copy of this code would quietly lose them.
 */
class ReceiptRenderer
{
    public function __construct(private readonly GymSettings $settings) {}

    public function render(Payment $payment): Response
    {
        $payment->load(['member', 'branch', 'membership', 'receivedBy', 'invoice']);

        $pdf = Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'currency' => config('gms.currency'),
            'gymName' => $this->gymName(),
            // A receipt on A4 wastes most of the page; A5 matches what a desk
            // printer and a member's pocket actually want.
        ])->setPaper('a5');

        return $pdf->stream("receipt-{$payment->receipt_number}.pdf");
    }

    /**
     * The gym's own name, for the documents a member takes away.
     *
     * Receipts are among the few things here that leave the building, so they
     * carry the gym's name rather than a branch's internal label or the
     * framework default.
     */
    private function gymName(): string
    {
        return (string) $this->settings->get('branding.gym_name', null, config('app.name'));
    }
}
