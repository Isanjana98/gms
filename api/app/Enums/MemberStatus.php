<?php

namespace App\Enums;

/** SRS 4 - Member statuses. */
enum MemberStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case EXPIRED = 'EXPIRED';
    case BLACKLISTED = 'BLACKLISTED';

    /**
     * Whether the member record itself permits entry. A BLACKLISTED or
     * SUSPENDED member is refused regardless of how valid their membership is.
     */
    public function allowsEntry(): bool
    {
        return $this === self::ACTIVE;
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
