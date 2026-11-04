<?php

namespace App\Console\Commands;

use App\Services\Biometric\AccessControlService;
use Illuminate\Console\Command;

/**
 * Bring every reader's user list into line with who may currently come in.
 *
 * Scheduled just after the expiry sweep, so a membership that lapsed at
 * midnight stops opening the door the same night rather than the next time
 * somebody notices.
 */
class SyncDeviceAccess extends Command
{
    protected $signature = 'biometric:sync-access';

    protected $description = 'Enable or disable members on the readers to match their membership';

    public function handle(AccessControlService $access): int
    {
        $result = $access->syncAll();

        $this->components->info(sprintf(
            '%d member(s) checked, %d updated on a reader.',
            $result['checked'],
            $result['changed'],
        ));

        if ($result['failed'] > 0) {
            // Not a failure of the command: the rest were synced and these are
            // retried tomorrow. Worth saying out loud all the same, because a
            // reader that is always unreachable will otherwise never be
            // noticed here.
            $this->components->warn("{$result['failed']} could not be reached. See the log.");
        }

        return self::SUCCESS;
    }
}
