<?php

namespace App\Enums;

/** How the visit was captured (SRS 12). */
enum AttendanceType: string
{
    case BIOMETRIC = 'BIOMETRIC';
    case MANUAL = 'MANUAL';
    case QR = 'QR';
    case IMPORTED = 'IMPORTED';

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
