<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ReceiptRenderer;
use App\Support\GymSettings;
use App\Support\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** SRS 18 - Invoices and printable receipts. */
class InvoiceController extends Controller
{
    /** GET /api/invoices */
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('outstanding'), fn ($q) => $q->outstanding())
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->with('member:id,full_name,membership_number,telephone')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return InvoiceResource::collection($invoices);
    }

    /** GET /api/invoices/{invoice} */
    public function show(Invoice $invoice): InvoiceResource
    {
        $invoice->load(['items', 'member:id,full_name,membership_number,telephone,email,address', 'payments.receivedBy:id,name']);

        return new InvoiceResource($invoice);
    }

    /**
     * GET /api/invoices/{invoice}/pdf
     *
     * Streamed inline so the browser's print dialog opens straight onto it -
     * reception prints far more often than it saves.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->user()->can(Permissions::INVOICES_VIEW), 403);

        $invoice->load(['items', 'member', 'branch', 'membership']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'currency' => config('gms.currency'),
            'gymName' => $this->gymName(),
        ])->setPaper('a4');

        return $pdf->stream("invoice-{$invoice->invoice_number}.pdf");
    }

    /** GET /api/payments/{payment}/receipt - SRS 18 printable receipt. */
    public function receipt(Request $request, Payment $payment): Response
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);

        // The document itself lives in ReceiptRenderer, because the member app
        // serves the identical PDF to the member it belongs to. Only the
        // question of who may ask differs, and that is settled above.
        return app(ReceiptRenderer::class)->render($payment);
    }

    /**
     * The gym's own name, for the documents a member takes away.
     *
     * Receipts and invoices are the only things here that leave the building,
     * so they carry the gym's name rather than a branch's internal label or
     * the framework default.
     */
    private function gymName(): string
    {
        return (string) app(GymSettings::class)->get('branding.gym_name', null, config('app.name'));
    }
}
