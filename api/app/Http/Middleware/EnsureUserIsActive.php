<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SRS 26 - A deactivated account stops working immediately.
 *
 * Sanctum tokens do not expire on their own, so without this a disabled
 * receptionist would keep full access until someone thought to revoke their
 * tokens. Their existing tokens are dropped here so the block is permanent
 * rather than lasting only as long as the request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'This account has been deactivated. Contact an administrator.',
            ], 403);
        }

        return $next($request);
    }
}
