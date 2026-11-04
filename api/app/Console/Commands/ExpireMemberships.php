<?php

namespace App\Console\Commands;

use App\Services\MembershipService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/** SRS 6 / 21 - Nightly expiry sweep. */
class ExpireMemberships extends Command
{
    protected $signature = 'memberships:expire {--notify : Also queue expiry notices}';

    protected $description = 'Expire memberships whose grace period has passed (SRS 6)';

    public function handle(MembershipService $memberships, NotificationService $notifications): int
    {
        $expired = $memberships->expireLapsed();

        $this->components->info("{$expired} membership(s) expired.");

        if ($this->option('notify')) {
            $queued = $notifications->queueExpiredNotices();
            $this->components->info("{$queued} expiry notice(s) queued.");
        }

        return self::SUCCESS;
    }
}
