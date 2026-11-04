<?php

namespace App\Enums;

/** Settlement state of a membership or invoice. */
enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PARTIAL = 'PARTIAL';
    case PAID = 'PAID';
    case REFUNDED = 'REFUNDED';

    /**
     * Derive the status from money actually received, so it can never drift
     * out of step with the payment ledger.
     */
    public static function fromAmounts(float $paid, float $total): self
    {
        if ($paid < 0) {
            return self::REFUNDED;
        }

        // Compare in cents: 0.1 + 0.2 != 0.3 in binary floating point, and a
        // fully-paid membership must not read as PARTIAL because of it.
        $paidCents = (int) round($paid * 100);
        $totalCents = (int) round($total * 100);

        return match (true) {
            $paidCents <= 0 => self::UNPAID,
            $paidCents >= $totalCents => self::PAID,
            default => self::PARTIAL,
        };
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
