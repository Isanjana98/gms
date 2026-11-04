<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberLoginCode;
use App\Models\Notification;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The member mobile app: signing in, and seeing only your own data. */
class MemberAppTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPhone(array $attributes = []): Member
    {
        return $this->member(['telephone' => '0771234567', ...$attributes]);
    }

    /** Sign in and return the member's bearer token. */
    private function signIn(Member $member): string
    {
        config(['gms.member_auth.expose_code' => true]);

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->assertOk()->json('debug_code');

        return $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $code,
        ])->assertOk()->json('token');
    }

    private function asMember(Member $member): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->signIn($member)]);
    }

    /**
     * Forget the resolved auth guard between requests.
     *
     * Laravel keeps one application instance for the whole test, and the guard
     * caches the user it resolved. In production every request builds a fresh
     * container, so without this a test cannot see a change that takes effect
     * between two requests — revoking a token, or blacklisting a member — and
     * would report the *test harness* as working when the feature is not.
     */
    private function forgetResolvedUser(): void
    {
        $this->app->forgetInstance('auth');
        Auth::clearResolvedInstances();
    }

    // ------------------------------------------------------------- signing in

    #[Test]
    public function a_member_signs_in_with_a_code_sent_to_their_phone(): void
    {
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone(['full_name' => 'Nimal Perera']);

        $request = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->assertOk();

        $this->assertSame('SMS', $request->json('channel'));
        // Masked: enough to recognise, not enough to learn.
        $this->assertSame('******4567', $request->json('sent_to'));

        $verify = $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $request->json('debug_code'),
        ])->assertOk();

        $this->assertNotEmpty($verify->json('token'));
        $this->assertSame('Nimal Perera', $verify->json('member.full_name'));
    }

    #[Test]
    public function a_member_can_sign_in_with_their_telephone_or_email(): void
    {
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone(['email' => 'nimal@example.lk']);

        foreach ([$member->telephone, $member->email, $member->membership_number] as $identifier) {
            $this->postJson('/api/member/auth/request-code', ['identifier' => $identifier])
                ->assertOk()
                ->assertJsonPath('channel', 'SMS');
        }

        $this->assertSame(3, MemberLoginCode::query()->count());
    }

    #[Test]
    public function the_code_is_never_stored_in_the_clear(): void
    {
        // A stolen database must not hand over live sign-in codes.
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone();

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->assertOk()->json('debug_code');

        $stored = MemberLoginCode::query()->firstOrFail();

        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString($code, $stored->code_hash);
    }

    #[Test]
    public function an_unknown_identifier_gets_the_same_answer_as_a_real_one(): void
    {
        /*
         * Otherwise this endpoint answers "is this person a member here?" for
         * anybody who asks, which is a question about somebody's gym
         * attendance that strangers should not be able to put to the system.
         */
        $member = $this->memberWithPhone();

        $known = $this->postJson('/api/member/auth/request-code', ['identifier' => $member->membership_number]);
        $unknown = $this->postJson('/api/member/auth/request-code', ['identifier' => 'GM-2026-99999']);

        $known->assertOk();
        $unknown->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(0, MemberLoginCode::query()->whereNot('member_id', $member->id)->count());
    }

    #[Test]
    public function the_code_expires(): void
    {
        config(['gms.member_auth.expose_code' => true, 'gms.member_auth.code_ttl_minutes' => 10]);
        $member = $this->memberWithPhone();

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        $this->travel(11)->minutes();

        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $code,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_code_works_once(): void
    {
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone();

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        $payload = ['identifier' => $member->membership_number, 'code' => $code];

        $this->postJson('/api/member/auth/verify', $payload)->assertOk();
        $this->postJson('/api/member/auth/verify', $payload)->assertStatus(422);
    }

    #[Test]
    public function guessing_is_limited(): void
    {
        // Six digits is only safe because of this. Without an attempt limit a
        // million guesses is an afternoon's work.
        config(['gms.member_auth.expose_code' => true, 'gms.member_auth.max_attempts' => 3]);
        $member = $this->memberWithPhone();

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/member/auth/verify', [
                'identifier' => $member->membership_number,
                'code' => '000000',
            ])->assertStatus(422);
        }

        // The real code is dead too: the record was destroyed, not just the
        // attempt refused.
        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $code,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_wrong_guess_does_not_expire_the_code(): void
    {
        /*
         * A regression test with a specific cause.
         *
         * `expires_at` was declared as TIMESTAMP, and MariaDB gives the first
         * TIMESTAMP column in a table an implicit
         * `ON UPDATE current_timestamp()`. Counting a wrong attempt updates
         * the row — which silently reset the expiry to now, so one mistyped
         * digit expired the code the member was still looking at. The column
         * is DATETIME for that reason.
         */
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone();

        $code = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => '000000',
        ])->assertStatus(422);

        // Still live, and still the right code.
        $this->assertSame(1, MemberLoginCode::query()->live()->count());

        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $code,
        ])->assertOk();
    }

    #[Test]
    public function requesting_a_second_code_kills_the_first(): void
    {
        // Otherwise "request one, request another, try both" quietly doubles
        // the attacker's allowance.
        config(['gms.member_auth.expose_code' => true]);
        $member = $this->memberWithPhone();

        $first = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        $second = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->json('debug_code');

        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $first,
        ])->assertStatus(422);

        $this->postJson('/api/member/auth/verify', [
            'identifier' => $member->membership_number,
            'code' => $second,
        ])->assertOk();
    }

    #[Test]
    public function the_code_goes_out_through_the_normal_outbox(): void
    {
        // So it follows whatever provider the gym has configured, and appears
        // in the same delivery log as everything else.
        $member = $this->memberWithPhone(['email' => 'nimal@example.lk']);

        $this->postJson('/api/member/auth/request-code', ['identifier' => $member->membership_number])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'member_id' => $member->id,
            'type' => 'LOGIN_CODE',
            'channel' => 'SMS',
        ]);

        $this->assertGreaterThan(0, Notification::query()->where('type', 'LOGIN_CODE')->count());
    }

    #[Test]
    public function the_code_is_never_exposed_in_production(): void
    {
        /*
         * The convenience that makes the app testable must not survive a
         * deployment where someone sets the flag by mistake.
         */
        config(['gms.member_auth.expose_code' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $member = $this->memberWithPhone();

        $response = $this->postJson('/api/member/auth/request-code', [
            'identifier' => $member->membership_number,
        ])->assertOk();

        $this->assertNull($response->json('debug_code'));
    }

    #[Test]
    public function a_member_with_no_contact_details_is_told_to_see_reception(): void
    {
        $member = $this->member(['telephone' => null, 'email' => null]);

        $this->postJson('/api/member/auth/request-code', ['identifier' => $member->membership_number])
            ->assertStatus(422)
            ->assertJsonPath('message', 'We have no phone number or email address on file for you. Please ask reception to add one.');
    }

    #[Test]
    public function a_blacklisted_member_cannot_sign_in(): void
    {
        $member = $this->memberWithPhone(['status' => MemberStatus::BLACKLISTED]);

        $this->postJson('/api/member/auth/request-code', ['identifier' => $member->membership_number])
            ->assertOk();

        // Answered the same way as an unknown identifier, and nothing sent.
        $this->assertSame(0, MemberLoginCode::query()->count());
    }

    // ------------------------------------------------------- the wall

    #[Test]
    public function a_member_token_cannot_reach_the_staff_api(): void
    {
        /*
         * The single most important test in this file.
         *
         * Sanctum's token table is polymorphic, so a member's token is a valid
         * token as far as auth:sanctum is concerned. Without the staff.only
         * middleware, `auth/me` and the session endpoints — which have no
         * permission gate — would hand staff session data to a member.
         */
        $member = $this->memberWithPhone();
        $headers = ['Authorization' => 'Bearer '.$this->signIn($member)];

        foreach ([
            '/api/auth/me',
            '/api/auth/sessions',
            '/api/members',
            '/api/payments',
            '/api/dashboard',
            '/api/reports/revenue',
        ] as $endpoint) {
            $this->withHeaders($headers)->getJson($endpoint)->assertForbidden();
        }
    }

    #[Test]
    public function a_staff_token_cannot_reach_the_member_app(): void
    {
        $staff = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $token = $staff->createToken('desk')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/member/me')
            ->assertForbidden();
    }

    #[Test]
    public function a_member_blacklisted_after_signing_in_is_cut_off_at_once(): void
    {
        $member = $this->memberWithPhone();
        $headers = ['Authorization' => 'Bearer '.$this->signIn($member)];

        $this->withHeaders($headers)->getJson('/api/member/me')->assertOk();

        $member->forceFill(['status' => MemberStatus::BLACKLISTED])->save();
        $this->forgetResolvedUser();

        $this->withHeaders($headers)->getJson('/api/member/me')->assertForbidden();
        // And their tokens are gone, so the block is permanent.
        $this->assertSame(0, $member->tokens()->count());
    }

    // ------------------------------------------------------ own data only

    #[Test]
    public function a_member_sees_only_their_own_attendance(): void
    {
        $mine = $this->memberWithPhone(['full_name' => 'Mine']);
        $theirs = $this->member(['full_name' => 'Somebody Else']);

        foreach ([$mine, $theirs] as $who) {
            Attendance::create([
                'branch_id' => $this->branch->id,
                'member_id' => $who->id,
                'attendance_date' => today(),
                'check_in_time' => now(),
                'attendance_type' => 'MANUAL',
                'status' => 'OPEN',
                'membership_valid' => true,
            ]);
        }

        $response = $this->asMember($mine)->getJson('/api/member/attendance')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('summary.total_visits'));
    }

    #[Test]
    public function a_member_sees_only_their_own_payments(): void
    {
        $mine = $this->memberWithPhone();
        $theirs = $this->member();

        foreach ([$mine, $theirs] as $who) {
            $membership = app(MembershipService::class)->create($who, $this->plan(['price' => 5000]));
            app(PaymentService::class)->record($who, ['amount' => 5000, 'membership_id' => $membership->id]);
        }

        $response = $this->asMember($mine)->getJson('/api/member/payments')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function a_member_cannot_download_somebody_elses_receipt(): void
    {
        $mine = $this->memberWithPhone();
        $theirs = $this->member();

        $membership = app(MembershipService::class)->create($theirs, $this->plan(['price' => 5000]));
        $payment = app(PaymentService::class)->record($theirs, ['amount' => 5000, 'membership_id' => $membership->id]);

        $this->asMember($mine)->getJson("/api/member/receipts/{$payment->id}")->assertNotFound();
    }

    #[Test]
    public function a_member_downloads_their_own_receipt(): void
    {
        $member = $this->memberWithPhone();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payment = app(PaymentService::class)->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);

        $response = $this->asMember($member)->get("/api/member/receipts/{$payment->id}");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function the_profile_does_not_leak_internal_notes(): void
    {
        // Reception keeps notes on members. They are for the gym, not for the
        // member to read back.
        $member = $this->memberWithPhone(['notes' => 'Chases discounts, do not offer.']);

        $response = $this->asMember($member)->getJson('/api/member/me')->assertOk();

        $response->assertDontSee('Chases discounts');
        $this->assertArrayNotHasKey('notes', $response->json('member'));
    }

    #[Test]
    public function the_profile_shows_the_membership_and_when_it_runs_out(): void
    {
        // The single thing a member opens this app to find out.
        $member = $this->memberWithPhone();
        app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));

        $response = $this->asMember($member->refresh())->getJson('/api/member/me')->assertOk();

        $this->assertNotNull($response->json('member.membership'));
        $this->assertNotNull($response->json('member.membership.end_date'));
        $this->assertIsInt($response->json('member.membership.days_remaining'));
    }

    #[Test]
    public function signing_out_revokes_only_that_device(): void
    {
        $member = $this->memberWithPhone();
        $phone = $this->signIn($member);
        $tablet = $this->signIn($member);

        $this->withHeaders(['Authorization' => 'Bearer '.$phone])
            ->postJson('/api/member/auth/logout')->assertOk();

        $this->forgetResolvedUser();
        $this->withHeaders(['Authorization' => 'Bearer '.$phone])
            ->getJson('/api/member/me')->assertUnauthorized();

        $this->forgetResolvedUser();
        $this->withHeaders(['Authorization' => 'Bearer '.$tablet])
            ->getJson('/api/member/me')->assertOk();
    }

    #[Test]
    public function the_timetable_and_gym_details_are_available_to_a_member(): void
    {
        $member = $this->memberWithPhone();

        $this->asMember($member)->getJson('/api/member/timetable')->assertOk()->assertJsonStructure(['data', 'days']);
        $this->asMember($member)->getJson('/api/member/gym')->assertOk()->assertJsonStructure(['name', 'branch']);
    }

    #[Test]
    public function the_member_api_needs_a_token(): void
    {
        foreach (['/api/member/me', '/api/member/attendance', '/api/member/payments'] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
    }
}
