<?php

namespace App\Enums;

/** How the person proved identity at the reader (SRS 12). */
enum VerificationType: string
{
    case FINGERPRINT = 'FINGERPRINT';
    case CARD = 'CARD';
    case FACE = 'FACE';
    case PASSWORD = 'PASSWORD';
    case MANUAL = 'MANUAL';
    case OTHER = 'OTHER';

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
