<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clearing operational data, for handing a system over after a trial or a
 * demonstration.
 *
 * This is the most destructive thing the application can do, so it is built to
 * be hard to do by accident and impossible to do quietly:
 *
 *  - A backup runs first, always. Not optional, and a failure to back up
 *    aborts the reset.
 *  - Scopes are cumulative and named for what they destroy, so nobody has to
 *    infer the blast radius from a label like "reset".
 *  - Staff accounts, roles, branches, settings and branding are never touched.
 *    Wiping the accounts that perform the reset would lock everyone out of the
 *    system they were trying to clean.
 *  - The audit log survives every scope. It is the record of what was done,
 *    including this.
 */
class DataResetService
{
    /**
     * Scopes, narrowest first. Each includes the ones above it.
     *
     * Ordering within a scope matters: children go before parents, because the
     * foreign keys are restrictive by design and deleting a member out from
     * under their payments should fail rather than silently orphan them.
     */
    public const SCOPES = [
        'ATTENDANCE' => [
            'label' => 'Attendance records',
            'description' => 'Every check-in and check-out, the raw device event log and the synchronisation history. Members, memberships and payments are untouched.',
            'tables' => ['biometric_event_logs', 'attendance', 'attendance_sync_logs'],
        ],
        'TRANSACTIONS' => [
            'label' => 'Attendance, payments and memberships',
            'description' => 'Everything above, plus all payments, invoices, memberships and recorded expenses. Members remain on file with no membership history.',
            'tables' => [
                'biometric_event_logs', 'attendance', 'attendance_sync_logs',
                'payments', 'invoice_items', 'invoices', 'memberships', 'expenses',
            ],
        ],
        'MEMBERS' => [
            'label' => 'Members and all their data',
            'description' => 'Everything above, plus every member, their documents, fingerprint mappings and training schedules. Plans, trainers, expense categories and devices remain.',
            'tables' => [
                'biometric_event_logs', 'attendance', 'attendance_sync_logs',
                'payments', 'invoice_items', 'invoices', 'memberships', 'expenses',
                'workout_exercises', 'workout_plans',
                'member_documents', 'biometric_users', 'notifications', 'members',
            ],
        ],
        'EVERYTHING' => [
            'label' => 'All operational data',
            'description' => 'Everything above, plus membership plans, trainers, expense categories and biometric devices. Staff accounts, roles, branches, settings, branding and the audit log are kept.',
            'tables' => [
                'biometric_event_logs', 'attendance', 'attendance_sync_logs',
                'payments', 'invoice_items', 'invoices', 'memberships', 'expenses',
                'workout_exercises', 'workout_plans',
                'member_documents', 'biometric_users', 'notifications', 'members',
                // After expenses: the foreign key is restrictive, so a category
                // still referenced by a row refuses to go and takes the whole
                // transaction with it.
                'biometric_devices', 'trainers', 'membership_plans', 'expense_categories',
            ],
        ],
    ];

    public function __construct(private readonly BackupService $backups) {}

    /**
     * Counts what each scope would remove, so the confirmation can say it.
     *
     * "This will delete 2,481 attendance records and 96 payments" is a
     * decision someone can make. "Are you sure?" is not.
     *
     * @return array<string, int>
     */
    public function preview(string $scope): array
    {
        $counts = [];

        foreach ($this->tablesFor($scope) as $table) {
            $count = DB::table($table)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return array{scope:string, deleted:array<string,int>, backup:?string}
     */
    public function reset(string $scope): array
    {
        $tables = $this->tablesFor($scope);
        $before = $this->preview($scope);

        // Back up before touching anything. If this fails the reset does not
        // happen: a wipe with no way back is not a operation anyone should be
        // able to perform by mistake.
        $backup = $this->backups->run('MANUAL');

        if ($backup->status !== 'SUCCESS') {
            throw new \RuntimeException(
                "The pre-reset backup failed, so nothing was deleted: {$backup->message}",
            );
        }

        DB::transaction(function () use ($tables) {
            // Members reference a membership and memberships reference the
            // member, so one of the two has to be broken before either can be
            // deleted. Clearing the cached pointer is the harmless side.
            if (in_array('members', $tables, true)) {
                DB::table('members')->update(['current_membership_id' => null]);
            }

            foreach ($tables as $table) {
                // delete() rather than truncate(): truncate implicitly commits
                // in MySQL, which would leave a half-finished reset that
                // cannot be rolled back.
                DB::table($table)->delete();
            }
        });

        $this->record($scope, $before, $backup->filename);

        Log::warning('Operational data was reset.', [
            'scope' => $scope,
            'deleted' => $before,
            'by' => Auth::user()?->email,
        ]);

        return [
            'scope' => $scope,
            'deleted' => $before,
            'backup' => $backup->filename,
        ];
    }

    /** @return array<int, string> */
    private function tablesFor(string $scope): array
    {
        $definition = self::SCOPES[$scope] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown reset scope: {$scope}.");
        }

        return $definition['tables'];
    }

    /**
     * SRS 25 - The audit log records the reset, and survives it.
     *
     * @param  array<string, int>  $deleted
     */
    private function record(string $scope, array $deleted, string $backup): void
    {
        $user = Auth::user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_role' => $user && method_exists($user, 'getRoleNames') ? $user->getRoleNames()->implode(', ') : null,
            'event' => 'data_reset',
            'description' => sprintf(
                'Reset %s: %s row(s) deleted. Backup taken first: %s',
                self::SCOPES[$scope]['label'],
                number_format(array_sum($deleted)),
                $backup,
            ),
            'new_values' => ['scope' => $scope, 'deleted' => $deleted, 'backup' => $backup],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
            'url' => substr(request()->fullUrl(), 0, 512),
            'method' => request()->method(),
            'created_at' => now(),
        ]);
    }
}
