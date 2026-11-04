<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionCookie;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** SRS 29 - Authentication. */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 60;

    public function __construct(private readonly SessionCookie $cookies) {}

    /**
     * POST /api/auth/login
     *
     * Rate limited per email+IP. Without it, the login endpoint is an offline
     * password cracker with a network connection (SRS 26).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => sprintf(
                    'Too many login attempts. Try again in %d seconds.',
                    RateLimiter::availableIn($throttleKey),
                ),
            ])->status(429);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            // One message for both cases: saying "no such user" tells an
            // attacker which addresses are worth guessing passwords for.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact an administrator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $expiresAt = now()->addDays((int) config('gms.security.lifetime_days', 30));

        // Issued per device so signing out on the front desk terminal does not
        // sign the manager out on their phone.
        $token = $user->createToken(
            $credentials['device_name'] ?? 'web',
            ['*'],
            $expiresAt,
        );

        // Recorded so the session list can show someone where each of their
        // sessions came from, which is the only way they can spot one they do
        // not recognise.
        $token->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ])->save();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit($user, 'login', 'Signed in');

        /*
         * The token goes back in an httpOnly cookie, not in the body.
         *
         * A browser client never sees it and so cannot leak it: script on the
         * page cannot read the cookie, which is what stops a cross-site
         * scripting hole turning into a stolen session.
         *
         * Non-browser callers — an integration, a script — ask for the token
         * explicitly and manage it themselves. They are not subject to CSRF,
         * because nothing sends their credential automatically.
         */
        $payload = [
            'user' => $this->userPayload($user),
            'expires_at' => $expiresAt->toIso8601String(),
            'idle_timeout_minutes' => (int) config('gms.security.idle_timeout_minutes', 0),
        ];

        if ($request->boolean('issue_token')) {
            $payload['token'] = $token->plainTextToken;
        }

        return response()->json($payload)
            ->withCookie($this->cookies->make($token->plainTextToken, $expiresAt, $request))
            ->withCookie($this->cookies->makeHint($expiresAt, $request));
    }

    /** POST /api/auth/logout - revokes only the token that made the call. */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->currentToken($request)?->delete();

        $this->audit($user, 'logout', 'Signed out');

        // The cookie is cleared as well as the token revoked. Either alone
        // would do, but a browser left holding a cookie for a token that no
        // longer exists gets a 401 on its next request instead of a clean
        // sign-in screen.
        return $this->ok('Signed out.')
            ->withCookie($this->cookies->forget($request))
            ->withCookie($this->cookies->forgetHint($request));
    }

    /**
     * GET /api/auth/sessions - where this account is currently signed in.
     *
     * Every user can see their own, without any permission: a receptionist who
     * suspects someone used their login should not have to ask an
     * administrator to find out.
     */
    public function sessions(Request $request): JsonResponse
    {
        $current = $this->currentToken($request);

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'device' => $this->describeClient($token->user_agent),
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => $current !== null && $token->id === $current->id,
            ]);

        return response()->json([
            'data' => $sessions->all(),
            'idle_timeout_minutes' => (int) config('gms.security.idle_timeout_minutes', 0),
        ]);
    }

    /** DELETE /api/auth/sessions/{id} - end one session. */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        // Scoped to the caller's own tokens, so an id from another account
        // simply is not found rather than being revoked.
        $token = $request->user()->tokens()->whereKey($id)->first();

        abort_if($token === null, 404, 'That session no longer exists.');

        $isCurrent = $this->currentToken($request)?->id === $token->id;
        $token->delete();

        $this->audit($request->user(), 'session_revoked', 'Ended a session');

        $response = $this->ok($isCurrent ? 'Signed out.' : 'That session has been signed out.');

        return $isCurrent
            ? $response
                ->withCookie($this->cookies->forget($request))
                ->withCookie($this->cookies->forgetHint($request))
            : $response;
    }

    /**
     * POST /api/auth/sessions/revoke-others
     *
     * The "I think someone else is signed in as me" button. Deliberately keeps
     * the current session so the person pressing it is not locked out of the
     * screen they are trying to secure themselves from.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $current = $this->currentToken($request);

        $ended = $request->user()->tokens()
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        $this->audit($request->user(), 'sessions_revoked', "Signed out {$ended} other session(s)");

        return $this->ok(
            $ended === 0
                ? 'There were no other sessions to sign out.'
                : "Signed out {$ended} other session".($ended === 1 ? '.' : 's.'),
            ['ended' => $ended],
        );
    }

    /**
     * The stored token behind this request, or null if there is not one.
     *
     * `currentAccessToken()` does not always return a database row: a session
     * authenticated by Laravel's own guard — which is what `actingAs()` uses in
     * tests, and what Sanctum's stateful SPA mode uses — returns a
     * `TransientToken`, which has no id and cannot be revoked. Reading `->id`
     * off it is a 500, so every caller here goes through this instead.
     */
    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /**
     * A user agent reduced to something a person can recognise.
     *
     * Not for analytics — only so somebody scanning their session list can
     * tell "Chrome on Windows" from "Safari on iPhone" and notice the one that
     * is neither.
     */
    private function describeClient(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            // Chrome's agent string contains "Safari", so Chrome must be
            // tested first or every Chrome session reads as Safari.
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }

    /** GET /api/auth/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** POST /api/auth/password */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Every other session is now stale. A password change is usually a
        // response to a suspected compromise, so the other tokens must go.
        $current = $this->currentToken($request);

        $user->tokens()
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        $this->audit($user, 'password_changed', 'Changed their password');

        return $this->ok('Password updated. Other sessions have been signed out.');
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing('branch');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch ? ['id' => $user->branch->id, 'name' => $user->branch->name] : null,
            'is_multi_branch' => $user->isMultiBranch(),
            'roles' => $user->getRoleNames(),
            // The interface hides what the user cannot do; the API still
            // enforces it independently.
            //
            // A Super Administrator holds no explicit permissions - the
            // Gate::before rule grants everything - so the full catalogue is
            // reported for them. Sending an empty list would tell the
            // interface to hide every feature from the one account that can
            // use all of them.
            'permissions' => $user->hasRole(Permissions::ROLE_SUPER_ADMIN)
                ? Permissions::all()
                : $user->getAllPermissions()->pluck('name')->all(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    /** SRS 25 - Login and logout are audited events in their own right. */
    private function audit(User $user, string $event, string $description): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_role' => $user->getRoleNames()->implode(', '),
            'branch_id' => $user->branch_id,
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
            'url' => substr(request()->fullUrl(), 0, 512),
            'method' => request()->method(),
            'created_at' => now(),
        ]);
    }
}
