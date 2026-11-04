<?php

namespace App\Services;

use App\Models\HealthScreening;
use App\Support\GymSettings;
use App\Support\ParQ;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rendering a completed screening as the document it has to be.
 *
 * This is the artefact that gets produced if anything ever goes wrong, so it
 * carries more than the answers: the exact wording the member was shown, the
 * signature as they drew it, when and from where they signed, and the record
 * hash. A PDF listing seven "no"s with no provenance proves nothing.
 *
 * A4, not A5 as receipts use: this is read and filed, not handed across a
 * counter, and the terms have to be legible without squinting.
 */
class ScreeningRenderer
{
    public function __construct(
        private readonly GymSettings $settings,
        private readonly HealthScreeningService $screenings,
    ) {}

    /** The raw PDF bytes, for filing against the member. */
    public function bytes(HealthScreening $screening): string
    {
        return $this->pdf($screening)->output();
    }

    /** A streamed response, for someone downloading it. */
    public function render(HealthScreening $screening): Response
    {
        return $this->pdf($screening)->stream("{$screening->reference}.pdf");
    }

    private function pdf(HealthScreening $screening)
    {
        $screening->loadMissing(['member', 'branch', 'issuedBy', 'reviewedBy']);

        return Pdf::loadView('pdf.screening', [
            'screening' => $screening,
            'questions' => ParQ::questions(),
            'answers' => $screening->answers ?? [],
            'terms' => $this->screenings->terms($screening->branch_id),
            'signature' => $this->signatureDataUrl($screening),
            'intact' => $this->screenings->verify($screening),
            'gymName' => $this->gymName(),
        ])->setPaper('a4');
    }

    /**
     * The signature, inlined.
     *
     * dompdf cannot fetch from the private disk, and giving it a URL would
     * mean exposing one. Embedding the bytes keeps the file where it belongs.
     */
    private function signatureDataUrl(HealthScreening $screening): ?string
    {
        if (! $screening->signature_path) {
            return null;
        }

        $disk = Storage::disk(HealthScreening::DISK);

        if (! $disk->exists($screening->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($disk->get($screening->signature_path));
    }

    private function gymName(): string
    {
        return (string) $this->settings->get('branding.gym_name', null, config('app.name'));
    }
}
