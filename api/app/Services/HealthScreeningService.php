<?php

namespace App\Services;

use App\Models\HealthScreening;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\User;
use App\Support\GymSettings;
use App\Support\ParQ;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Issuing, completing and reviewing a PAR-Q.
 *
 * Three jobs, deliberately in one class because they share the invariants:
 * a screening is written once, the thing that was signed is pinned to the
 * row, and a "yes" always lands somewhere a human has to answer it.
 */
class HealthScreeningService
{
    /** How long a QR at the desk stays usable, unless a setting says otherwise. */
    private const DEFAULT_LINK_HOURS = 48;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly GymSettings $settings,
    ) {}

    /* ---------------------------------------------------------- issuing */

    /**
     * Create a pending screening and return it with the **plain** token.
     *
     * The plain token is returned once and never stored: only its hash goes to
     * the database, so a leaked backup does not hand out working links to
     * other people's health forms. The caller turns it into a QR and forgets
     * it.
     *
     * Issuing again supersedes any link already outstanding. Two live QRs for
     * one member is how you end up with two contradictory screenings and no
     * way to say which is current.
     *
     * @return array{screening: HealthScreening, token: string, url: string}
     */
    public function issue(Member $member, ?User $by = null, ?int $hours = null): array
    {
        $hours ??= $this->settings->int('health.link_hours', $member->branch_id, self::DEFAULT_LINK_HOURS);

        // 64 hex characters. Long enough that guessing is not a strategy, and
        // short enough to survive being encoded in a QR at a readable size.
        $token = bin2hex(random_bytes(32));

        $screening = $this->numbers->withRetry(fn () => DB::transaction(function () use ($member, $by, $hours, $token) {
            HealthScreening::query()
                ->where('member_id', $member->getKey())
                ->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'token_hash' => null]);

            return HealthScreening::create([
                'member_id' => $member->getKey(),
                'branch_id' => $member->branch_id,
                'reference' => $this->numbers->screeningReference(),
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addHours($hours),
                'issued_at' => now(),
                'issued_by' => $by?->getKey(),
                'status' => 'PENDING',
                'questionnaire_version' => ParQ::VERSION,
            ]);
        }));

        return [
            'screening' => $screening,
            'token' => $token,
            'url' => URL::to("/health/{$token}"),
        ];
    }

    /**
     * Find the screening a token belongs to, or null.
     *
     * Looks the row up by hash rather than scanning and comparing, so this
     * stays one indexed read however many screenings exist.
     */
    public function findByToken(string $token): ?HealthScreening
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $screening = HealthScreening::query()
            ->with('member:id,full_name,membership_number,date_of_birth')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $screening?->linkIsUsable() ? $screening : null;
    }

    /* -------------------------------------------------------- completing */

    /**
     * Record the answers, the signature and everything needed to stand them up
     * later.
     *
     * @param  array<string, array{answer: bool, detail?: ?string}>  $answers
     * @param  string  $signature  A PNG data URL from the signature pad.
     */
    public function complete(
        HealthScreening $screening,
        array $answers,
        string $signature,
        string $signedName,
        ?string $ip,
        ?string $userAgent,
    ): HealthScreening {
        $terms = $this->terms($screening->branch_id);
        $flagCount = ParQ::countFlags($answers);

        $path = $this->storeSignature($screening, $signature);

        $signedAt = now();

        $screening->forceFill([
            'status' => 'COMPLETED',
            'answers' => $answers,
            'has_flags' => $flagCount > 0,
            'flag_count' => $flagCount,
            'questionnaire_version' => ParQ::VERSION,
            'terms_version' => $terms['version'],
            'terms_hash' => $terms['hash'],
            'signature_path' => $path,
            'signed_name' => $signedName,
            'signed_at' => $signedAt,
            'ip_address' => $ip,
            // Truncated rather than rejected: a long user agent is a browser
            // being verbose, not an attack, and losing the whole record over
            // it would be the wrong trade.
            'user_agent' => $userAgent ? Str::limit($userAgent, 490, '') : null,

            // Any yes needs a person to respond to it. That is the entire
            // reason this feature is worth having rather than being a liability.
            'review_status' => $flagCount > 0 ? 'PENDING' : 'NOT_REQUIRED',

            // The link is spent. Re-screening issues a new one.
            'token_hash' => null,
        ]);

        $screening->record_hash = $this->recordHash($screening);
        $screening->save();

        return $screening;
    }

    /**
     * A hash over everything that matters about the signed record.
     *
     * This does not make the row tamper-proof — anyone with database access
     * can recompute it. What it catches is the realistic case: a single field
     * edited later, by hand or by a careless migration, without the hash being
     * recomputed. Checking it costs nothing and turns a silent change into a
     * visible one.
     *
     * The answers are sorted by key so two equivalent records hash the same
     * regardless of the order PHP happened to build the array in.
     */
    public function recordHash(HealthScreening $screening): string
    {
        $answers = $screening->answers ?? [];
        ksort($answers);

        return hash('sha256', json_encode([
            'reference' => $screening->reference,
            'member_id' => $screening->member_id,
            'questionnaire_version' => $screening->questionnaire_version,
            'answers' => $answers,
            'terms_version' => $screening->terms_version,
            'terms_hash' => $screening->terms_hash,
            'signed_name' => $screening->signed_name,
            'signed_at' => $screening->signed_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Whether the stored record still hashes to what was recorded at signing. */
    public function verify(HealthScreening $screening): bool
    {
        return $screening->record_hash !== null
            && hash_equals($screening->record_hash, $this->recordHash($screening));
    }

    /* ----------------------------------------------------------- review */

    /**
     * Record that somebody looked at the flags and decided what to do.
     *
     * @param  'CLEARED'|'DOCTOR_NOTE_REQUIRED'|'RESTRICTED'  $outcome
     */
    public function review(HealthScreening $screening, User $by, string $outcome, ?string $notes = null): HealthScreening
    {
        $screening->forceFill([
            'review_status' => $outcome,
            'reviewed_by' => $by->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        return $screening;
    }

    /* ------------------------------------------------------------ terms */

    /**
     * The gym's own waiver wording, with a version and a hash of it.
     *
     * Hashing the text is what stops an edit to the waiver silently rewriting
     * what everybody who already signed is recorded as having agreed to. The
     * version is for humans; the hash is what actually proves it.
     *
     * @return array{version: string, body: string, hash: string}
     */
    public function terms(?int $branchId = null): array
    {
        $body = (string) $this->settings->get('health.terms_body', $branchId, $this->defaultTerms());
        $version = (string) $this->settings->get('health.terms_version', $branchId, '1.0');

        return [
            'version' => $version,
            'body' => $body,
            'hash' => hash('sha256', $version."\n".$body),
        ];
    }

    private function defaultTerms(): string
    {
        return <<<'TEXT'
        I confirm that the answers I have given above are true and complete to the best of my knowledge.

        I understand that physical activity carries a risk of injury, and that I take part at my own risk. I accept responsibility for exercising within my own limits and for stopping and seeking help if I feel unwell.

        I will tell the gym if my health changes in a way that affects any of my answers.

        I agree to follow the gym's rules, to use the equipment as instructed, and to treat staff and other members with respect.
        TEXT;
    }

    /* -------------------------------------------------------- signature */

    /**
     * Write the signature PNG to the private disk.
     *
     * A signature is identifying, and it is the one part of this record that
     * is useful to a forger. It never goes near the public disk and is served
     * only through an authenticated endpoint.
     */
    private function storeSignature(HealthScreening $screening, string $dataUrl): string
    {
        $binary = $this->decodeSignature($dataUrl);

        $path = sprintf(
            'members/%d/screenings/%s-%s.png',
            $screening->member_id,
            $screening->reference,
            Str::random(8),
        );

        Storage::disk(HealthScreening::DISK)->put($path, $binary);

        return $path;
    }

    /**
     * Turn a `data:image/png;base64,...` URL into bytes.
     *
     * Validated rather than trusted: this arrives from an unauthenticated
     * public form, so a payload that is not actually a PNG must be refused
     * here and not written to disk for something else to open later.
     */
    public function decodeSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $matches)) {
            throw new \InvalidArgumentException('The signature must be a PNG image.');
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('That signature could not be read.');
        }

        // The PNG magic number. A base64 string that decodes cleanly is still
        // not necessarily an image.
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('The signature must be a PNG image.');
        }

        if (strlen($binary) > 2_000_000) {
            throw new \InvalidArgumentException('That signature is too large.');
        }

        return $binary;
    }

    /* ----------------------------------------------------------- filing */

    /** Attach a rendered PDF to the member's documents, the way an upload would be. */
    public function fileDocument(HealthScreening $screening, string $pdf): MemberDocument
    {
        $path = sprintf(
            'members/%d/documents/%s.pdf',
            $screening->member_id,
            $screening->reference,
        );

        Storage::disk(MemberDocument::DISK)->put($path, $pdf);

        $document = MemberDocument::create([
            'member_id' => $screening->member_id,
            'type' => 'MEDICAL',
            'title' => "Health screening {$screening->reference}",
            'original_filename' => "{$screening->reference}.pdf",
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
            'signed_on' => $screening->signed_at?->toDateString(),
            'uploaded_by' => $screening->issued_by,
        ]);

        $screening->forceFill(['document_id' => $document->getKey()])->save();

        return $document;
    }
}
