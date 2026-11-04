<?php

namespace Tests\Feature;

use App\Services\SessionCookie;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/** SRS 26 - How the session is carried, and what stops it being stolen or ridden. */
class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sign in and hand back the session cookie that was set.
     *
     * Requests that use it need `withCredentials()` as well as the cookie
     * itself: Laravel's JSON test helpers deliberately send no cookies without
     * it, mirroring the browser rule that a fetch only carries credentials
     * when asked to. That is the same rule the front end satisfies with
     * `withCredentials: true` on its axios client.
     */
    private function signIn(): array
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        // Not the readable companion cookie, which shares the prefix.
        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $c) => str_contains($c->getName(), 'gms_session') && ! str_ends_with($c->getName(), '_active'));

        return [$user, $cookie];
    }

    #[Test]
    public function the_session_cookie_is_http_only_and_same_site(): void
    {
        [, $cookie] = $this->signIn();

        $this->assertNotNull($cookie, 'Signing in did not set a session cookie.');

        // httpOnly is the whole point: without it, script on the page can read
        // the session and an XSS hole becomes a stolen account.
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        // No Domain attribute — one would share the cookie with every
        // subdomain, any of which could then set or read it.
        $this->assertNull($cookie->getDomain());
    }

    #[Test]
    public function the_readable_companion_cookie_carries_no_secret(): void
    {
        // It exists only so an anonymous visitor need not make a request that
        // can only come back 401. If it ever carried the token, the httpOnly
        // flag on the real cookie would be pointless.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $cookies = collect($response->headers->getCookies());
        $session = $cookies->first(fn (Cookie $c) => ! str_ends_with($c->getName(), '_active'));
        $companion = $cookies->first(fn (Cookie $c) => str_ends_with($c->getName(), '_active'));

        $this->assertNotNull($companion);
        $this->assertSame('1', $companion->getValue());
        $this->assertFalse($companion->isHttpOnly(), 'The companion cookie has to be readable to be useful.');
        // The token format is "<id>|<secret>". Nothing resembling it belongs
        // in a cookie that script can read.
        $this->assertStringNotContainsString('|', (string) $companion->getValue());
        $this->assertNotSame($session->getValue(), $companion->getValue());
    }

    #[Test]
    public function the_cookie_authenticates_a_request_with_no_authorization_header(): void
    {
        [, $cookie] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    #[Test]
    public function a_cookie_authenticated_write_without_the_request_header_is_refused(): void
    {
        /*
         * The CSRF case. A cross-site form can make the browser send the
         * session cookie on a POST, but it cannot set a custom header — so a
         * write arriving without one is not from our front end.
         *
         * withoutMiddleware is not used here: this is the middleware under
         * test, and the point is that it refuses before the controller runs.
         */
        [, $cookie] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->post('/api/auth/logout', [], ['Accept' => 'application/json'])
            ->assertStatus(419);
    }

    #[Test]
    public function the_same_write_succeeds_with_the_request_header(): void
    {
        [, $cookie] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->post('/api/auth/logout', [], [
                'Accept' => 'application/json',
                'X-GMS-Request' => '1',
            ])
            ->assertOk();
    }

    #[Test]
    public function a_cookie_authenticated_read_needs_no_header(): void
    {
        // A GET changes nothing, so requiring the header would only break
        // ordinary navigation without preventing anything.
        [, $cookie] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->get('/api/auth/me', ['Accept' => 'application/json'])
            ->assertOk();
    }

    #[Test]
    public function a_bearer_token_is_never_subject_to_the_header_check(): void
    {
        // Nothing sends a bearer token automatically, so cross-site request
        // forgery does not apply to it and integrations must keep working.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $token = $user->createToken('integration')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->post('/api/auth/logout')
            ->assertOk();
    }

    #[Test]
    public function signing_out_clears_the_cookie_and_revokes_the_token(): void
    {
        [$user, $cookie] = $this->signIn();

        $response = $this->withCredentials()
            ->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->postJson('/api/auth/logout', [], ['X-GMS-Request' => '1'])
            ->assertOk();

        $cleared = collect($response->headers->getCookies())
            ->first(fn (Cookie $c) => $c->getName() === $cookie->getName());

        $this->assertNotNull($cleared);
        $this->assertSame('', (string) $cleared->getValue());
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function an_integration_can_still_ask_for_a_bearer_token(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'issue_token' => true,
        ])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    #[Test]
    public function a_session_unused_past_the_idle_timeout_is_ended(): void
    {
        config(['gms.security.idle_timeout_minutes' => 30]);

        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $token = $user->createToken('web');

        // As though the terminal had been left alone for an hour.
        PersonalAccessToken::whereKey($token->accessToken->id)
            ->update(['last_used_at' => now()->subHour()]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        // Deleted, not merely refused — otherwise the next keystroke would
        // bring the session back.
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function a_recently_used_session_survives(): void
    {
        config(['gms.security.idle_timeout_minutes' => 30]);

        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $token = $user->createToken('web');

        PersonalAccessToken::whereKey($token->accessToken->id)
            ->update(['last_used_at' => now()->subMinutes(5)]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    #[Test]
    public function security_headers_are_on_every_response_including_refusals(): void
    {
        $response = $this->getJson('/api/members')->assertStatus(401);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function hsts_is_not_sent_over_plain_http(): void
    {
        // Sending it over HTTP is meaningless, and from a dev machine it would
        // pin localhost to HTTPS in the developer's own browser.
        $this->getJson('/api/auth/me')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function a_user_sees_only_their_own_sessions(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $other = $this->userWithRole(Permissions::ROLE_ACCOUNTANT);

        $user->createToken('phone');
        $other->createToken('their laptop');

        $sessions = $this->actingAs($user)
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $sessions);
        $this->assertSame('phone', $sessions[0]['name']);
    }

    #[Test]
    public function one_user_cannot_revoke_another_users_session(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $other = $this->userWithRole(Permissions::ROLE_ACCOUNTANT);

        $victim = $other->createToken('their laptop')->accessToken;

        $this->actingAs($user)
            ->deleteJson("/api/auth/sessions/{$victim->id}")
            ->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }

    #[Test]
    public function signing_out_everywhere_else_keeps_the_current_session(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $current = $user->createToken('this device');
        $user->createToken('old phone');
        $user->createToken('shared terminal');

        $this->withHeaders(['Authorization' => 'Bearer '.$current->plainTextToken])
            ->postJson('/api/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJsonPath('ended', 2);

        $this->assertSame(1, $user->tokens()->count());

        // And the one that pressed the button still works.
        $this->withHeaders(['Authorization' => 'Bearer '.$current->plainTextToken])
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    #[Test]
    public function a_session_records_where_it_was_signed_in_from(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'])
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $session = $this->actingAs($user->refresh())
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->json('data.0');

        // Chrome's agent string also contains "Safari"; reporting it as Safari
        // would make the list useless for spotting an unfamiliar device.
        $this->assertSame('Chrome on Windows', $session['device']);
    }

    #[Test]
    public function the_session_cookie_is_secure_over_https(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $response = $this->postJson('https://localhost/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $c) => str_contains($c->getName(), 'gms_session'));

        $this->assertTrue($cookie->isSecure());
        // The __Host- prefix is enforced by the browser: it will not accept
        // such a cookie from a subdomain, which closes off session fixation.
        $this->assertStringStartsWith('__Host-', $cookie->getName());
    }

    #[Test]
    public function the_cookie_name_has_no_host_prefix_over_plain_http(): void
    {
        // A __Host- cookie must be Secure, so over HTTP the browser would
        // reject it outright and sign-in would silently do nothing.
        [, $cookie] = $this->signIn();

        $this->assertSame('gms_session', $cookie->getName());
        $this->assertFalse($cookie->isSecure());
    }

    #[Test]
    public function the_service_agrees_with_itself_about_the_cookie_name(): void
    {
        // forget() must match make() on name, path and flags or the browser
        // keeps the original cookie and the user cannot sign out.
        $cookies = app(SessionCookie::class);
        $made = $cookies->make('token', now()->addDay());
        $forgotten = $cookies->forget();

        $this->assertSame($made->getName(), $forgotten->getName());
        $this->assertSame($made->getPath(), $forgotten->getPath());
        $this->assertSame($made->isSecure(), $forgotten->isSecure());
        $this->assertSame($made->getSameSite(), $forgotten->getSameSite());
    }

    #[Test]
    public function a_loopback_origin_on_any_port_is_allowed_in_development(): void
    {
        // Expo moves to another port whenever its default is taken, so the
        // allowlist cannot be a fixed set of port numbers.
        $patterns = config('cors.allowed_origins_patterns');

        foreach (['http://localhost:8082', 'http://127.0.0.1:19006', 'http://localhost:5173'] as $origin) {
            $this->assertTrue(
                collect($patterns)->contains(fn (string $p) => preg_match($p, $origin) === 1)
                    || in_array($origin, config('cors.allowed_origins'), true),
                "{$origin} should be allowed in development"
            );
        }
    }

    #[Test]
    public function a_non_loopback_origin_is_never_matched_by_the_pattern(): void
    {
        // The guard against a pattern that is looser than it looks: these all
        // contain "localhost" or "127.0.0.1" somewhere, and none of them is us.
        $patterns = config('cors.allowed_origins_patterns');

        $hostile = [
            'http://localhost.attacker.com',
            'http://127.0.0.1.attacker.com',
            'https://evil.com/?x=http://localhost:8082',
            'http://notlocalhost:8082',
            'http://localhost:8082.attacker.com',
        ];

        foreach ($hostile as $origin) {
            foreach ($patterns as $pattern) {
                $this->assertSame(0, preg_match($pattern, $origin), "{$origin} must not match {$pattern}");
            }
        }
    }

    #[Test]
    public function production_drops_every_development_origin(): void
    {
        // FRONTEND_URL is whatever the operator set and is deliberately left
        // alone - on a developer's machine it is a localhost URL, and that is
        // correct. What must not survive into production is the part this file
        // adds by itself: the dev server origins and the loopback pattern.
        $restore = [
            'env' => $_ENV['APP_ENV'] ?? null,
            'server' => $_SERVER['APP_ENV'] ?? null,
            'put' => getenv('APP_ENV'),
            'frontend' => getenv('FRONTEND_URL'),
        ];

        // Laravel's env() reads through several adapters - $_SERVER among them
        // - so setting only one of these leaves the old value visible.
        foreach (['APP_ENV' => 'production', 'FRONTEND_URL' => ''] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $fresh = require base_path('config/cors.php');

            $this->assertSame([], $fresh['allowed_origins_patterns']);
            $this->assertSame([], $fresh['allowed_origins']);
        } finally {
            putenv($restore['put'] === false ? 'APP_ENV' : 'APP_ENV='.$restore['put']);
            putenv($restore['frontend'] === false ? 'FRONTEND_URL' : 'FRONTEND_URL='.$restore['frontend']);
            $_ENV['APP_ENV'] = $restore['env'];
            $_SERVER['APP_ENV'] = $restore['server'];
            unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL']);
        }
    }

    #[Test]
    public function credentials_are_allowed_but_the_origin_is_never_a_wildcard(): void
    {
        // A wildcard origin with credentials is rejected by every browser, and
        // would be a disaster if it were not.
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
    }
}
