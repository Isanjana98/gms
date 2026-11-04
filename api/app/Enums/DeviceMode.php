<?php

namespace App\Enums;

/**
 * SRS 11 - Operating mode of a reader.
 *
 * AUTO is the single-device case: the first valid scan of a visit is the
 * check-in and the next one closes it. CHECK_IN / CHECK_OUT is the
 * separate-device case, where the door a member walks through decides the
 * direction and the system never has to guess.
 */
enum DeviceMode: string
{
    case AUTO = 'AUTO';
    case CHECK_IN = 'CHECK_IN';
    case CHECK_OUT = 'CHECK_OUT';

    public function fixedDirection(): ?AttendanceDirection
    {
        return match ($this) {
            self::CHECK_IN => AttendanceDirection::IN,
            self::CHECK_OUT => AttendanceDirection::OUT,
            self::AUTO => null,
        };
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
