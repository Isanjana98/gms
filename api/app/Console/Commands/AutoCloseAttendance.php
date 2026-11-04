<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceService;
use Illuminate\Console\Command;

/** SRS 11 - Close visits that were never scanned out. */
class AutoCloseAttendance extends Command
{
    protected $signature = 'attendance:auto-close';

    protected $description = 'Close attendance records left open by a missing check-out (SRS 11)';

    public function handle(AttendanceService $attendance): int
    {
        $closed = $attendance->autoCloseOpenVisits();

        $this->components->info("{$closed} open visit(s) closed.");

        return self::SUCCESS;
    }
}
