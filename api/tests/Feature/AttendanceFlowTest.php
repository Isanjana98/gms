<?php

namespace Tests\Feature;

use App\Enums\AttendanceDirection;
use App\Enums\AttendanceStatus;
use App\Enums\EventProcessStatus;
use App\Enums\MemberStatus;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\Member;
use App\Models\Setting;
use App\Services\Attendance\AttendanceService;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 9-12 - Scan handling, validation and check-in/check-out. */
class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeMember(): Member
    {
        $member = $this->member();

        app(MembershipService::class)->create($member, $this->plan(), ['activate' => true]);

        return $member->refresh();
    }

    private function device(string $mode = 'AUTO'): BiometricDevice
    {
        return BiometricDevice::create([
            'branch_id' => $this->branch->id,
            'code' => 'DEV-'.uniqid(),
            'name' => 'Reader',
            'driver' => 'hikvision',
            'ip_address' => '192.0.2.10',
            'mode' => $mode,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_first_scan_opens_a_visit(): void
    {
        $outcome = app(AttendanceService::class)->recordScan($this->activeMember(), now());

        $this->assertTrue($outcome->accepted);
        $this->assertSame(AttendanceStatus::OPEN, $outcome->attendance->status);
        $this->assertTrue($outcome->attendance->membership_valid);
    }

    #[Test]
    public function a_later_scan_closes_the_open_visit_and_records_the_duration(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subHour());
        $outcome = $attendance->recordScan($member->refresh(), now());

        $this->assertSame(AttendanceStatus::COMPLETED, $outcome->attendance->status);
        $this->assertSame(60, $outcome->attendance->duration_minutes);
        $this->assertSame(1, Attendance::count());
    }

    #[Test]
    public function a_scan_moments_after_check_in_is_not_treated_as_a_check_out(): void
    {
        // Someone swiping again because the door did not open must not have
        // their session thrown away as a two-minute workout.
        //
        // Time is frozen because the gap below is exactly the duplicate
        // window. Unfrozen, the two now() calls are a few milliseconds apart,
        // which puts the second scan just outside the window - so this passed
        // on a quiet machine and failed on a loaded one.
        $this->freezeTime();

        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subMinutes(2));
        $outcome = $attendance->recordScan($member->refresh(), now());

        $this->assertSame(EventProcessStatus::DUPLICATE, $outcome->status);
        $this->assertSame(AttendanceStatus::OPEN, Attendance::first()->status);
    }

    #[Test]
    public function a_repeated_scan_inside_the_duplicate_window_is_ignored(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now());
        $outcome = $attendance->recordScan($member->refresh(), now()->addSeconds(20));

        $this->assertSame(EventProcessStatus::DUPLICATE, $outcome->status);
        $this->assertSame(1, Attendance::count());
    }

    #[Test]
    public function an_expired_membership_is_refused_entry(): void
    {
        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan(), [
            'start_date' => today()->subMonths(6)->toDateString(),
            'activate' => true,
        ]);
        app(MembershipService::class)->expireLapsed();

        $outcome = app(AttendanceService::class)->recordScan($member->refresh(), now());

        $this->assertSame(EventProcessStatus::REJECTED, $outcome->status);
        $this->assertFalse($outcome->accepted);
        $this->assertSame(0, Attendance::count());
    }

    #[Test]
    public function a_blacklisted_member_is_refused_even_with_a_paid_membership(): void
    {
        $member = $this->activeMember();
        $member->forceFill(['status' => MemberStatus::BLACKLISTED])->save();

        $outcome = app(AttendanceService::class)->recordScan($member->refresh(), now());

        $this->assertSame(EventProcessStatus::REJECTED, $outcome->status);
        $this->assertStringContainsString('Blacklisted', $outcome->message);
    }

    #[Test]
    public function an_unpaid_member_is_told_why_not_merely_that_they_are_inactive(): void
    {
        // "Member is inactive" gives the desk nothing to do. "Awaiting
        // payment" tells them to take the money.
        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan());

        $check = app(AttendanceService::class)->validateEntry($member->refresh());

        $this->assertFalse($check->valid);
        $this->assertStringContainsString('awaiting payment', $check->message);
    }

    #[Test]
    public function an_expired_member_is_told_the_date_their_membership_ran_out(): void
    {
        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan(), [
            'start_date' => today()->subMonths(4)->toDateString(),
            'activate' => true,
        ]);
        app(MembershipService::class)->expireLapsed();

        $check = app(AttendanceService::class)->validateEntry($member->refresh());

        $this->assertFalse($check->valid);
        $this->assertStringContainsString('expired on', $check->message);
    }

    #[Test]
    public function a_member_with_no_membership_at_all_is_told_so(): void
    {
        $check = app(AttendanceService::class)->validateEntry($this->member());

        $this->assertFalse($check->valid);
        $this->assertStringContainsString('no membership', $check->message);
    }

    #[Test]
    public function an_invalid_membership_is_recorded_but_flagged_when_the_branch_allows_entry(): void
    {
        // SRS 10 makes this configurable; with rejection off the visit must
        // still be visible to reception as an exception.
        Setting::set('attendance.reject_invalid_membership', false, null, 'boolean');

        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan(), [
            'start_date' => today()->subMonths(6)->toDateString(),
            'activate' => true,
        ]);
        app(MembershipService::class)->expireLapsed();

        $outcome = app(AttendanceService::class)->recordScan($member->refresh(), now());

        $this->assertTrue($outcome->accepted);
        $this->assertFalse($outcome->attendance->membership_valid);
        $this->assertNotNull($outcome->attendance->validation_message);
    }

    #[Test]
    public function a_member_inside_the_building_can_always_scan_out(): void
    {
        // Their membership lapsing at midnight must not trap them in the gym.
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subHours(2));

        $member->currentMembership->forceFill(['status' => 'SUSPENDED'])->save();
        $member->refresh();

        $outcome = $attendance->recordScan($member, now());

        $this->assertTrue($outcome->accepted);
        $this->assertSame(AttendanceStatus::COMPLETED, $outcome->attendance->status);
    }

    #[Test]
    public function a_dedicated_check_in_reader_always_means_entry(): void
    {
        // SRS 11 separate-device mode: the door decides, not the open state.
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);
        $entrance = $this->device('CHECK_IN');

        $attendance->recordScan($member, now()->subHours(3), $entrance);
        $second = $attendance->recordScan($member->refresh(), now(), $entrance);

        $this->assertSame(AttendanceStatus::OPEN, $second->attendance->status);
        $this->assertSame(2, Attendance::count());
    }

    #[Test]
    public function a_dedicated_check_out_reader_always_means_exit(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subHours(2), $this->device('CHECK_IN'));
        $outcome = $attendance->recordScan($member->refresh(), now(), $this->device('CHECK_OUT'));

        $this->assertSame(AttendanceStatus::COMPLETED, $outcome->attendance->status);
    }

    #[Test]
    public function the_devices_own_direction_is_honoured_over_guesswork(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);
        $reader = $this->device('AUTO');

        $attendance->recordScan($member, now()->subHours(2), $reader, AttendanceDirection::IN);
        $outcome = $attendance->recordScan($member->refresh(), now(), $reader, AttendanceDirection::OUT);

        $this->assertSame(AttendanceStatus::COMPLETED, $outcome->attendance->status);
    }

    #[Test]
    public function visits_left_open_are_closed_by_the_nightly_job_at_a_capped_length(): void
    {
        config(['gms.attendance.max_session_hours' => 6]);

        $member = $this->activeMember();
        app(AttendanceService::class)->recordScan($member, now()->subDays(2)->setTime(9, 0));

        $closed = app(AttendanceService::class)->autoCloseOpenVisits();

        $visit = Attendance::first();

        $this->assertSame(1, $closed);
        $this->assertSame(AttendanceStatus::AUTO_CLOSED, $visit->status);
        // Credited six hours, not the ~48 until the job ran - otherwise the
        // average-duration report is meaningless.
        $this->assertSame(360, $visit->duration_minutes);
    }

    #[Test]
    public function closing_a_forgotten_visit_lets_the_member_check_in_again(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subDays(2)->setTime(9, 0));
        $attendance->autoCloseOpenVisits();

        $outcome = $attendance->recordScan($member->refresh(), now());

        $this->assertSame(AttendanceStatus::OPEN, $outcome->attendance->status);
        $this->assertSame(2, Attendance::count());
    }

    #[Test]
    public function the_members_visit_counters_are_kept_up_to_date(): void
    {
        $member = $this->activeMember();
        $attendance = app(AttendanceService::class);

        $attendance->recordScan($member, now()->subDays(3));
        $attendance->recordScan($member->refresh(), now()->subDays(2));

        $this->assertSame(2, $member->refresh()->total_visits);
        $this->assertNotNull($member->last_visit_at);
    }
}
