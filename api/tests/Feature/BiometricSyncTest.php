<?php

namespace Tests\Feature;

use App\Enums\AttendanceDirection;
use App\Enums\EventProcessStatus;
use App\Enums\VerificationType;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricEventLog;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\DTO\DeviceEvent;
use App\Services\Biometric\DTO\DeviceUser;
use App\Services\MembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDeviceDriver;
use Tests\TestCase;

/** SRS 13 / 14 / 24 - Synchronisation, deduplication and offline recovery. */
class BiometricSyncTest extends TestCase
{
    use RefreshDatabase;

    private BiometricDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDeviceDriver::reset();
        config(['gms.biometric.drivers.fake' => FakeDeviceDriver::class]);

        $this->device = BiometricDevice::create([
            'branch_id' => $this->branch->id,
            'code' => 'FAKE-01',
            'name' => 'Fake Reader',
            'driver' => 'fake',
            'ip_address' => '192.0.2.10',
            'mode' => 'AUTO',
            'sync_mode' => 'PULL',
            'is_active' => true,
        ]);
    }

    private function enrolledMember(string $biometricId = '1001'): Member
    {
        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan(), ['activate' => true]);

        BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => $biometricId,
            'enrollment_status' => 'ENROLLED',
            'sync_status' => 'SYNCED',
            'is_active' => true,
        ]);

        return $member->refresh();
    }

    private function event(string $biometricId, \DateTimeInterface $at, AttendanceDirection $direction = AttendanceDirection::UNKNOWN): DeviceEvent
    {
        return new DeviceEvent(
            biometricUserId: $biometricId,
            occurredAt: Carbon::instance(Carbon::parse($at)->toDateTime()),
            verification: VerificationType::FINGERPRINT,
            direction: $direction,
            raw: ['employeeNoString' => $biometricId],
        );
    }

    #[Test]
    public function a_pull_turns_device_events_into_attendance(): void
    {
        $member = $this->enrolledMember();

        FakeDeviceDriver::$events = [
            $this->event('1001', now()->subHours(2)),
            $this->event('1001', now()->subHour()),
        ];

        $log = app(BiometricSyncService::class)->syncAttendance($this->device);

        $this->assertSame('SUCCESS', $log->status);
        $this->assertSame(2, $log->records_fetched);
        $this->assertSame(2, $log->records_new);

        $visit = Attendance::where('member_id', $member->id)->first();
        $this->assertNotNull($visit->check_in_time);
        $this->assertNotNull($visit->check_out_time);
    }

    #[Test]
    public function re_syncing_the_same_events_creates_nothing_new(): void
    {
        // SRS 13 - the manual "Sync Attendance" button must be safe to press
        // twice, and an overlapping pull window must not double-count.
        $this->enrolledMember();

        FakeDeviceDriver::$events = [$this->event('1001', now()->subHours(2))];

        $sync = app(BiometricSyncService::class);
        $sync->syncAttendance($this->device);
        $second = $sync->syncAttendance($this->device->refresh());

        $this->assertSame(1, $second->records_duplicate);
        $this->assertSame(0, $second->records_new);
        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, BiometricEventLog::count());
    }

    #[Test]
    public function the_pull_window_overlaps_the_high_water_mark(): void
    {
        // A reader can report an event a few seconds late; resuming exactly
        // where we left off would step straight over it.
        config(['gms.biometric.pull_overlap_minutes' => 10]);

        $this->enrolledMember();
        FakeDeviceDriver::$events = [$this->event('1001', now()->subMinutes(30))];

        $sync = app(BiometricSyncService::class);
        $sync->syncAttendance($this->device);
        $sync->syncAttendance($this->device->refresh());

        [$from] = FakeDeviceDriver::$windows[1];
        $lastEvent = $this->device->refresh()->last_event_at;

        $this->assertTrue(
            Carbon::parse($from)->lessThan($lastEvent),
            'The second pull should start before the last event seen.',
        );
    }

    #[Test]
    public function a_scan_from_an_unknown_device_user_is_flagged_not_lost(): void
    {
        // SRS 24 - it must surface as unmatched, not vanish.
        FakeDeviceDriver::$events = [$this->event('9999', now()->subHour())];

        $log = app(BiometricSyncService::class)->syncAttendance($this->device);

        $this->assertSame(1, $log->records_unmatched);
        $this->assertSame(0, Attendance::count());

        $this->assertDatabaseHas('biometric_users', [
            'device_id' => $this->device->id,
            'biometric_user_id' => '9999',
            'member_id' => null,
        ]);

        $this->assertSame(
            EventProcessStatus::UNMATCHED,
            BiometricEventLog::first()->process_status,
        );
    }

    #[Test]
    public function a_scan_refused_by_membership_validation_is_still_recorded_as_an_event(): void
    {
        $member = $this->member();
        BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => '1001',
            'enrollment_status' => 'ENROLLED',
            'is_active' => true,
        ]);

        FakeDeviceDriver::$events = [$this->event('1001', now()->subHour())];

        $log = app(BiometricSyncService::class)->syncAttendance($this->device);

        $this->assertSame(1, $log->records_rejected);
        $this->assertSame(0, Attendance::count());
        // The attempt is on record even though entry was refused.
        $this->assertSame(1, BiometricEventLog::count());
    }

    #[Test]
    public function a_device_failure_is_logged_without_losing_events_already_read(): void
    {
        FakeDeviceDriver::$throwOnFetch = 'Device did not respond in time.';

        $log = app(BiometricSyncService::class)->syncAttendance($this->device);

        $this->assertSame('FAILED', $log->status);
        $this->assertStringContainsString('did not respond', $log->message);
        $this->assertSame('OFFLINE', $this->device->refresh()->connection_status);
        $this->assertSame(1, $this->device->consecutive_failures);
    }

    #[Test]
    public function buffered_events_are_recovered_after_the_device_comes_back(): void
    {
        // SRS 14 - a reader that held three days of events through a network
        // outage needs no special path; it is just a larger ordinary pull.
        config(['gms.biometric.initial_lookback_days' => 7]);

        $this->enrolledMember();

        FakeDeviceDriver::$throwOnFetch = 'Network unreachable.';
        app(BiometricSyncService::class)->syncAttendance($this->device);

        FakeDeviceDriver::$throwOnFetch = null;
        FakeDeviceDriver::$events = [
            $this->event('1001', now()->subDays(3)->setTime(9, 0)),
            $this->event('1001', now()->subDays(3)->setTime(10, 30)),
            $this->event('1001', now()->subDays(2)->setTime(9, 0)),
        ];

        $log = app(BiometricSyncService::class)->syncAttendance($this->device->refresh());

        $this->assertSame('SUCCESS', $log->status);
        $this->assertSame(3, $log->records_new);
        $this->assertSame(2, Attendance::count());
        $this->assertSame('ONLINE', $this->device->refresh()->connection_status);
    }

    #[Test]
    public function the_high_water_mark_never_moves_backwards(): void
    {
        // A recovery pull of old buffered events, or a device with a wrong
        // clock, must not drag the mark back and cause an endless re-read.
        $this->enrolledMember();

        FakeDeviceDriver::$events = [$this->event('1001', now()->subMinutes(10))];
        app(BiometricSyncService::class)->syncAttendance($this->device);

        $mark = $this->device->refresh()->last_event_at;

        FakeDeviceDriver::$events = [$this->event('1001', now()->subDays(5))];
        app(BiometricSyncService::class)->syncAttendance($this->device->refresh());

        $this->assertTrue($this->device->refresh()->last_event_at->equalTo($mark));
    }

    #[Test]
    public function downloading_device_users_reconciles_them_with_members(): void
    {
        // SRS 24 - matched users are updated, unknown ones reported.
        $member = $this->enrolledMember('1001');

        FakeDeviceDriver::$users = [
            new DeviceUser(biometricUserId: '1001', name: 'Test Member', fingerprintCount: 2),
            new DeviceUser(biometricUserId: '7777', name: 'Stranger', fingerprintCount: 1),
        ];

        $log = app(BiometricSyncService::class)->syncUsersDown($this->device);

        $this->assertSame('SUCCESS', $log->status);
        $this->assertSame(2, $log->records_fetched);
        $this->assertSame(1, $log->records_unmatched);

        $mapping = BiometricUser::where('biometric_user_id', '1001')->first();
        $this->assertSame(2, $mapping->fingerprint_count);
        $this->assertSame('ENROLLED', $mapping->enrollment_status);
        $this->assertNotNull($mapping->verified_at);
        $this->assertSame($member->id, $mapping->member_id);
    }

    #[Test]
    public function uploading_pushes_pending_members_onto_the_device(): void
    {
        $this->member();
        BiometricUser::create([
            'member_id' => $this->member(['full_name' => 'Upload Me'])->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => '2002',
            'enrollment_status' => 'PENDING',
            'sync_status' => 'PENDING',
            'is_active' => true,
        ]);

        $log = app(BiometricSyncService::class)->syncUsersUp($this->device);

        $this->assertSame('SUCCESS', $log->status);
        $this->assertContains('2002', FakeDeviceDriver::$uploaded);
        $this->assertSame('SYNCED', BiometricUser::where('biometric_user_id', '2002')->first()->sync_status);
    }

    #[Test]
    public function the_same_event_arriving_by_push_and_by_pull_is_stored_once(): void
    {
        $this->enrolledMember();
        $event = $this->event('1001', now()->subHour());

        $sync = app(BiometricSyncService::class);

        $pushResult = $sync->ingest($this->device, $event, 'PUSH');
        $pullResult = $sync->ingest($this->device, $event, 'PULL');

        $this->assertSame(EventProcessStatus::PROCESSED, $pushResult);
        $this->assertSame(EventProcessStatus::DUPLICATE, $pullResult);
        $this->assertSame(1, BiometricEventLog::count());
    }

    #[Test]
    public function the_event_hash_is_stable_across_equivalent_timestamps(): void
    {
        // The same instant written with different offsets is the same scan.
        $colombo = Carbon::parse('2026-03-01T10:00:00+05:30');
        $utc = Carbon::parse('2026-03-01T04:30:00+00:00');

        $this->assertSame(
            BiometricEventLog::makeHash(1, '1001', $colombo, 'IN'),
            BiometricEventLog::makeHash(1, '1001', $utc, 'IN'),
        );
    }
}
