<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberRegistration;
use App\Models\User;
use App\Support\ParQ;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Self-registration from the QR code at the desk: taking it in, and turning
 * an approved one into a member.
 *
 * The two halves are deliberately far apart in time and in trust. Anyone who
 * can see the printed code can call the first. Only a member of staff holding
 * REGISTRATIONS_REVIEW can call the second.
 */
class MemberRegistrationService
{
    public function __construct(
        private readonly HealthScreeningService $screenings,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * Record a submission. Creates nothing but a pending registration.
     *
     * @param  array<string, mixed>  $details  the person's own details
     * @param  array<string, array{answer: bool, detail: string|null}>  $answers
     */
    public function submit(
        ?int $branchId,
        array $details,
        array $answers,
        array $customAnswers,
        string $signatureDataUrl,
        string $signedName,
        ?string $ip,
        ?string $userAgent,
    ): MemberRegistration {
        // Validate the signature before writing anything: a registration row
        // with no signature is a form nobody agreed to, and cleaning one up
        // afterwards means deciding whether a half-finished submission is
        // evidence of anything.
        $binary = $this->screenings->decodeSignature($signatureDataUrl);

        return DB::transaction(function () use (
            $branchId, $details, $answers, $customAnswers, $binary, $signedName, $ip, $userAgent
        ) {
            $registration = MemberRegistration::create([
                ...$details,
                'branch_id' => $branchId,
                'parq_version' => ParQ::VERSION,
                'parq_answers' => $answers,
                'parq_flag_count' => ParQ::countFlags($answers),
                // Each entry carries the question's wording, so editing a
                // question later cannot change what this person was asked.
                'custom_answers' => $customAnswers === [] ? null : $customAnswers,
                // The wording itself, not a version number. The text is the
                // record of what was agreed to, and it has to still be
                // readable years later when the setting behind it has been
                // edited twice.
                'declaration' => $this->screenings->terms($branchId)['body'],
                'signed_name' => $signedName,
                'signed_at' => now(),
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                'status' => 'PENDING',
            ]);

            /*
             * Written under the registration's own id, on the private disk.
             *
             * Not under a member id, because there is no member - and may
             * never be one. A rejected registration still keeps the signature
             * that was given, since the question "what did this person
             * actually sign?" outlives the decision not to accept them.
             */
            $path = sprintf('registrations/%d/signature-%s.png', $registration->id, Str::random(8));
            Storage::disk(MemberRegistration::DISK)->put($path, $binary);

            $registration->forceFill(['signature_path' => $path])->save();

            return $registration;
        });
    }

    /**
     * Approve a registration: create the member, and carry the signed PAR-Q
     * across as a completed health screening.
     *
     * The screening matters as much as the member. Somebody answered eight
     * medical questions and signed them; if approval only copied the name and
     * phone number across, that signature would be stranded on a row nobody
     * looks at, and the member would appear in the system with no screening
     * on file at all.
     */
    public function approve(MemberRegistration $registration, User $staff, ?int $branchId = null): Member
    {
        if (! $registration->isPending()) {
            throw new \RuntimeException('That registration has already been dealt with.');
        }

        return $this->numbers->withRetry(fn () => DB::transaction(function () use ($registration, $staff, $branchId) {
            $member = Member::create([
                'branch_id' => $branchId ?? $registration->branch_id,
                'membership_number' => $this->numbers->memberNumber(),
                'full_name' => $registration->full_name,
                'nic_passport' => $registration->nic_passport,
                'date_of_birth' => $registration->date_of_birth,
                'gender' => $registration->gender,
                'address' => $registration->address,
                'city' => $registration->city,
                'telephone' => $registration->telephone,
                'email' => $registration->email,
                'emergency_contact_name' => $registration->emergency_contact_name,
                'emergency_contact_number' => $registration->emergency_contact_number,
                'join_date' => today(),
                'created_by' => $staff->id,
            ]);

            $this->screenings->recordSelfRegistered($member, $registration);

            $registration->forceFill([
                'status' => 'APPROVED',
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'member_id' => $member->id,
            ])->save();

            return $member;
        }));
    }

    public function reject(MemberRegistration $registration, User $staff, ?string $notes = null): MemberRegistration
    {
        if (! $registration->isPending()) {
            throw new \RuntimeException('That registration has already been dealt with.');
        }

        $registration->forceFill([
            'status' => 'REJECTED',
            'reviewed_by' => $staff->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        return $registration;
    }
}
