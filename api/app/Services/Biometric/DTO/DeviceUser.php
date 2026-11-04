<?php

namespace App\Services\Biometric\DTO;

/**
 * A user account as it exists on a reader (SRS 24).
 *
 * Carries no biometric template - only the reference and the counts needed to
 * confirm enrolment succeeded (SRS 8 step 5, SRS 26).
 */
readonly class DeviceUser
{
    public function __construct(
        public string $biometricUserId,
        public ?string $name = null,
        public int $fingerprintCount = 0,
        public bool $hasCard = false,
        public bool $hasFace = false,
        public ?string $validFrom = null,
        public ?string $validTo = null,
        public array $raw = [],
    ) {}

    public function isEnrolled(): bool
    {
        return $this->fingerprintCount > 0 || $this->hasCard || $this->hasFace;
    }
}
