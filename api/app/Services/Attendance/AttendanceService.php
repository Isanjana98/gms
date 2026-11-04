<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceDirection;
use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\VerificationType;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\Member;
use App\Support\GymSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SRS 9-12 - Turning scans into visits.
 *
 * This is the busiest code in the system and the least forgiving: it runs at
 * the door, it runs again for the same event whenever a device re-sends, and
 * it has to produce the same answer every time.
 */
class AttendanceService
{
    public function __construct(private readonly GymSettings $settings) {}

    /**
     * SRS 9 - Record one scan for an identified member.
     *
     * Idempotence is handled upstream by the event hash; what this guards is
     * the softer case of a member scanning twice in a few seconds because the
     * reader did not beep, which produces genuinely distinct device events.
     */
    public function recordScan(
        Member $member,
        CarbonInterface $time,
        ?BiometricDevice $device = null,
        AttendanceDirection $hint = AttendanceDirection::UNKNOWN,
        VerificationType $verification = VerificationType::FINGERPRINT,
        AttendanceType $type = AttendanceType::BIOMETRIC,
    ): ScanOutcome {
        $branchId = $device?->branch_id ?? $member->branch_id;

        return DB::transaction(function () use ($member, $time, $device, $hint, $verification, $type, $branchId) {
            // Lock the member row for the duration. Two readers at the same
            // door, or a pull and a push arriving together, would otherwise
            // both find "no open visit" and open two.
            $member = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $open = $this->closeIfStale($this->openVisit($member), $time);
            $direction = $this->resolveDirection($device, $hint, $open, $time);

            if ($duplicate = $this->detectDuplicate($member, $time, $direction, $open)) {
                return $duplicate;
            }

            $check = $this->validateEntry($member);

            // Validation gates entry, not exit. Someone inside the building
            // must always be able to scan out, even if their membership
            // lapsed at midnight while they were on the treadmill.
            if ($direction === AttendanceDirection::IN
                && ! $check->valid
                && $this->settings->rejectsInvalidMembership($branchId)) {
                return ScanOutcome::rejected($check->message);
            }

            return $direction === AttendanceDirection::OUT && $open !== null
                ? $this->closeVisit($open, $time, $device)
                : $this->openNewVisit($member, $time, $device, $verification, $type, $check, $branchId);
        });
    }

    /**
     * SRS 10 - May this member enter right now?
     *
     * The member's own status is checked before their membership: a
     * blacklisted member holding a paid-up annual plan is still refused, and
     * saying "membership valid" to reception in that case would be actively
     * misleading.
     */
    public function validateEntry(Member $member): MembershipCheck
    {
        // A deliberate decision about the person always wins, and is always
        // what reception is told: "suspended" must never be softened into a
        // billing message they might try to resolve by taking a payment.
        if (in_array($member->status, [MemberStatus::BLACKLISTED, MemberStatus::SUSPENDED], true)) {
            return MembershipCheck::fail(
                sprintf('Access denied: member is %s.', $member->status->label()),
            );
        }

        $membership = $member->currentMembership;

        // INACTIVE and EXPIRED are derived from the membership, so the
        // membership checks below give the actionable reason. Falling back to
        // "member is Inactive" would tell the desk nothing they can act on -
        // "awaiting payment" tells them to take the money.
        if (! $member->status->allowsEntry() && $membership === null) {
            return MembershipCheck::fail(
                sprintf('Access denied: member is %s and has no membership.', $member->status->label()),
            );
        }

        if ($membership === null) {
            return MembershipCheck::fail('Access denied: no membership on record.');
        }

        if ($membership->status === MembershipStatus::PENDING) {
            return MembershipCheck::fail('Access denied: membership is awaiting payment.', $membership);
        }

        if ($membership->status === MembershipStatus::SUSPENDED) {
            return MembershipCheck::fail('Access denied: membership is suspended.', $membership);
        }

        if ($membership->status === MembershipStatus::CANCELLED) {
            return MembershipCheck::fail('Access denied: membership was cancelled.', $membership);
        }

        if ($membership->start_date->isFuture()) {
            return MembershipCheck::fail(
                'Access denied: membership starts on '.$membership->start_date->format('d M Y').'.',
                $membership,
            );
        }

        if ($membership->access_until->endOfDay()->isPast()) {
            return MembershipCheck::fail(
                'Access denied: membership expired on '.$membership->end_date->format('d M Y').'.',
                $membership,
            );
        }

        if (! $membership->status->allowsEntry()) {
            return MembershipCheck::fail('Access denied: membership is not active.', $membership);
        }

        // Backstop. The member's cached status is derived from their
        // memberships, so reaching here with a live membership and a
        // non-active member means the two have drifted apart. Refuse rather
        // than admit on a state the system cannot explain.
        if (! $member->status->allowsEntry()) {
            return MembershipCheck::fail(
                sprintf('Access denied: member is %s. Check their profile.', $member->status->label()),
                $membership,
            );
        }

        return MembershipCheck::ok($membership);
    }

