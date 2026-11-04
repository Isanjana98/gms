<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/** SRS 21 - Queue reminders and drain the outbox. */
class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch
        {--queue-reminders : Queue membership expiry reminders before sending}
        {--limit=200 : Maximum messages to deliver in this run}';

    protected $description = 'Queue membership reminders and deliver pending notifications (SRS 21)';

    public function handle(NotificationService $notifications): int
    {
        if ($this->option('queue-reminders')) {
            $queued = $notifications->queueExpiryReminders();
            $this->components->info("{$queued} expiry reminder(s) queued.");
        }

        $result = $notifications->dispatchPending((int) $this->option('limit'));

        $this->components->info(sprintf(
            '%d sent, %d failed, %d awaiting a configured provider.',
            $result['sent'],
            $result['failed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
