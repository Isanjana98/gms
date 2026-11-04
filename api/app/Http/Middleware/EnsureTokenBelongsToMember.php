<?php

namespace App\Http\Middleware;

use App\Enums\MemberStatus;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other half of the wall: only members reach the member app's API.
 *
 * A staff token must not work here either. Not because it would be dangerous —
 * these endpoints only ever return the caller's own data — but because
 * `$request->user()` is typed as a Member throughout, and a staff token would
 * produce confusing failures deep inside controllers instead of one clear
 * refusal at the door.
 *
 * A member barred since their token was issued is cut off here too, so
 * blacklisting somebody at reception takes effect on their phone immediately
 * rather than whenever the token happens to expire.
 */
class EnsureTokenBelongsToMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user();

        if (! $member instanceof Member) {
            return response()->json([
                'message' => 'This endpoint is for members.',
            ], 403);
        }

        if ($member->status === MemberStatus::BLACKLISTED) {
            // Their tokens go too, so the block is permanent rather than
            // lasting only as long as this request.
            $member->tokens()->delete();

            return response()->json([
                'message' => 'This account is no longer active. Please speak to reception.',
            ], 403);
        }

        return $next($request);
    }
}
