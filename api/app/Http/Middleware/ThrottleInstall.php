<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting for the installer, counted on disk rather than in the cache.
 *
 * `throttle:15,1` cannot be used here. It goes through the RateLimiter, which
 * uses the *default* cache store, and that store is the database:
 *
 *     select * from "cache" where "key" in (...)
 *
 * On a freshly unzipped application there is no database yet - it is what the
 * installer is about to create - so the throttle threw before the form could
 * be drawn, and /install answered 500. Same shape of problem as the `web`
 * middleware group this route was also wrongly carrying; see
 * routes/install.php.
 *
 * The file store needs nothing but `storage/framework/cache/data`, which the
 * release archive ships empty for exactly this reason.
 *
 * Why throttle at all, when EnsureNotInstalled closes these routes the moment
 * an installation exists: the window before that is when someone can submit
 * the form, and the form takes database credentials. Unlimited attempts would
 * make this a quiet way to test guesses against the host's MySQL.
 */
class ThrottleInstall
{
    /** Attempts allowed per window, matching the throttle:15,1 it replaces. */
    private const ATTEMPTS = 15;

    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $store = Cache::store('file');
        $key = 'install-throttle:'.sha1((string) $request->ip());

        if ((int) $store->get($key, 0) >= self::ATTEMPTS) {
            abort(429, 'Too many attempts. Wait a minute and try again.');
        }

        /*
         * add() only writes when the key is absent, so the window starts at
         * the first attempt and is not pushed forward by the ones after it.
         * Were this a plain put() with a fresh TTL each time, an attacker
         * making steady requests would never let the window close.
         */
        if (! $store->add($key, 1, self::WINDOW_SECONDS)) {
            $store->increment($key);
        }

        return $next($request);
    }
}
