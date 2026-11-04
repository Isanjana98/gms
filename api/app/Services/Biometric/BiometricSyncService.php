<?php

namespace App\Services\Biometric;

use App\Enums\EventProcessStatus;
use App\Models\AttendanceSyncLog;
use App\Models\BiometricDevice;
use App\Models\BiometricEventLog;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Attendance\AttendanceService;
use App\Services\Biometric\DTO\DeviceEvent;
use App\Services\Biometric\DTO\DeviceUser;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SRS 13 / 14 - Moves attendance from readers into the database.
 *
 * The design rests on one decision: the device is the source of truth and we
 * are allowed to read the same event as many times as we like. Ingestion is
 * idempotent through the unique event hash, so the sync window can safely
 * overlap, a manual re-sync is harmless, and a reader that buffered three days
 * of events through a network outage needs no special recovery path - it is
 * just a larger ordinary pull (SRS 14).
 */
class BiometricSyncService
{
    public function __construct(
        private readonly DeviceDriverFactory $drivers,
        private readonly AttendanceService $attendance,
    ) {}

    /** SRS 23 - Test connection, recording what the device reported. */
    public function testConnection(BiometricDevice $device): AttendanceSyncLog
    {
        $log = $this->startLog($device, 'CONNECTION_TEST', 'MANUAL');

        $info = $this->drivers->for($device)->testConnection();

        if (! $info->reachable) {
            $device->markFailure($info->error ?? 'Device unreachable.');
            $log->finish('FAILED', $info->error);

            return $log;
        }

        // Record what the hardware says it is, so the device list shows the
        // real model and serial rather than whatever was typed in at setup.
        $device->forceFill(array_filter([
            'model' => $info->model,
            'serial_number' => $info->serialNumber,
            'firmware' => $info->firmware,
        ]))->save();

        $device->markOnline();
        $log->finish('SUCCESS', 'Device reachable.');

        return $log;
    }

    /**
     * SRS 13 - Pull attendance from one device.
     *
     * @param  'SCHEDULED'|'MANUAL'|'RECOVERY'  $trigger
     */
    public function syncAttendance(BiometricDevice $device, string $trigger = 'SCHEDULED'): AttendanceSyncLog
    {
        $from = $this->windowStart($device);
        $to = now();

        $log = $this->startLog($device, 'ATTENDANCE', $trigger, $from, $to);

        $counts = ['fetched' => 0, 'new' => 0, 'duplicate' => 0, 'unmatched' => 0, 'rejected' => 0, 'failed' => 0];
        $latestEvent = null;

        try {
            foreach ($this->drivers->for($device)->fetchEvents($from, $to) as $event) {
                $counts['fetched']++;

                $status = $this->ingest($device, $event, 'PULL');

                match ($status) {
                    EventProcessStatus::PROCESSED => $counts['new']++,
                    EventProcessStatus::DUPLICATE => $counts['duplicate']++,
                    EventProcessStatus::UNMATCHED => $counts['unmatched']++,
                    EventProcessStatus::REJECTED => $counts['rejected']++,
                    default => $counts['failed']++,
                };

                // Track the newest event actually seen, not the window end:
                // advancing the high-water mark to "now" would skip anything
                // the device buffers and reports a moment later.
                if ($latestEvent === null || $event->occurredAt->greaterThan($latestEvent)) {
                    $latestEvent = $event->occurredAt;
                }
            }
        } catch (\Throwable $e) {
            $device->markFailure($e->getMessage());
            $this->applyCounts($log, $counts);
            $log->finish('FAILED', $e->getMessage());

            Log::error('Biometric attendance sync failed.', [
                'device_id' => $device->id,
                'device' => $device->name,
                'error' => $e->getMessage(),
            ]);

            return $log;
        }

        $device->forceFill([
            'last_sync_at' => now(),
            // Only move forward. A device whose clock is wrong, or a
            // recovery pull of old buffered events, must not drag the
            // high-water mark backwards and cause an endless re-read.
            'last_event_at' => $latestEvent && (! $device->last_event_at || $latestEvent->greaterThan($device->last_event_at))
                ? $latestEvent
                : $device->last_event_at,
        ])->save();

        $device->markOnline();

        $this->applyCounts($log, $counts);

        $status = $counts['failed'] > 0 ? 'PARTIAL' : 'SUCCESS';
        $log->finish($status, sprintf(
            '%d events read: %d new, %d duplicate, %d unmatched, %d rejected, %d failed.',
            $counts['fetched'], $counts['new'], $counts['duplicate'],
            $counts['unmatched'], $counts['rejected'], $counts['failed'],
        ));

        return $log;
    }

