<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Signing in to the member app. */
class MemberAuthController extends Controller
{
    /**
     * Tokens for the member app last a long time deliberately.
     *
     * A member opens this perhaps once a fortnight, to check when their
     * membership runs out. Being signed out every time would mean an SMS round
     * trip on every visit, and a person who gives up is a person who turns up
     * to a lapsed membership instead.
     *
     * The exposure is small: a member token reads only that member's own
     * record, and can be revoked from the phone or by blacklisting at
     * reception.
     */
    private const TOKEN_DAYS = 180;

    public function __construct(private readonly MemberAuthService $auth) {}

    /**
     * POST /api/member/auth/request-code
     *
     * Rate limited hard, by identifier and by address. This endpoint sends an
     * SMS on each call, so without a limit it is both a way to enumerate
     * members and a way to run up the gym's messaging bill from a laptop.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ]);

        $key = 'member-code:'.mb_strtolower($data['identifier']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'identifier' => sprintf(
                    'Too many requests. Try again in %d seconds.',
                    RateLimiter::availableIn($key),
                ),
            ])->status(429);
        }

        RateLimiter::hit($key, 300);

        $result = $this->auth->requestCode($data['identifier'], $request->ip());

        if (! $result['sent']) {
            return response()->json([
                'message' => 'We have no phone number or email address on file for you. Please ask reception to add one.',
            ], 422);
        }

        return response()->json([
            // Worded so it says nothing about whether the identifier matched.
            'message' => 'If that matches a membership, a code is on its way.',
            'channel' => $result['channel'],
            'sent_to' => $result['hint'],
            'expires_in_minutes' => (int) config('gms.member_auth.code_ttl_minutes', 10),
            // Present only in local development; see MemberAuthService.
            ...($result['debug_code'] ? ['debug_code' => $result['debug_code']] : []),
        ]);
    }

    /** POST /api/member/auth/verify */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $key = 'member-verify:'.mb_strtolower($data['identifier']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please request a new code shortly.',
            ])->status(429);
        }

        RateLimiter::hit($key, 900);

        $member = $this->auth->verify($data['identifier'], $data['code']);

        if ($member === null) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right, or it has expired.',
            ]);
        }

        RateLimiter::clear($key);

        $expiresAt = now()->addDays(self::TOKEN_DAYS);

        // Scoped to a single ability. Even if this token were somehow accepted
        // by a staff route, it could do nothing there.
        $token = $member->createToken(
            $data['device_name'] ?? 'member app',
            ['member'],
            $expiresAt,
        );

        $token->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'member' => $this->profile($member),
        ]);
    }

    /** POST /api/member/auth/logout — revokes only the token that called it. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->ok('Signed out.');
    }

    /** GET /api/member/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['member' => $this->profile($request->user())]);
    }

    /**
     * What the member app shows on its home screen.
     *
     * Deliberately narrow. A member reads their own name, their membership and
     * when it runs out — not the internal notes reception keeps on them, not
     * their trainer's contact details, and nothing about any other member.
     *
     * @return array<string, mixed>
     */
    private function profile(Member $member): array
    {
        $member->loadMissing(['currentMembership', 'branch']);
        $membership = $member->currentMembership;

        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'membership_number' => $member->membership_number,
            'telephone' => $member->telephone,
            'email' => $member->email,
            'photo_url' => $member->photo_path ? url('storage/'.$member->photo_path) : null,
            'status' => $member->status?->value,
            'join_date' => $member->join_date?->toDateString(),
            'total_visits' => (int) $member->total_visits,
            'last_visit_at' => $member->last_visit_at?->toIso8601String(),
            'branch' => $member->branch?->name,

            'membership' => $membership ? [
                'reference' => $membership->reference,
                'plan_name' => $membership->plan_name,
                'start_date' => $membership->start_date?->toDateString(),
                'end_date' => $membership->end_date?->toDateString(),
                'days_remaining' => $membership->days_remaining,
                'is_expired' => $membership->is_expired,
                'status' => $membership->status?->value,
                'balance' => round((float) $membership->balance, 2),
            ] : null,
        ];
    }
}
