<?php

namespace App\Services\Attendance;

use App\Models\Membership;

/** Outcome of validating a member's right to enter (SRS 10). */
readonly class MembershipCheck
{
    public function __construct(
        public bool $valid,
        public string $message,
        public ?Membership $membership = null,
    ) {}

    public static function ok(Membership $membership): self
    {
        return new self(true, 'Membership valid until '.$membership->access_until->format('d M Y'), $membership);
    }

    public static function fail(string $message, ?Membership $membership = null): self
    {
        return new self(false, $message, $membership);
    }
}
