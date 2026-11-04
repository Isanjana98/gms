<?php

namespace App\Http\Middleware;

use App\Services\SessionCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SRS 26 - Lets the httpOnly session cookie authenticate the request.
 *
 * Sanctum authenticates from the Authorization header. The browser holds the
 * token in a cookie it cannot read, so this promotes the cookie into that
 * header before the guard looks at it. Everything downstream — abilities,
 * expiry, revocation, `$request->user()` — is unchanged; only the transport
 * differs.
 *
 * A caller that sends its own Authorization header is left alone, so
 * integrations and the fingerprint readers keep working with bearer tokens.
 */
class AuthenticateFromSessionCookie
{
    /** Methods that cannot change anything, and so need no CSRF defence. */
    private const READ_ONLY = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly SessionCookie $cookies) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Registered globally so nothing can sort it after the auth guard, so
        // it has to confine itself to the API rather than every route.
        if (! $request->is('api/*')) {
            return $next($request);
        }

        // An explicit bearer token wins. It was chosen deliberately by the
        // caller and is not sent automatically by a browser, so none of the
        // cross-site reasoning below applies to it.
        if ($request->bearerToken()) {
            return $next($request);
        }

        $token = $request->cookies->get($this->cookies->name($request));

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        if ($this->isCrossSiteWrite($request)) {
            return response()->json([
                'message' => 'This request was refused because it did not come from the application.',
            ], 419);
        }

        $request->headers->set('Authorization', 'Bearer '.$token);

        return $next($request);
    }

    /**
     * A state-changing request that cannot be shown to have come from our own
     * front end.
     *
     * SameSite=Lax already stops the browser sending the session cookie on a
     * cross-site POST, so this is the second line rather than the first — it
     * covers a browser that does not honour SameSite, and a future deployment
     * where somebody relaxes the cookie without thinking it through.
     *
     * The test is a custom header. A cross-site HTML form cannot set one at
     * all, and a cross-site fetch that sets one must first pass a CORS
     * preflight, which config/cors.php answers only for our own origins.
     */
    private function isCrossSiteWrite(Request $request): bool
    {
        if (! config('gms.security.require_request_header', true)) {
            return false;
        }

        if (in_array($request->getMethod(), self::READ_ONLY, true)) {
            return false;
        }

        // Either header is proof enough: both are impossible to set from a
        // cross-site form, which is the attack being closed off.
        return ! $request->hasHeader('X-Requested-With')
            && ! $request->hasHeader('X-GMS-Request');
    }
}
