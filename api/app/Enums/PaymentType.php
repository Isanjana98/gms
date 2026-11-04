<?php

namespace App\Enums;

/** SRS 17 - What a payment row is for. */
enum PaymentType: string
{
    case MEMBERSHIP = 'MEMBERSHIP';
    case RENEWAL = 'RENEWAL';
    case REGISTRATION_FEE = 'REGISTRATION_FEE';
    case PERSONAL_TRAINING = 'PERSONAL_TRAINING';
    case OTHER = 'OTHER';
    case REFUND = 'REFUND';

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
