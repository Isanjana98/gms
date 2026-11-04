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
use App\Services\MembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDeviceDriver;
use Tests\TestCase;

/**
 * Linking a member to the user id a reader already knows, and getting the
 * attendance that arrived before the link existed.
 *
 * Two failures this guards against, both of which look like nothing is wrong:
 *
 *  - **`01` and `1`.** The number a terminal prints is not always the string
 *    it sends. Somebody types what the screen showed, the enrolment screen
 *    says registered and verified, and every scan still files itself as
 *    unmatched.
 *  - **Scans before the link.** They are recorded in full and then never
 *    looked at again, so a member's history silently starts on the day
 *    somebody got round to the paperwork.
 */
class DeviceUserLinkingTest extends TestCase
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
            'name' => 'Main Entrance',
            'driver' => 'fake',
            'ip_address' => '192.0.2.10',
            'mode' => 'AUTO',
            'sync_mode' => 'PULL',
            'is_active' => true,
        ]);
    }

    private function activeMember(): Member
    {
        $member = $this->member();
        app(MembershipService::class)->create($member, $this->plan(), ['activate' => true]);

        return $member->refresh();
    }

    private function scan(string $deviceUserId, string $at, AttendanceDirection $direction = AttendanceDirection::UNKNOWN): EventProcessStatus
    {
        return app(BiometricSyncService::class)->ingest($this->device, new DeviceEvent(
            biometricUserId: $deviceUserId,
            occurredAt: Carbon::parse($at),
            direction: $direction,
            verification: VerificationType::FINGERPRINT,
            deviceUserName: 'isuru',
        ));
    }

    /* ------------------------------------------------- leading zeros */

    #[Test]
    public function a_scan_sent_as_01_reaches_the_member_mapped_to_1(): void
    {
        $member = $this->activeMember();

        BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => '1',
            'enrollment_status' => 'ENROLLED',
            'sync_status' => 'SYNCED',
            'is_active' => true,
        ]);

        $this->assertSame(EventProcessStatus::PROCESSED, $this->scan('01', '2026-11-05 09:00:00'));
        $this->assertDatabaseHas('attendance', ['member_id' => $member->id]);
    }

    #[Test]
    public function a_scan_sent_as_1_reaches_the_member_mapped_to_01(): void
    {
        $member = $this->activeMember();

        // The other way round, which is what happens when somebody types the
        // number exactly as the terminal displays it.
        BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => '01',
            'enrollment_status' => 'ENROLLED',
            'sync_status' => 'SYNCED',
            'is_active' => true,
        ]);

        $this->assertSame(EventProcessStatus::PROCESSED, $this->scan('1', '2026-11-05 09:00:00'));
        $this->assertDatabaseHas('attendance', ['member_id' => $member->id]);
    }

    #[Test]
    public function an_exact_match_wins_over_the_lenient_one(): void
    {
        $exact = $this->activeMember();
        $padded = $this->activeMember();

        BiometricUser::create([
            'member_id' => $padded->id, 'device_id' => $this->device->id, 'biometric_user_id' => '01',
            'enrollment_status' => 'ENROLLED', 'sync_status' => 'SYNCED', 'is_active' => true,
        ]);
        BiometricUser::create([
            'member_id' => $exact->id, 'device_id' => $this->device->id, 'biometric_user_id' => '1',
            'enrollment_status' => 'ENROLLED', 'sync_status' => 'SYNCED', 'is_active' => true,
        ]);

        $this->scan('1', '2026-11-05 09:00:00');

        $this->assertDatabaseHas('attendance', ['member_id' => $exact->id]);
        $this->assertDatabaseMissing('attendance', ['member_id' => $padded->id]);
    }

    #[Test]
    public function an_ambiguous_id_is_left_unmatched_rather_than_guessed(): void
    {
        $one = $this->activeMember();
        $two = $this->activeMember();

        // A device holding both is pathological, but admitting the wrong
        // person is worse than admitting nobody.
        BiometricUser::create([
            'member_id' => $one->id, 'device_id' => $this->device->id, 'biometric_user_id' => '001',
            'enrollment_status' => 'ENROLLED', 'sync_status' => 'SYNCED', 'is_active' => true,
        ]);
        BiometricUser::create([
            'member_id' => $two->id, 'device_id' => $this->device->id, 'biometric_user_id' => '01',
            'enrollment_status' => 'ENROLLED', 'sync_status' => 'SYNCED', 'is_active' => true,
        ]);

        $this->assertSame(EventProcessStatus::UNMATCHED, $this->scan('1', '2026-11-05 09:00:00'));
        $this->assertDatabaseCount('attendance', 0);
    }

    /* ------------------------------------- recovering earlier scans */

    #[Test]
    public function linking_a_member_recovers_the_scans_that_came_first(): void
    {
        // The member used the reader for two days before anybody linked them.
        $this->scan('2', '2026-11-02 08:00:00');
        $this->scan('2', '2026-11-02 10:30:00');
        $this->scan('2', '2026-11-03 08:05:00');

        $this->assertSame(3, BiometricEventLog::where('process_status', EventProcessStatus::UNMATCHED)->count());
        $this->assertDatabaseCount('attendance', 0);

        $member = $this->activeMember();
        $unmatched = BiometricUser::whereNull('member_id')->sole();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $this->assertSame(3, $response->json('replayed'));
        $this->assertStringContainsString('3 earlier scan(s) recovered', $response->json('message'));

        // Two days of use, reconstructed: a visit in and out on the 2nd, and
        // another opened on the 3rd.
        $this->assertSame(2, Attendance::where('member_id', $member->id)->count());
        $this->assertSame(0, BiometricEventLog::where('process_status', EventProcessStatus::UNMATCHED)->count());
    }

    #[Test]
    public function recovered_scans_keep_the_time_they_actually_happened(): void
    {
        $this->scan('2', '2026-11-02 08:00:00', AttendanceDirection::IN);
        $this->scan('2', '2026-11-02 10:30:00', AttendanceDirection::OUT);

        $member = $this->activeMember();
        $unmatched = BiometricUser::whereNull('member_id')->sole();

        $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $visit = Attendance::where('member_id', $member->id)->sole();

        // Backdated, not stamped with the moment somebody pressed the button.
        $this->assertSame('2026-11-02 08:00:00', $visit->check_in_time->toDateTimeString());
        $this->assertSame('2026-11-02 10:30:00', $visit->check_out_time?->toDateTimeString());
    }

    #[Test]
    public function replaying_is_in_order_so_entries_pair_with_exits(): void
    {
        // Ingested out of order, as an overlapping pull window delivers them.
        // Kept to a plausible gym visit: a visit left open for most of a day
        // is closed as stale, which would be two records for reasons that
        // have nothing to do with ordering.
        $this->scan('2', '2026-11-02 11:00:00', AttendanceDirection::OUT);
        $this->scan('2', '2026-11-02 09:00:00', AttendanceDirection::IN);

        $member = $this->activeMember();
        $unmatched = BiometricUser::whereNull('member_id')->sole();

        $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $visit = Attendance::where('member_id', $member->id)->sole();

        $this->assertSame('2026-11-02 09:00:00', $visit->check_in_time->toDateTimeString());
        $this->assertSame('2026-11-02 11:00:00', $visit->check_out_time?->toDateTimeString());
    }

    #[Test]
    public function scans_logged_as_01_are_recovered_for_a_member_linked_to_1(): void
    {
        $this->scan('01', '2026-11-02 08:00:00');

        $member = $this->activeMember();
        $unmatched = BiometricUser::whereNull('member_id')->sole();
        $unmatched->forceFill(['biometric_user_id' => '1'])->save();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $this->assertSame(1, $response->json('replayed'));
    }

    #[Test]
    public function another_device_users_scans_are_left_alone(): void
    {
        $this->scan('2', '2026-11-02 08:00:00');
        $this->scan('7', '2026-11-02 08:05:00');

        $member = $this->activeMember();
        $unmatched = BiometricUser::where('biometric_user_id', '2')->sole();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $this->assertSame(1, $response->json('replayed'));
        $this->assertSame(
            EventProcessStatus::UNMATCHED,
            BiometricEventLog::where('biometric_user_id', '7')->sole()->process_status,
        );
    }

    #[Test]
    public function enrolling_against_an_id_the_reader_already_uses_recovers_its_scans(): void
    {
        $this->scan('2', '2026-11-02 08:00:00');
        $this->scan('2', '2026-11-02 11:00:00');

        $member = $this->activeMember();

        // GM-2026-00001 is 2 on the reader: say so when registering, rather
        // than being handed a fresh number and losing what came before.
        $response = $this->actingAs($this->admin())
            ->postJson('/api/biometric/users', [
                'member_id' => $member->id,
                'device_id' => $this->device->id,
                'biometric_user_id' => '2',
            ])
            ->assertCreated();

        $this->assertSame('2', $response->json('data.biometric_user_id'));
        $this->assertSame(2, $response->json('replayed'));
        $this->assertSame(1, Attendance::where('member_id', $member->id)->count());
    }

    #[Test]
    public function a_member_with_no_membership_still_gets_their_history_back(): void
    {
        // The real shape of this problem: somebody has been scanning in for
        // weeks and was never written up properly, so today they hold no
        // membership at all. The door opened on each of those days - refusing
        // to record it now keeps nobody out, it only loses the visit.
        $this->scan('2', '2026-11-02 08:00:00');
        $this->scan('2', '2026-11-02 10:00:00');

        $member = $this->member();
        $unmatched = BiometricUser::whereNull('member_id')->sole();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$unmatched->id}/match", ['member_id' => $member->id])
            ->assertOk();

        $this->assertSame(2, $response->json('replayed'));

        $visit = Attendance::where('member_id', $member->id)->sole();

        // Kept, and flagged for what it was, so a report can still show who
        // was in the building without a membership.
        $this->assertFalse((bool) $visit->membership_valid);
        $this->assertNotNull($visit->validation_message);
    }

    #[Test]
    public function a_live_scan_is_still_refused_when_the_branch_says_so(): void
    {
        $member = $this->member();

        BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => '2',
            'enrollment_status' => 'ENROLLED',
            'sync_status' => 'SYNCED',
            'is_active' => true,
        ]);

        // Replay relaxes the gate; the door in front of somebody standing
        // there must not.
        $this->assertSame(EventProcessStatus::REJECTED, $this->scan('2', '2026-11-05 09:00:00'));
        $this->assertDatabaseCount('attendance', 0);
    }

    #[Test]
    public function recovering_nothing_says_nothing(): void
    {
        $member = $this->activeMember();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/biometric/users', [
                'member_id' => $member->id,
                'device_id' => $this->device->id,
            ])
            ->assertCreated();

        $this->assertSame(0, $response->json('replayed'));
        $this->assertStringNotContainsString('recovered', $response->json('message'));
    }

    #[Test]
    public function the_member_id_a_new_enrolment_gets_is_their_own(): void
    {
        $member = $this->activeMember();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/biometric/users', [
                'member_id' => $member->id,
                'device_id' => $this->device->id,
            ])
            ->assertCreated();

        // The default that keeps the two sides in step without anybody
        // having to think about it.
        $this->assertSame((string) $member->id, $response->json('data.biometric_user_id'));
    }
}
