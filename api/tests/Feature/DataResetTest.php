<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\BrandAsset;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Clearing operational data — the one action with no undo. */
class DataResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The service takes a backup first; keep it off the real disk.
        Storage::fake('local');
        config(['gms.backup.path' => 'test-backups']);
    }

    /** A member with a paid membership and a visit on record. */
    private function seedOperationalData(): Member
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));

        app(PaymentService::class)->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);

        Attendance::create([
            'branch_id' => $this->branch->id,
            'member_id' => $member->id,
            'attendance_date' => today(),
            'check_in_time' => now()->subHour(),
            'check_out_time' => now(),
            'status' => 'COMPLETED',
        ]);

        return $member->refresh();
    }

    #[Test]
    public function only_a_super_administrator_can_reach_it(): void
    {
        // Deliberately withheld from the Gym Administrator: this is a decision
        // for whoever owns the system, not whoever runs the gym day to day.
        foreach ([Permissions::ROLE_GYM_ADMIN, Permissions::ROLE_RECEPTIONIST, Permissions::ROLE_ACCOUNTANT] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/data-reset')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/data-reset', ['scope' => 'ATTENDANCE', 'password' => 'password', 'confirm' => 'RESET'])
                ->assertForbidden();
        }

        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/data-reset')->assertOk();
    }

    #[Test]
    public function the_preview_says_exactly_what_would_be_deleted(): void
    {
        // "2,481 attendance records and 96 payments" is a decision someone can
        // make. "Are you sure?" is not.
        $this->seedOperationalData();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/data-reset')->assertOk();

        $attendance = collect($response->json('scopes'))->firstWhere('scope', 'ATTENDANCE');

        $this->assertSame(1, $attendance['counts']['attendance']);
        $this->assertArrayNotHasKey('payments', $attendance['counts']);

        $members = collect($response->json('scopes'))->firstWhere('scope', 'MEMBERS');
        $this->assertSame(1, $members['counts']['payments']);
        $this->assertSame(1, $members['counts']['members']);
    }

    #[Test]
    public function a_wrong_password_deletes_nothing(): void
    {
        // A permission check proves the session is valid, not that the person
        // at the keyboard is the one who opened it.
        $this->seedOperationalData();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', [
                'scope' => 'MEMBERS',
                'password' => 'not-the-password',
                'confirm' => 'RESET',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(1, Member::count());
    }

    #[Test]
    public function a_missing_confirmation_deletes_nothing(): void
    {
        $this->seedOperationalData();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'MEMBERS', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->assertSame(1, Member::count());
    }

    #[Test]
    public function the_attendance_scope_leaves_members_and_money_alone(): void
    {
        $this->seedOperationalData();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'ATTENDANCE', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertOk();

        $this->assertSame(0, Attendance::count());
        $this->assertSame(1, Member::count());
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function the_members_scope_clears_them_and_everything_hanging_off_them(): void
    {
        $this->seedOperationalData();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'MEMBERS', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertOk();

        $this->assertSame(0, Member::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Attendance::count());
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    #[Test]
    public function staff_settings_and_branding_always_survive(): void
    {
        // Wiping the accounts that perform the reset would lock everyone out
        // of the system they were trying to clean.
        $this->seedOperationalData();
        $admin = $this->admin();

        Setting::set('branding.gym_name', 'Iron House Fitness');
        BrandAsset::create([
            'type' => 'LOGO',
            'path' => 'branding/logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'EVERYTHING', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertOk();

        $this->assertGreaterThan(0, User::count());
        $this->assertSame('Iron House Fitness', Setting::get('branding.gym_name'));
        $this->assertSame(1, BrandAsset::count());
        $this->assertDatabaseCount('branches', 1);
    }

    #[Test]
    public function the_reset_is_written_to_the_audit_log_which_survives_it(): void
    {
        // The audit log is the record of what was done, including this.
        $this->seedOperationalData();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'EVERYTHING', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'data_reset']);

        $entry = AuditLog::where('event', 'data_reset')->first();

        $this->assertStringContainsString('Backup taken first', $entry->description);
        $this->assertSame('EVERYTHING', $entry->new_values['scope']);
    }

    #[Test]
    public function a_backup_is_taken_before_anything_is_deleted(): void
    {
        $this->seedOperationalData();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'ATTENDANCE', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertOk();

        $this->assertNotNull($response->json('data.backup'));
        $this->assertDatabaseHas('backup_logs', ['status' => 'SUCCESS']);
    }

    #[Test]
    public function an_unknown_scope_is_refused(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/data-reset', ['scope' => 'DROP_EVERYTHING', 'password' => 'password', 'confirm' => 'RESET'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }
}
