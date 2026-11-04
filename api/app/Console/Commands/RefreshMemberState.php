<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Console\Command;

/**
 * Recomputes the cached membership pointer on every member.
 *
 * `members.current_membership_id`, `membership_expires_on` and `status` are a
 * cache derived from the memberships table. They are kept current by
 * MembershipService on every write, so this is not needed in normal running.
 *
 * It exists for the case where the derivation itself changes: after a deploy
 * that alters how "current membership" is chosen, existing rows still hold the
 * old answer, and only a sweep will correct them.
 */
class RefreshMemberState extends Command
{
    protected $signature = 'members:refresh-state {--member= : Only this member id}';

    protected $description = 'Recompute each member\'s cached membership status and expiry date';

    public function handle(MembershipService $memberships): int
    {
        $query = Member::query()->with('memberships');

        if ($id = $this->option('member')) {
            $query->whereKey($id);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->warn('No members matched.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $changed = 0;

        $query->chunkById(200, function ($members) use ($memberships, $bar, &$changed) {
            foreach ($members as $member) {
                $before = [$member->current_membership_id, $member->status?->value];

                $memberships->refreshMemberState($member);

                if ([$member->current_membership_id, $member->status?->value] !== $before) {
                    $changed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->components->info("{$total} member(s) checked, {$changed} corrected.");

        return self::SUCCESS;
    }
}
