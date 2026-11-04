<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/** SRS 30 - Database backup. */
class BackupDatabase extends Command
{
    protected $signature = 'gms:backup {--type=MANUAL : DAILY, WEEKLY or MANUAL}';

    protected $description = 'Back up the database and apply the retention policy (SRS 30)';

    public function handle(BackupService $backups): int
    {
        $type = strtoupper((string) $this->option('type'));

        if (! in_array($type, ['DAILY', 'WEEKLY', 'MANUAL'], true)) {
            $this->components->error('Type must be DAILY, WEEKLY or MANUAL.');

            return self::INVALID;
        }

        $log = $backups->run($type);

        if ($log->status !== 'SUCCESS') {
            $this->components->error("Backup failed: {$log->message}");

            return self::FAILURE;
        }

        $this->components->info("Backup written: {$log->filename} ({$log->size_mb} MB)");

        return self::SUCCESS;
    }
}