    /**
     * SRS 11 - Decide whether this scan is an entry or an exit.
     *
     * A device configured as a dedicated in or out reader always wins: that is
     * the whole point of separate-device mode, and second-guessing it from
     * open state would defeat it. Otherwise the open visit decides.
     */
    public function resolveDirection(
        ?BiometricDevice $device,
        AttendanceDirection $hint,
        ?Attendance $open,
        CarbonInterface $time,
    ): AttendanceDirection {
        // `mode` is null-safe too: a device loaded with a partial column set
        // has no mode, and the direction should then fall through to the
        // open-visit logic rather than crashing at the door.
        if ($fixed = $device?->mode?->fixedDirection()) {
            return $fixed;
        }

        if ($hint !== AttendanceDirection::UNKNOWN) {
            return $hint;
        }

        if ($open === null) {
            return AttendanceDirection::IN;
        }

        // A scan moments after checking in is someone swiping again because
        // the door did not open, not a completed workout. Treating it as a
        // check-out would throw the real session away.
        $minMinutes = $this->settings->int('attendance.min_session_minutes', $device?->branch_id, 5);

        if ($open->check_in_time && $open->check_in_time->diffInMinutes($time) < $minMinutes) {
            return AttendanceDirection::IN;
        }

        return AttendanceDirection::OUT;
    }

    /**
     * Close an open visit that is too old to be the one this scan belongs to,
     * and report that there is no open visit any more.
     *
     * A member who forgets to scan out on Monday and returns on Tuesday would
     * otherwise have Tuesday's arrival read as Monday's departure: the new
     * visit is never recorded, and the next scan after that is read as a
     * check-in again, so every second visit silently disappears.
     *
     * The nightly roll-over exists for the same reason, but relying on it
     * alone means the bug is live for every hour between the missed check-out
     * and midnight. Healing it here makes the scan path correct on its own.
     */
    private function closeIfStale(?Attendance $open, CarbonInterface $time): ?Attendance
    {
        if ($open === null || $open->check_in_time === null) {
            return $open;
        }

        $maxHours = (int) config('gms.attendance.max_session_hours', 6);

        if ($open->check_in_time->diffInHours($time) < $maxHours) {
            return $open;
        }

        $open->forceFill([
            'check_out_time' => $open->check_in_time->copy()->addHours($maxHours),
            'status' => AttendanceStatus::AUTO_CLOSED,
            'validation_message' => trim(($open->validation_message ?? '').' Closed automatically: no check-out recorded.'),
        ]);

        $open->recalculateDuration();
        $open->save();

        return null;
    }

    /** The member's still-open visit, if any. */
    public function openVisit(Member $member): ?Attendance
    {
        return Attendance::query()
            ->where('member_id', $member->getKey())
            ->where('status', AttendanceStatus::OPEN)
            ->orderByDesc('check_in_time')
            ->first();
    }

    /** SRS 12 - Reception records a visit by hand. */
    public function manualCheckIn(Member $member, ?CarbonInterface $time = null, ?string $notes = null): ScanOutcome
    {
        $outcome = $this->recordScan(
            $member,
            $time ?? now(),
            null,
            AttendanceDirection::IN,
            VerificationType::MANUAL,
            AttendanceType::MANUAL,
        );

        if ($outcome->attendance && $notes) {
            $outcome->attendance->forceFill(['notes' => $notes, 'created_by' => Auth::id()])->save();
        }

        return $outcome;
    }

    public function manualCheckOut(Member $member, ?CarbonInterface $time = null): ScanOutcome
    {
        return $this->recordScan(
            $member,
            $time ?? now(),
            null,
            AttendanceDirection::OUT,
            VerificationType::MANUAL,
            AttendanceType::MANUAL,
        );
    }

