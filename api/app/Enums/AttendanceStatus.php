<?php

namespace App\Enums;

/** Lifecycle of a single gym visit (SRS 11, 12). */
enum AttendanceStatus: string
{
    /** Checked in; no check-out scan yet. */
    case OPEN = 'OPEN';
    case COMPLETED = 'COMPLETED';
    /** Closed by the nightly roll-over because no check-out ever arrived. */
    case AUTO_CLOSED = 'AUTO_CLOSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->name)));
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
