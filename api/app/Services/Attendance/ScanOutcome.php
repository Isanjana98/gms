<?php

namespace App\Services\Attendance;

use App\Enums\EventProcessStatus;
use App\Models\Attendance;

/**
 * The result of interpreting one scan.
 *
 * Returned rather than thrown: a refused entry is a normal, expected outcome
 * that the reader needs a message for, not an exceptional condition. It is
 * also what the reception screen displays and what the event log stores.
 */
readonly class ScanOutcome
{
    private function __construct(
        public EventProcessStatus $status,
        public string $message,
        public ?Attendance $attendance = null,
        public bool $accepted = false,
    ) {}

    public static function checkedIn(Attendance $attendance, string $message): self
    {
        return new self(EventProcessStatus::PROCESSED, $message, $attendance, true);
    }

    public static function checkedOut(Attendance $attendance, string $message): self
    {
        return new self(EventProcessStatus::PROCESSED, $message, $attendance, true);
    }

    public static function duplicate(string $message, ?Attendance $attendance = null): self
    {
        return new self(EventProcessStatus::DUPLICATE, $message, $attendance);
    }

    public static function rejected(string $message, ?Attendance $attendance = null): self
    {
        return new self(EventProcessStatus::REJECTED, $message, $attendance);
    }

    public static function unmatched(string $message): self
    {
        return new self(EventProcessStatus::UNMATCHED, $message);
    }

    public static function error(string $message): self
    {
        return new self(EventProcessStatus::ERROR, $message);
    }
}
