<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\AccessControlService;
use App\Services\Biometric\DTO\DeviceUser;
use App\Services\Biometric\EnrollmentService;
use App\Services\MembershipService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDeviceDriver;
use Tests\TestCase;

/**
 * Changing the id a member is known by, opening the door, and keeping the
 * reader's idea of who may come in matched to the membership.
 *
 * The thing these tests are really guarding is that **nobody loses a
 * fingerprint by accident**. Hikvision stores templates against the employee
 * number and gives no way to move one, so a change that recreates the user on
 * the device silently costs the member another trip to the counter. Which of
 * the two paths runs has to be decided by asking the reader, not by guessing.
 */
class DeviceAccessControlTest extends TestCase
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
            'name' => 'Main Door',
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

    /**
     * Let the member's term actually run out.
     *
     * Dating it in the past matters: a renewal continues from the old expiry
     * rather than from today, so a term "expired" while still dated in the
     * future renews into a term that has not started yet - and the member
     * still cannot get in, correctly.
     */
    private function lapse(Member $member): void
    {
        $member->currentMembership->forceFill([
            'status' => MembershipStatus::EXPIRED,
            'start_date' => today()->subDays(40),
            'end_date' => today()->subDays(10),
        ])->save();

        $member->refresh();
    }

    private function mapping(Member $member, string $id, int $fingerprints = 2): BiometricUser
    {
        return BiometricUser::create([
            'member_id' => $member->id,
            'device_id' => $this->device->id,
            'biometric_user_id' => $id,
            'device_user_name' => $member->full_name,
            'enrollment_status' => $fingerprints > 0 ? 'ENROLLED' : 'PENDING',
            'fingerprint_count' => $fingerprints,
            'sync_status' => 'SYNCED',
            'is_active' => true,
            'verified_at' => $fingerprints > 0 ? now() : null,
        ]);
    }

    /* ------------------------------------------------------ changing the id */

    #[Test]
    public function adopting_an_id_the_reader_already_has_keeps_the_fingerprint(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        // The reader was enrolled before this member was in the system, under
        // the number its own screen shows. This is the case worth getting
        // right: nothing on the device needs to move.
        FakeDeviceDriver::$users = [
            new DeviceUser(biometricUserId: '47', name: 'Kasun', fingerprintCount: 3),
        ];

        $result = app(EnrollmentService::class)->changeId($mapping, '47');

        $this->assertFalse($result['reenrol']);
        $this->assertSame('47', $mapping->refresh()->biometric_user_id);
        $this->assertSame(3, $mapping->fingerprint_count);
        $this->assertSame('ENROLLED', $mapping->enrollment_status);

        // Nothing was deleted, so no template was thrown away.
        $this->assertSame([], FakeDeviceDriver::$deleted);
        $this->assertStringContainsString('existing fingerprint still works', $result['message']);
    }

    #[Test]
    public function moving_to_an_id_the_reader_does_not_have_costs_the_fingerprint(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        FakeDeviceDriver::$users = [
            new DeviceUser(biometricUserId: '112', name: 'Kasun', fingerprintCount: 3),
        ];

        $result = app(EnrollmentService::class)->changeId($mapping, '900');

        $this->assertTrue($result['reenrol']);
        $this->assertSame('900', $mapping->refresh()->biometric_user_id);

        // Back to square one, and said so plainly: the member has to present
        // a finger again, and reception needs to know before the member walks
        // out of the building.
        $this->assertSame(0, $mapping->fingerprint_count);
        $this->assertSame('PENDING', $mapping->enrollment_status);
        $this->assertNull($mapping->verified_at);
        $this->assertStringContainsString('enrol their fingerprint again', $result['message']);

        $this->assertContains('900', FakeDeviceDriver::$uploaded);
        $this->assertContains('112', FakeDeviceDriver::$deleted);
    }

    #[Test]
    public function the_new_user_is_created_before_the_old_one_is_removed(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        app(EnrollmentService::class)->changeId($mapping, '900');

        // If the order were the other way round, a device that accepted the
        // delete and rejected the create would leave the member with no way
        // in at all.
        $this->assertSame(['900'], FakeDeviceDriver::$uploaded);
        $this->assertSame(['112'], FakeDeviceDriver::$deleted);
    }

    #[Test]
    public function the_system_can_be_corrected_without_touching_the_reader(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        // The ordinary case, and the only one available when the server
        // cannot reach the reader at all: somebody read 47 off the terminal
        // and is fixing our record to match.
        $result = app(EnrollmentService::class)->changeId($mapping, '47', updateDevice: false);

        $this->assertSame('47', $mapping->refresh()->biometric_user_id);
        $this->assertFalse($result['reenrol']);
        $this->assertSame([], FakeDeviceDriver::$uploaded);
        $this->assertSame([], FakeDeviceDriver::$deleted);
        $this->assertStringContainsString('in the system only', $result['message']);
    }

    #[Test]
    public function an_id_held_by_another_member_is_refused(): void
    {
        $mine = $this->mapping($this->activeMember(), '112');
        $this->mapping($this->activeMember(), '47');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already assigned to another member');

        app(EnrollmentService::class)->changeId($mine, '47');
    }

    #[Test]
    public function an_unmatched_device_user_holding_the_id_is_absorbed(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        // Found on the device during a users sync and matched to nobody. It
        // is the same identity we are adopting, and the unique index will not
        // hold both rows.
        BiometricUser::create([
            'member_id' => null,
            'device_id' => $this->device->id,
            'biometric_user_id' => '47',
            'device_user_name' => 'Unknown 47',
            'enrollment_status' => 'ENROLLED',
            'fingerprint_count' => 1,
            'sync_status' => 'SYNCED',
            'is_active' => true,
        ]);

        FakeDeviceDriver::$users = [
            new DeviceUser(biometricUserId: '47', fingerprintCount: 1),
        ];

        app(EnrollmentService::class)->changeId($mapping, '47');

        $this->assertSame('47', $mapping->refresh()->biometric_user_id);
        $this->assertSame(
            1,
            BiometricUser::withTrashed()->where('device_id', $this->device->id)->where('biometric_user_id', '47')->count(),
        );
    }

    #[Test]
    public function the_id_must_be_digits_because_that_is_what_the_device_shows(): void
    {
        $mapping = $this->mapping($this->activeMember(), '112');

        $this->actingAs($this->admin())
            ->postJson("/api/biometric/users/{$mapping->id}/change-id", [
                'biometric_user_id' => 'ENT-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.biometric_user_id.0', 'The ID must be digits only — it is the number the device itself shows.');
    }

    /* ----------------------------------------------------------- the door */

    #[Test]
    public function reception_can_release_the_door(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->postJson("/api/biometric/devices/{$this->device->id}/open-door", [
                'reason' => 'Finger would not read',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Main Door unlocked.');

        $this->assertSame([1], FakeDeviceDriver::$doorsOpened);
    }

    #[Test]
    public function opening_the_door_is_recorded_even_when_the_device_refuses(): void
    {
        FakeDeviceDriver::$throwOnOpenDoor = 'Device is offline.';

        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->postJson("/api/biometric/devices/{$this->device->id}/open-door")
            ->assertStatus(422);

        // A refused attempt is exactly the one somebody will want to find
        // later, so the audit entry is written before the attempt rather
        // than after a success.
        $this->assertDatabaseHas('audit_logs', ['event' => 'door_opened']);
    }

    #[Test]
    public function a_trainer_cannot_open_the_door(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER))
            ->postJson("/api/biometric/devices/{$this->device->id}/open-door")
            ->assertForbidden();

        $this->assertSame([], FakeDeviceDriver::$doorsOpened);
    }

    #[Test]
    public function the_audit_entry_names_who_opened_it(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($user)
            ->postJson("/api/biometric/devices/{$this->device->id}/open-door", ['reason' => 'Delivery'])
            ->assertOk();

        $entry = AuditLog::where('event', 'door_opened')->firstOrFail();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('Delivery', $entry->new_values['reason']);
    }

    /* ------------------------------------------------- membership expiry */

    #[Test]
    public function a_lapsed_membership_stops_the_reader_admitting_them(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        $this->lapse($member);

        $changed = app(AccessControlService::class)->syncMember($member->refresh());

        $this->assertSame(1, $changed);
        $this->assertFalse($mapping->refresh()->access_enabled);

        // Disabled, not deleted. The fingerprint must survive, or every
        // renewal becomes a fresh enrolment and a queue at the desk.
        $this->assertSame([], FakeDeviceDriver::$deleted);
        $this->assertSame(['id' => '112', 'enabled' => false], FakeDeviceDriver::$upsertedWith[0]);
    }

    #[Test]
    public function renewing_lets_them_back_in_without_re_enrolling(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        $this->lapse($member);
        $membership = $member->currentMembership;
        app(AccessControlService::class)->syncMember($member->refresh());

        $this->assertFalse($mapping->refresh()->access_enabled);

        FakeDeviceDriver::$upsertedWith = [];

        // Activated, because that is what taking the money does. A renewal
        // sitting unpaid must not open the door - see the test below.
        app(MembershipService::class)->renew($membership->refresh(), null, ['activate' => true]);

        $this->assertTrue($mapping->refresh()->access_enabled);

        // Re-enabled, not re-enrolled: no fingerprint was ever removed, so
        // the member walks in on the finger they gave the first time.
        $this->assertSame([], FakeDeviceDriver::$deleted);
        $this->assertSame(['id' => '112', 'enabled' => true], FakeDeviceDriver::$upsertedWith[0]);
    }

    #[Test]
    public function a_renewal_that_has_not_been_paid_for_does_not_open_the_door(): void
    {
        $member = $this->activeMember();
        $mapping = $this->mapping($member, '112');

        $this->lapse($member);
        $membership = $member->currentMembership;
        app(AccessControlService::class)->syncMember($member->refresh());

        // Written up at the desk but not activated: SRS 32 says a term
        // begins at payment, and the door has to agree with that.
        app(MembershipService::class)->renew($membership->refresh());

        $this->assertFalse($mapping->refresh()->access_enabled);
    }

    #[Test]
    public function a_sweep_that_changes_nothing_does_not_touch_the_hardware(): void
    {
        $member = $this->activeMember();
        $this->mapping($member, '112');

        FakeDeviceDriver::$upsertedWith = [];

        $result = app(AccessControlService::class)->syncAll();

        // A nightly run over every member must not rewrite every user record
        // on a small terminal to tell it what it already knows.
        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['changed']);
        $this->assertSame([], FakeDeviceDriver::$upsertedWith);
    }

    #[Test]
    public function an_unreachable_reader_does_not_stop_the_sweep(): void
    {
        $member = $this->activeMember();
        $this->mapping($member, '112');
        $this->lapse($member);

        $other = $this->activeMember();
        $this->mapping($other, '113');
        $this->lapse($other);

        // One reader will not take the write. The sweep has to carry on: the
        // others still need locking down tonight, and this one is retried
        // tomorrow.
        FakeDeviceDriver::$throwOnUpsertFor = '112';

        $result = app(AccessControlService::class)->syncAll();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(1, $result['failed']);

        // The one that worked is recorded as done; the one that failed is not
        // marked as pushed, so tomorrow's run tries it again.
        $this->assertFalse(BiometricUser::where('biometric_user_id', '113')->sole()->access_enabled);
        $this->assertTrue(BiometricUser::where('biometric_user_id', '112')->sole()->access_enabled);
    }

    #[Test]
    public function a_member_enrolled_while_lapsed_is_created_disabled(): void
    {
        $member = $this->member();

        // No membership at all. Putting them on the reader as admissible
        // would mean the door opens for somebody who has never paid.
        app(EnrollmentService::class)->enroll($member, $this->device);

        $this->assertSame(
            false,
            FakeDeviceDriver::$upsertedWith[0]['enabled'],
        );
    }
}
