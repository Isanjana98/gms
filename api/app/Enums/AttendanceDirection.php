<?php

namespace App\Enums;

/** Direction of a single raw scan, before it is folded into a visit. */
enum AttendanceDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    /** The device gave no direction; AUTO mode resolves it from open state. */
    case UNKNOWN = 'UNKNOWN';

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
