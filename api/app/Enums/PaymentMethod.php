<?php

namespace App\Enums;

/** SRS 17 - Accepted payment methods. */
enum PaymentMethod: string
{
    case CASH = 'CASH';
    case CARD = 'CARD';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case ONLINE = 'ONLINE';

    /**
     * Methods that clear instantly at the counter. Anything else may need a
     * reference number before the accountant will treat it as received.
     */
    public function isImmediate(): bool
    {
        return $this === self::CASH || $this === self::CARD;
    }

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
