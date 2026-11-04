<?php

use App\Console\Commands\AutoCloseAttendance;
use App\Console\Commands\BackupDatabase;
use App\Console\Commands\DispatchNotifications;
use App\Console\Commands\ExpireMemberships;
use App\Console\Commands\SyncBiometricAttendance;
use App\Console\Commands\SyncDeviceAccess;
use App\Http\Middleware\AuthenticateFromSessionCookie;
use App\Http\Middleware\EnsureTokenBelongsToMember;
use App\Http\Middleware\EnsureTokenBelongsToStaff;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active.user' => EnsureUserIsActive::class,
            // The wall between the member app and the staff API. See both
            // middleware classes for why type, not permission, is the test.
            'staff.only' => EnsureTokenBelongsToStaff::class,
            'member.only' => EnsureTokenBelongsToMember::class,
        ]);

        /*
         * SRS 26 - Session security.
         *
         * Order matters. AuthenticateFromSessionCookie has to run before the
         * Sanctum guard, because it is what turns the httpOnly cookie into the
         * Authorization header the guard reads. SecurityHeaders is prepended
         * so its headers are on every response, including the ones produced by
         * middleware that refuses a request before it reaches a controller.
         */
        /*
         * Behind a reverse proxy or a Cloudflare tunnel.
         *
         * Three things in this application read the request directly, and all
         * three are wrong without this:
         *
         *  - **The client IP on a health screening.** That IP is recorded as
         *    evidence of who signed and from where. Untrusted, every signature
         *    records the proxy's address instead of the member's, which is
         *    worse than recording nothing: it looks like real provenance.
         *  - **isSecure(), which decides the session cookie's Secure flag and
         *    its __Host- prefix.** A proxy terminates TLS and forwards plain
         *    HTTP, so the application believes the connection is insecure and
         *    quietly drops both - the exact protections SessionCookie exists
         *    to apply.
         *  - **Rate limiting.** The public health form is throttled per IP.
         *    With every request appearing to come from the proxy, the whole
         *    internet shares one bucket and the twentieth visitor of the
         *    minute is refused.
         *
         * Trusting 127.0.0.1 by default, not '*'. A Cloudflare tunnel runs its
         * agent on this machine and connects to the web server over loopback,
         * so that is the only hop to trust - and the server is *also* reachable
         * directly on the gym LAN, where a trusted '*' would let anyone forge
         * X-Forwarded-For and write a false IP onto a signed health form.
         *
         * Set TRUSTED_PROXIES for a different arrangement: a comma-separated
         * list, or '*' when the application can only ever be reached through
         * the proxy.
         */
        $middleware->trustProxies(at: array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
        ));

        $middleware->prependToGroup('api', SecurityHeaders::class);

        /*
         * Global, not in the `api` group — and that distinction is the whole
         * reason the cookie works.
         *
         * Laravel sorts route and group middleware by its priority list, in
         * which Authenticate ranks above SubstituteBindings. A middleware
         * added to the `api` group therefore ends up *after* `auth:sanctum`
         * whichever end of the group it is registered at: the guard ran first,
         * found no bearer token and returned 401 before the cookie had been
         * promoted into a header. It presented as the cookie silently doing
         * nothing, with the middleware never reached at all.
         *
         * Global middleware run before the router, so nothing can reorder them
         * ahead of the guard.
         */
        $middleware->prepend(AuthenticateFromSessionCookie::class);

        /*
         * Never redirect an unauthenticated caller to a login page.
         *
         * This is an API; there is no `login` route. Laravel's default sends
         * guests to route('login') unless the request asks for JSON, so a
         * request without an Accept: application/json header - a PDF opened
         * in a browser tab, say - raised "Route [login] not defined" and came
         * back as a 500 instead of a 401.
         *
         * Returning null here makes Authenticate throw the
         * AuthenticationException it should, which the handler below renders
         * as JSON. The redirect has to be disabled at the middleware: by the
         * time route('login') has thrown, the original exception is gone and
         * no handler can recover it.
         */
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * There is no `login` route in an API-only application, so Laravel's
         * default "redirect the guest to login" turned every unauthenticated
         * request into a 500 "Route [login] not defined" instead of a 401.
         * That only showed up for requests without an Accept: application/json
         * header - a PDF opened in a browser tab, for instance - which is
         * exactly the case nothing else covers.
         */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        // Permission failures come back as 403 with a message the interface
        // can show, rather than Spatie's default HTML page.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }

            return null;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        // SRS 13 - Pull attendance from every device on a short cycle. Overlap
        // protection matters: a slow or unreachable reader must not stack up
        // concurrent sync runs behind it.
        $interval = max(1, (int) config('gms.biometric.sync_interval_minutes', 1));

        $schedule->command(SyncBiometricAttendance::class)
            ->cron("*/{$interval} * * * *")
            ->withoutOverlapping(10)
            ->runInBackground()
            ->when(fn () => (bool) config('gms.biometric.sync_enabled', true));

        // SRS 6 - Expire lapsed memberships just after midnight.
        $schedule->command(ExpireMemberships::class)->dailyAt('00:15');

        // Then tell the readers. Ordered after the expiry sweep on purpose:
        // run the other way round and a membership that lapsed tonight would
        // keep opening the door for another day.
        $schedule->command(SyncDeviceAccess::class)
            ->dailyAt('00:30')
            ->withoutOverlapping(30)
            ->when(fn () => (bool) config('gms.biometric.sync_enabled', true));

        // SRS 11 - Close visits nobody scanned out of.
        $schedule->command(AutoCloseAttendance::class)
            ->dailyAt(sprintf('%02d:30', (int) config('gms.attendance.auto_close_hour', 23)));

        // SRS 21 - Queue expiry reminders, then drain the outbox.
        $schedule->command(DispatchNotifications::class, ['--queue-reminders'])->dailyAt('08:00');
        $schedule->command(DispatchNotifications::class)->everyFifteenMinutes()->withoutOverlapping();

        // SRS 30 - Daily backup, with a full weekly copy retained longer.
        $schedule->command(BackupDatabase::class, ['--type=DAILY'])->dailyAt('02:00');
        $schedule->command(BackupDatabase::class, ['--type=WEEKLY'])->weeklyOn(0, '03:00');
    })->create();
