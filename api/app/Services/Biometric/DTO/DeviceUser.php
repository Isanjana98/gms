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
        /**
         * Whether the reader will admit this user.
         *
         * Hikvision calls it `Valid.enable`. Turning it off leaves the
         * fingerprints in place and simply stops them opening the door, which
         * is what a lapsed membership should do - deleting the user would
         * make every renewal an enrolment.
         */
        public bool $enabled = true,
        public array $raw = [],
    ) {}

    public function isEnrolled(): bool
    {
        return $this->fingerprintCount > 0 || $this->hasCard || $this->hasFace;
    }
}
