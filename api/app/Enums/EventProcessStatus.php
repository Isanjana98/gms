<?php

namespace App\Enums;

/** Outcome of interpreting one raw device event. */
enum EventProcessStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSED = 'PROCESSED';
    /** Same scan already stored - a re-sync or an overlapping pull window. */
    case DUPLICATE = 'DUPLICATE';
    /** No biometric_users row maps this device user to a member (SRS 24). */
    case UNMATCHED = 'UNMATCHED';
    /** Matched a member, but membership validation refused entry (SRS 10). */
    case REJECTED = 'REJECTED';
    case ERROR = 'ERROR';

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