    /**
     * Store and interpret one event.
     *
     * Writing the raw log row first is deliberate: if anything downstream
     * throws, the scan is still on record and can be reprocessed, rather than
     * being lost because the interpretation of it failed.
     *
     * @param  'PULL'|'PUSH'|'MANUAL'  $source
     */
    public function ingest(BiometricDevice $device, DeviceEvent $event, string $source = 'PULL'): EventProcessStatus
    {
        if ($event->isAnonymous()) {
            return EventProcessStatus::ERROR;
        }

        $hash = BiometricEventLog::makeHash(
            $device->getKey(),
            $event->biometricUserId,
            $event->occurredAt,
            $event->direction->value,
        );

        try {
            $eventLog = BiometricEventLog::create([
                'device_id' => $device->getKey(),
                'biometric_user_id' => $event->biometricUserId,
                'event_time' => $event->occurredAt,
                'event_hash' => $hash,
                'verification_type' => $event->verification,
                'direction' => $event->direction,
                'process_status' => EventProcessStatus::PENDING,
                'source' => $source,
                'raw_payload' => $event->raw,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already ingested. This is the normal, expected result for most
            // events in an overlapping pull window - not an error.
            return EventProcessStatus::DUPLICATE;
        }

        try {
            return $this->process($device, $event, $eventLog);
        } catch (\Throwable $e) {
            $eventLog->forceFill([
                'process_status' => EventProcessStatus::ERROR,
                'process_message' => substr($e->getMessage(), 0, 255),
            ])->save();

            Log::error('Failed to process biometric event.', [
                'device_id' => $device->id,
                'event_log_id' => $eventLog->id,
                'error' => $e->getMessage(),
            ]);

            return EventProcessStatus::ERROR;
        }
    }

    /** SRS 9 steps 3-5 - map the device user to a member and record the visit. */
    private function process(BiometricDevice $device, DeviceEvent $event, BiometricEventLog $eventLog): EventProcessStatus
    {
        $member = $this->resolveMember($device, $event);

        if ($member === null) {
            $this->recordUnmatchedUser($device, $event);

            $eventLog->forceFill([
                'process_status' => EventProcessStatus::UNMATCHED,
                'process_message' => "No member is mapped to device user {$event->biometricUserId}.",
            ])->save();

            return EventProcessStatus::UNMATCHED;
        }

        $outcome = $this->attendance->recordScan(
            member: $member,
            time: $event->occurredAt,
            device: $device,
            hint: $event->direction,
            verification: $event->verification,
        );

        $eventLog->forceFill([
            'member_id' => $member->getKey(),
            'attendance_id' => $outcome->attendance?->getKey(),
            'process_status' => $outcome->status,
            'process_message' => substr($outcome->message, 0, 255),
        ])->save();

        return $outcome->status;
    }

    /** SRS 9 step 3 - biometric user id to gym member. */
    private function resolveMember(BiometricDevice $device, DeviceEvent $event): ?Member
    {
        return $this->resolveMapping($device, $event->biometricUserId)?->member;
    }

    /**
     * The member mapped to one device user id, allowing for leading zeros.
     *
     * An exact match always wins. The fallback exists because the number a
     * terminal prints on its own screen is not always the string it sends in
     * an event: `01` and `1` are the same person to everybody except a string
     * comparison, and someone who types what the screen showed would get a
     * mapping that silently never matches a scan. The symptom is the worst
     * kind - attendance quietly piling up as "unmatched" while the enrolment
     * screen says the member is registered and verified.
     *
     * The fallback only runs when the exact lookup found nothing, only for
     * ids that are entirely digits, and only when it is unambiguous. A device
     * genuinely holding both `1` and `01` as separate users resolves to
     * neither, which leaves the scan unmatched and visible rather than
     * guessing which member walked in.
     */
    private function resolveMapping(BiometricDevice $device, ?string $biometricUserId): ?BiometricUser
    {
        $id = trim((string) $biometricUserId);

        if ($id === '') {
            return null;
        }

        $exact = BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->where('biometric_user_id', $id)
            ->whereNotNull('member_id')
            ->active()
            ->with('member')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        if (! ctype_digit($id)) {
            return null;
        }

        $candidates = BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->whereNotNull('member_id')
            ->active()
            ->whereRaw('CAST(biometric_user_id AS UNSIGNED) = ?', [(int) $id])
            ->with('member')
            ->get()
            // CAST turns any non-numeric string into 0, which would otherwise
            // make every such mapping a candidate for the id "0".
            ->filter(fn (BiometricUser $mapping) => ctype_digit((string) $mapping->biometric_user_id));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * SRS 24 - Remember device users we cannot place, so an administrator can
     * see and fix them instead of the scans quietly disappearing.
     */
    private function recordUnmatchedUser(BiometricDevice $device, DeviceEvent $event): void
    {
        BiometricUser::query()->firstOrCreate(
            [
                'device_id' => $device->getKey(),
                'biometric_user_id' => $event->biometricUserId,
            ],
            [
                'member_id' => null,
                'device_user_name' => $event->deviceUserName,
                'enrollment_status' => 'ENROLLED',
                'sync_status' => 'SYNCED',
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * Turn scans that arrived before the link existed into attendance.
     *
     * Every unmatched scan was already stored in full - which reader, which
     * device user, when, and in which direction - precisely so that linking
     * the member afterwards is not too late. Without this, the gap between a
     * member starting to use the reader and somebody linking them in here is
     * attendance that is visibly recorded and permanently unusable.
     *
     * Replayed oldest first, because the direction of a scan depends on
     * whether the member is currently inside: processing them out of order
     * would pair the wrong entries with the wrong exits.
     *
     * One honest limit: membership validation runs against the member's state
     * **now**, not as it was on the day of the scan. Reconstructing a
     * membership history that has since been renewed, suspended or expired
     * would be guesswork, and the alternative - refusing to recover anything
     * - is worse.
     *
     * @return array{replayed: int, recorded: int, rejected: int}
     */
    public function replayUnmatched(BiometricUser $mapping): array
    {
        $member = $mapping->member;
        $device = $mapping->device;

        if ($member === null || $device === null) {
            return ['replayed' => 0, 'recorded' => 0, 'rejected' => 0];
        }

        $replayed = 0;
        $recorded = 0;
        $rejected = 0;

        BiometricEventLog::query()
            ->where('device_id', $device->getKey())
            ->where('process_status', EventProcessStatus::UNMATCHED)
            ->whereNull('member_id')
            ->where(function ($query) use ($mapping) {
                $query->where('biometric_user_id', $mapping->biometric_user_id);

                // The same leniency the live path uses: a scan logged as `01`
                // belongs to the member mapped to `1`.
                if (ctype_digit((string) $mapping->biometric_user_id)) {
                    $query->orWhereRaw('CAST(biometric_user_id AS UNSIGNED) = ?', [(int) $mapping->biometric_user_id]);
                }
            })
            ->orderBy('event_time')
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($mapping, $member, $device, &$replayed, &$recorded, &$rejected) {
                foreach ($events as $event) {
                    // The CAST above is a coarse filter - it turns any
                    // non-numeric string into 0, so an id like "ENT" would be
                    // swept in alongside a mapping of "0". Decide precisely
                    // here before writing anybody's attendance.
                    if (! $this->sameDeviceUser((string) $event->biometric_user_id, (string) $mapping->biometric_user_id)) {
                        continue;
                    }

                    $replayed++;

                    $outcome = $this->attendance->recordScan(
                        member: $member,
                        time: $event->event_time,
                        device: $device,
                        hint: $event->direction,
                        verification: $event->verification_type,
                        // The door already opened, days ago. Refusing to
                        // record it now would keep nobody out - it would only
                        // throw the visit away, and on the strength of a
                        // membership state from today rather than from the
                        // day they actually walked in. The visit is stored
                        // flagged instead, exactly as a live scan is when the
                        // branch does not refuse invalid memberships.
                        enforceMembership: false,
                    );

                    $event->forceFill([
                        'member_id' => $member->getKey(),
                        'attendance_id' => $outcome->attendance?->getKey(),
                        'process_status' => $outcome->status,
                        'process_message' => substr($outcome->message, 0, 255),
                    ])->save();

                    if ($outcome->status === EventProcessStatus::REJECTED) {
                        $rejected++;
                    } elseif ($outcome->attendance !== null) {
                        $recorded++;
                    }
                }
            });

        return ['replayed' => $replayed, 'recorded' => $recorded, 'rejected' => $rejected];
    }

    /**
     * Whether two device user ids name the same user.
     *
     * Identical strings, or the same number written with different leading
     * zeros. "ENT" and "0" are not the same user, which is the case the plain
     * CAST comparison gets wrong.
     */
    private function sameDeviceUser(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === $b) {
            return true;
        }

        return $a !== '' && $b !== ''
            && ctype_digit($a) && ctype_digit($b)
            && ltrim($a, '0') === ltrim($b, '0');
    }

    /**
     * SRS 24 - Download the device's user list and reconcile it with ours.
     */
    public function syncUsersDown(BiometricDevice $device): AttendanceSyncLog
    {
        $log = $this->startLog($device, 'USERS_DOWNLOAD', 'MANUAL');
        $seen = 0;
        $unmatched = 0;

        try {
            foreach ($this->drivers->for($device)->fetchUsers() as $deviceUser) {
                if ($deviceUser->biometricUserId === '') {
                    continue;
                }

                $seen++;

                $record = BiometricUser::query()->firstOrNew([
                    'device_id' => $device->getKey(),
                    'biometric_user_id' => $deviceUser->biometricUserId,
                ]);

                $record->fill([
                    'device_user_name' => $deviceUser->name,
                    'fingerprint_count' => $deviceUser->fingerprintCount,
                    'has_card' => $deviceUser->hasCard,
                    'has_face' => $deviceUser->hasFace,
                    'enrollment_status' => $deviceUser->isEnrolled() ? 'ENROLLED' : 'PENDING',
                    'sync_status' => 'SYNCED',
                ]);

                $record->last_synced_at = now();

                if ($deviceUser->isEnrolled()) {
                    // SRS 8 step 5 - confirmation the enrolment really took.
                    $record->verified_at = $record->verified_at ?? now();
                }

                $record->save();

                if ($record->member_id === null) {
                    $unmatched++;
                }
            }
        } catch (\Throwable $e) {
            $device->markFailure($e->getMessage());
            $log->finish('FAILED', $e->getMessage());

            return $log;
        }

        $device->markOnline();

        $log->forceFill(['records_fetched' => $seen, 'records_unmatched' => $unmatched])->save();
        $log->finish('SUCCESS', "{$seen} device users read, {$unmatched} not matched to a member.");

        return $log;
    }

    /**
     * SRS 24 - Push members onto the device.
     *
     * Only creates the identity. Fingerprints are enrolled at the reader by
     * the member themselves (SRS 8 step 3) and the template never travels.
     *
     * @param  iterable<BiometricUser>|null  $mappings  defaults to everything pending
     */
    public function syncUsersUp(BiometricDevice $device, ?iterable $mappings = null): AttendanceSyncLog
    {
        $log = $this->startLog($device, 'USERS_UPLOAD', 'MANUAL');

        $mappings = $mappings ?? BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->whereNotNull('member_id')
            ->where('sync_status', '!=', 'SYNCED')
            ->with('member')
            ->get();

        $driver = $this->drivers->for($device);
        $sent = 0;
        $failed = 0;

        foreach ($mappings as $mapping) {
            try {
                $driver->upsertUser(new DeviceUser(
                    biometricUserId: $mapping->biometric_user_id,
                    name: $mapping->member?->full_name ?? $mapping->device_user_name,
                ));

                $mapping->forceFill([
                    'sync_status' => 'SYNCED',
                    'sync_error' => null,
                    'last_synced_at' => now(),
                ])->save();

                $sent++;
            } catch (\Throwable $e) {
                $mapping->forceFill([
                    'sync_status' => 'FAILED',
                    'sync_error' => substr($e->getMessage(), 0, 255),
                ])->save();

                $failed++;
            }
        }

        $log->forceFill(['records_fetched' => $sent + $failed, 'records_new' => $sent, 'records_failed' => $failed])->save();
        $log->finish($failed > 0 ? 'PARTIAL' : 'SUCCESS', "{$sent} users uploaded, {$failed} failed.");

        return $log;
    }

    /**
     * Where this pull should start.
     *
     * Always reaches back before the high-water mark: readers can report an
     * event a few seconds after it happened, and an exact resume would step
     * straight over those. Re-reading costs nothing because the event hash
     * makes ingestion idempotent.
     */
    private function windowStart(BiometricDevice $device): CarbonInterface
    {
        if ($device->last_event_at === null) {
            return now()->subDays((int) config('gms.biometric.initial_lookback_days', 7));
        }

        $overlap = (int) config('gms.biometric.pull_overlap_minutes', 10);

        return $device->last_event_at->copy()->subMinutes($overlap);
    }

    private function startLog(
        BiometricDevice $device,
        string $type,
        string $trigger,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): AttendanceSyncLog {
        return AttendanceSyncLog::create([
            'device_id' => $device->getKey(),
            'trigger' => $trigger,
            'type' => $type,
            'status' => 'RUNNING',
            'window_from' => $from ? Carbon::instance($from->toDateTime()) : null,
            'window_to' => $to ? Carbon::instance($to->toDateTime()) : null,
            'started_at' => now(),
            'triggered_by' => Auth::id(),
        ]);
    }

    /** @param  array<string, int>  $counts */
    private function applyCounts(AttendanceSyncLog $log, array $counts): void
    {
        $log->forceFill([
            'records_fetched' => $counts['fetched'],
            'records_new' => $counts['new'],
            'records_duplicate' => $counts['duplicate'],
            'records_unmatched' => $counts['unmatched'],
            'records_rejected' => $counts['rejected'],
            'records_failed' => $counts['failed'],
        ])->save();
    }
}
