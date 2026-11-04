<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 3 / 26 - Authentication and role-based access. */
class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function protected_endpoints_reject_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/members')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->postJson('/api/payments', [])->assertUnauthorized();
    }

    #[Test]
    public function an_unauthenticated_request_without_a_json_accept_header_is_a_401_not_a_500(): void
    {
        // A browser opening a PDF sends Accept: text/html, and Laravel's
        // default response to an unauthenticated guest is to redirect them to
        // a route named "login" - which does not exist in an API-only app, so
        // it raised a 500 instead. The only place this surfaced was exactly
        // the case the JSON tests do not cover.
        $this->get('/api/payments/1/receipt', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function a_valid_login_returns_the_users_permissions_but_not_the_token(): void
    {
        // The token goes into an httpOnly cookie, never the response body:
        // anything in the body is readable by script, and a session that
        // script can read is a session cross-site scripting can steal.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'roles', 'permissions']])
            ->assertJsonMissingPath('token')
            ->assertJsonPath('user.roles.0', Permissions::ROLE_RECEPTIONIST);
    }

    #[Test]
    public function a_wrong_password_gives_the_same_message_as_an_unknown_address(): void
    {
        // Distinguishing the two tells an attacker which addresses are real.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $unknownUser = $this->postJson('/api/auth/login', [
            'email' => 'nobody@test.local',
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownUser->json('errors.email'),
        );
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    #[Test]
    public function an_account_deactivated_mid_session_loses_access_immediately(): void
    {
        // Sanctum tokens do not expire on their own; disabling an account has
        // to take effect now, not whenever the token happens to lapse.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($user, 'sanctum')->getJson('/api/members')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->refresh(), 'sanctum')->getJson('/api/members')->assertForbidden();
    }

    #[Test]
    public function a_receptionist_can_run_the_front_desk_but_not_see_the_books(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($user, 'sanctum')->getJson('/api/members')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/attendance/live')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/revenue')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/users')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/audit-logs')->assertForbidden();
    }

    #[Test]
    public function an_accountant_sees_the_books_but_cannot_edit_members(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_ACCOUNTANT);

        $this->actingAs($user, 'sanctum')->getJson('/api/reports/revenue')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/members', ['full_name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function a_trainer_cannot_reach_payments(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_TRAINER);

        $this->actingAs($user, 'sanctum')->getJson('/api/payments')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/payments', [])->assertForbidden();
    }

    #[Test]
    public function a_super_administrator_reaches_everything(): void
    {
        $user = $this->admin();

        $this->actingAs($user, 'sanctum')->getJson('/api/members')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/revenue')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/audit-logs')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/settings')->assertOk();
    }

    #[Test]
    public function a_branch_bound_user_cannot_open_another_branchs_member(): void
    {
        // SRS 22 - branch scoping is enforced, not merely a filter default.
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $foreign = Member::create([
            'branch_id' => $other->id,
            'membership_number' => 'GM-OTHER-1',
            'full_name' => 'Other Branch Member',
            'join_date' => today(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/members/{$foreign->id}")
            ->assertForbidden();
    }

    #[Test]
    public function a_branch_bound_users_member_list_excludes_other_branches(): void
    {
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->member(['full_name' => 'Mine']);
        Member::create([
            'branch_id' => $other->id,
            'membership_number' => 'GM-OTHER-2',
            'full_name' => 'Theirs',
            'join_date' => today(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/members')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Mine');
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    #[Test]
    public function the_device_push_endpoint_requires_the_shared_secret(): void
    {
        $this->postJson('/api/biometric/push/ANY', ['AccessControllerEvent' => []])
            ->assertUnauthorized();

        $this->postJson('/api/biometric/push/ANY', ['AccessControllerEvent' => []], [
            'X-GMS-Token' => 'wrong-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_valid_push_token_for_an_unknown_device_is_a_404_not_a_401(): void
    {
        $this->postJson('/api/biometric/push/NO-SUCH-DEVICE', ['AccessControllerEvent' => []], [
            'X-GMS-Token' => 'test-push-token',
        ])->assertNotFound();
    }

    #[Test]
    public function the_device_password_is_never_returned_by_the_api(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/biometric/devices', [
            'code' => 'SEC-01',
            'name' => 'Secure Reader',
            'ip_address' => '192.0.2.10',
            'username' => 'admin',
            'password' => 'super-secret-password',
        ])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertTrue($response->json('data.has_password'));
        $this->assertStringNotContainsString('super-secret-password', $response->getContent());

        // Encrypted at rest, so a database dump is not a set of door keys.
        $stored = DB::table('biometric_devices')
            ->where('code', 'SEC-01')->value('password');

        $this->assertNotSame('super-secret-password', $stored);
    }
}
