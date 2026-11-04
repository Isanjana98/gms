<?php

namespace App\Services\Biometric\DTO;

use App\Enums\AttendanceDirection;
use App\Enums\VerificationType;
use Carbon\CarbonInterface;

/**
 * One scan, normalised away from any particular vendor's wire format.
 *
 * Everything past the driver speaks this shape, which is what makes swapping
 * a Hikvision reader for a ZKTeco one a driver change and nothing more
 * (SRS 35).
 */
readonly class DeviceEvent
{
    public function __construct(
        /** The device-side user reference (Hikvision: employeeNoString). */
        public ?string $biometricUserId,
        public CarbonInterface $occurredAt,
        public VerificationType $verification = VerificationType::FINGERPRINT,
        /** UNKNOWN when the reader does not say; the service then decides. */
        public AttendanceDirection $direction = AttendanceDirection::UNKNOWN,
        public ?string $deviceUserName = null,
        /** The untouched vendor payload, kept for dispute resolution. */
        public array $raw = [],
    ) {}

    /** True when the event carries no usable identity. */
    public function isAnonymous(): bool
    {
        return $this->biometricUserId === null || trim($this->biometricUserId) === '';
    }
}