    /**
     * Close visits nobody scanned out of.
     *
     * Without this, one forgotten check-out leaves a visit open forever: every
     * later scan is read as the check-out of that ancient visit, and the
     * member's attendance silently stops being recorded. Runs nightly.
     *
     * @return int number of visits closed
     */
    public function autoCloseOpenVisits(?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf ?? now();
        $maxHours = (int) config('gms.attendance.max_session_hours', 6);
        $closed = 0;

        Attendance::query()
            ->open()
            ->where(function ($q) use ($asOf, $maxHours) {
                $q->whereDate('attendance_date', '<', $asOf->toDateString())
                    ->orWhere('check_in_time', '<=', $asOf->copy()->subHours($maxHours));
            })
            ->chunkById(200, function ($visits) use (&$closed, $maxHours) {
                foreach ($visits as $visit) {
                    // Credit the capped session length rather than the time
                    // until the job ran: the reports in SRS 16 average these,
                    // and a stray 19-hour visit would distort a whole month.
                    $closeAt = $visit->check_in_time?->copy()->addHours($maxHours) ?? $visit->created_at;

                    $visit->forceFill([
                        'check_out_time' => $closeAt,
                        'status' => AttendanceStatus::AUTO_CLOSED,
                        'validation_message' => trim(($visit->validation_message ?? '').' Closed automatically: no check-out recorded.'),
                    ]);

                    $visit->recalculateDuration();
                    $visit->save();

                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * SRS 9.6 - Suppress a scan that repeats one we already stored.
     *
     * The device-level hash catches an identical event re-sent by the reader.
     * This catches the human version: the same person scanning again seconds
     * later, which the device reports as a genuinely new event.
     */
    private function detectDuplicate(
        Member $member,
        CarbonInterface $time,
        AttendanceDirection $direction,
        ?Attendance $open,
    ): ?ScanOutcome {
        $window = $this->settings->int('attendance.duplicate_window_minutes', $member->branch_id, 2);

        if ($window <= 0) {
            return null;
        }

        $since = $time->copy()->subMinutes($window);

        if ($direction === AttendanceDirection::IN) {
            $recent = Attendance::query()
                ->where('member_id', $member->getKey())
                ->where('check_in_time', '>=', $since)
                ->where('check_in_time', '<=', $time)
                ->first();

            return $recent
                ? ScanOutcome::duplicate('Duplicate check-in ignored: already recorded at '.$recent->check_in_time->format('H:i').'.', $recent)
                : null;
        }

        if ($open === null) {
            $recent = Attendance::query()
                ->where('member_id', $member->getKey())
                ->whereNotNull('check_out_time')
                ->where('check_out_time', '>=', $since)
                ->where('check_out_time', '<=', $time)
                ->first();

            return $recent
                ? ScanOutcome::duplicate('Duplicate check-out ignored: already recorded at '.$recent->check_out_time->format('H:i').'.', $recent)
                : null;
        }

        return null;
    }

    private function openNewVisit(
        Member $member,
        CarbonInterface $time,
        ?BiometricDevice $device,
        VerificationType $verification,
        AttendanceType $type,
        MembershipCheck $check,
        int $branchId,
    ): ScanOutcome {
        $visit = Attendance::create([
            'branch_id' => $branchId,
            'member_id' => $member->getKey(),
            'membership_id' => $check->membership?->getKey() ?? $member->current_membership_id,
            'check_in_device_id' => $device?->getKey(),
            'biometric_user_id' => $device
                ? $member->biometricUsers()->where('device_id', $device->getKey())->value('biometric_user_id')
                : null,
            'attendance_date' => $time->toDateString(),
            'check_in_time' => $time,
            'attendance_type' => $type,
            'verification_type' => $verification,
            'status' => AttendanceStatus::OPEN,
            // Recorded even when entry was allowed anyway, so a report can
            // later show who walked in on a lapsed membership (SRS 10).
            'membership_valid' => $check->valid,
            'validation_message' => $check->valid ? null : $check->message,
            'created_by' => Auth::id(),
        ]);

        $member->forceFill([
            'last_visit_at' => $time,
            'total_visits' => $member->total_visits + 1,
        ])->save();

        $message = $check->valid
            ? sprintf('Welcome, %s. Checked in at %s.', $member->full_name, $time->format('H:i'))
            : sprintf('%s checked in. %s', $member->full_name, $check->message);

        return ScanOutcome::checkedIn($visit, $message);
    }

    private function closeVisit(Attendance $visit, CarbonInterface $time, ?BiometricDevice $device): ScanOutcome
    {
        $visit->forceFill([
            'check_out_time' => $time,
            'check_out_device_id' => $device?->getKey(),
            'status' => AttendanceStatus::COMPLETED,
            'updated_by' => Auth::id(),
        ]);

        $visit->recalculateDuration();
        $visit->save();

        return ScanOutcome::checkedOut($visit, sprintf(
            'Goodbye, %s. Checked out at %s (%d minutes).',
            $visit->member?->full_name ?? 'member',
            $time->format('H:i'),
            $visit->duration_minutes ?? 0,
        ));
    }
}
