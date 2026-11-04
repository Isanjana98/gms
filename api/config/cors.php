<?php

/**
 * Cross-origin rules.
 *
 * Since the session moved into a cookie, this file is load-bearing for
 * security rather than just for convenience. `supports_credentials` is true,
 * which means the browser will send the session cookie on cross-origin
 * requests to the origins listed here — so this list is, in effect, the set of
 * sites allowed to act as a signed-in user.
 *
 * Two rules follow from that, and the browser enforces the first one itself:
 *
 *  - '*' is not allowed here at all. A wildcard origin with credentials is
 *    rejected outright by every browser, and if it were not, it would let any
 *    site on the internet make authenticated calls from a logged-in
 *    receptionist's session.
 *  - Every entry must be an exact origin that we control.
 *
 * In production the front end is normally served from the same origin as the
 * API behind one reverse proxy, in which case none of this applies to it and
 * FRONTEND_URL can be left unset.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL'),
        // The Vite dev server. Expo's web target is covered by the pattern
        // below instead, because it does not keep a fixed port.
        env('APP_ENV') === 'production' ? null : 'http://localhost:5173',
        env('APP_ENV') === 'production' ? null : 'http://127.0.0.1:5173',
    ]))),

    /*
     * Development only: any loopback port.
     *
     * Expo does not get to keep a fixed port. It asks for one, and when that
     * is taken - a second bundler, a leftover from the last run - it silently
     * moves to the next free one. A hard-coded list of ports therefore breaks
     * at the least convenient moment, with a CORS error that looks nothing
     * like "your bundler moved".
     *
     * This widens development and nothing else: the guard returns an empty
     * list under APP_ENV=production, so a deployed API still answers only the
     * exact origins above.
     *
     * What it costs, stated plainly: in development, any page served from
     * loopback can make credentialed calls to this API. That is a real
     * loosening, and it is acceptable only because it is confined to a
     * developer's own machine. It must never be relaxed in production, where
     * the cookie session lives - and where the front end is normally served
     * from the same origin as the API anyway, so none of this applies.
     *
     * The phone apps do not depend on this once built: they authenticate with
     * a bearer token, and CORS is a browser rule that a native app never meets.
     */
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^http://(localhost|127\.0\.0\.1):\d+$#',
    ],

    // Named rather than '*': with credentials enabled, a wildcard would let a
    // cross-origin caller set the very headers the CSRF check relies on.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-GMS-Request',
        'X-XSRF-TOKEN',
    ],

    // Lets the browser read the filename on a streamed CSV or PDF download.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    // The session now travels in a cookie, so cross-origin requests from the
    // front end must be allowed to carry it.
    'supports_credentials' => true,

];
