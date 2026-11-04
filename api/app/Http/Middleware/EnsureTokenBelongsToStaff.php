<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wall between the member app and the staff API.
 *
 * Sanctum's token table is polymorphic, so once Member became authenticatable
 * a member's token is a perfectly valid token as far as `auth:sanctum` is
 * concerned. Every existing staff route would then resolve `$request->user()`
 * to a Member.
 *
 * Most of those routes would fail anyway — a Member holds no permissions, so
 * the permission middleware refuses it — but "most" is not a security
 * boundary. The two routes with no permission gate at all are `auth/me` and
 * the session endpoints, and a member reaching those would be reading staff
 * session data.
 *
 * So the check is on the type of the thing holding the token, not on what it
 * can do. A member is not a small staff account and must not be able to
 * become one by accumulating permissions.
 */
class EnsureTokenBelongsToStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user instanceof User) {
            return response()->json([
                'message' => 'This endpoint is for staff accounts.',
            ], 403);
        }

        return $next($request);
    }
}
