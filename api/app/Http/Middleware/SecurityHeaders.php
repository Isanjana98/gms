<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that close off whole classes of attack before any
 * application code is involved.
 *
 * Cheap to add, invisible when working, and each one is here for a reason
 * rather than because a scanner asked for it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * Stop the browser guessing a response's type.
         *
         * Member documents are uploaded by staff and streamed back by the API.
         * Without this, a file that claims to be a PDF but contains markup can
         * be sniffed as HTML and executed on our origin — which, now that the
         * session lives in a cookie, is exactly the position we must not be in.
         */
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking: nothing here should ever be framed. Both headers,
        // because the older one is what some corporate proxies still read.
        $frameAncestors = (string) config('gms.security.headers.frame_ancestors', "'none'");
        $response->headers->set('X-Frame-Options', $frameAncestors === "'none'" ? 'DENY' : 'SAMEORIGIN');

        // Referrer: a receipt URL contains a payment id. It has no business
        // being sent to a third-party site in a Referer header.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nothing in a gym admin system needs a camera, a microphone or a
        // location, so nothing embedded in it should be able to ask.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        );

        /*
         * A content policy for API responses.
         *
         * These are JSON and streamed files, never pages, so the policy can be
         * as tight as it goes: nothing may load, nothing may be framed, and a
         * document served from this origin cannot be the base for relative
         * URLs. This is what turns a stored-XSS attempt inside a member note
         * into inert text if it is ever rendered from an API response.
         */
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors {$frameAncestors}; base-uri 'none'; form-action 'none'",
        );

        /*
         * HSTS, only over HTTPS.
         *
         * Sending it over plain HTTP is meaningless, and sending it from a
         * development machine would pin localhost to HTTPS in the developer's
         * browser — which is a genuinely annoying thing to undo.
         */
        $maxAge = (int) config('gms.security.headers.hsts_max_age', 0);

        if ($request->isSecure() && $maxAge > 0) {
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }
}
