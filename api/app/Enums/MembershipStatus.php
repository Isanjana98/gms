<?php

namespace App\Enums;

/** SRS 5 - Membership statuses. */
enum MembershipStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case SUSPENDED = 'SUSPENDED';
    case CANCELLED = 'CANCELLED';

    /** Only an ACTIVE term grants access at the turnstile (SRS 10). */
    public function allowsEntry(): bool
    {
        return $this === self::ACTIVE;
    }

    /** Terminal states never transition again, so jobs can skip them. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::EXPIRED, self::CANCELLED], true);
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
