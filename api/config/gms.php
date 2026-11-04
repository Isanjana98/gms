<?php

use App\Services\Biometric\Drivers\HikvisionDriver;

/**
 * Gym Management System settings.
 *
 * These are the deployment-level defaults. Anything reception or an
 * administrator is expected to change at runtime is overridden per branch in
 * the `settings` table - see App\Models\Setting and App\Support\GymSettings.
 */
return [

    'currency' => env('GYM_CURRENCY', 'LKR'),

    /*
    |---------------------------------------------------------------------
    | Document numbering
    |---------------------------------------------------------------------
    | Prefixes for the human-facing references printed on receipts and
    | membership cards. Changing a prefix does not renumber existing records.
    */
    'numbering' => [
        'member' => env('GYM_MEMBER_NO_PREFIX', 'GM'),
        'invoice' => env('GYM_INVOICE_PREFIX', 'INV'),
        'receipt' => env('GYM_RECEIPT_PREFIX', 'RCP'),
        'membership' => 'MS',
        'trainer' => 'TR',
        'expense' => env('GYM_EXPENSE_PREFIX', 'EXP'),
        'screening' => 'PARQ',
        'padding' => 5,
    ],

    /*
    |---------------------------------------------------------------------
    | Attendance (SRS 9-11)
    |---------------------------------------------------------------------
    */
    'attendance' => [
        // SRS 10: refuse entry when the membership is not valid. When false
        // the scan is still recorded, flagged membership_valid = false, so
        // reception can see who walked in on a lapsed membership.
        'reject_invalid_membership' => env('ATTENDANCE_REJECT_INVALID_MEMBERSHIP', true),

        // SRS 9.6: a scan from the same member within this window is the same
        // person swiping twice because the reader beeped oddly, not a real
        // second event. Also absorbs the overlap when a pull window is re-run.
        'duplicate_window_minutes' => (int) env('ATTENDANCE_DUPLICATE_WINDOW_MINUTES', 2),

        // In AUTO mode, a scan this soon after check-in is treated as another
        // check-in attempt rather than a check-out - nobody completes a
        // workout in four minutes, and closing the visit would lose the session.
        'min_session_minutes' => (int) env('ATTENDANCE_MIN_SESSION_MINUTES', 5),

        // Visits still OPEN at this hour are closed by the nightly job, so a
        // member who left without scanning out does not carry an open visit
        // into tomorrow and block their next check-in.
        'auto_close_hour' => (int) env('ATTENDANCE_AUTO_CLOSE_HOUR', 23),

        // Upper bound on a recorded session. A visit longer than this was a
        // missed check-out, not a 14-hour workout.
        'max_session_hours' => (int) env('ATTENDANCE_MAX_SESSION_HOURS', 6),
    ],

    /*
    |---------------------------------------------------------------------
    | Biometric integration (SRS 13, 14, 35)
    |---------------------------------------------------------------------
    */
    'biometric' => [
        'sync_enabled' => env('BIOMETRIC_SYNC_ENABLED', true),
        'sync_interval_minutes' => (int) env('BIOMETRIC_SYNC_INTERVAL_MINUTES', 1),
        'http_timeout' => (int) env('BIOMETRIC_HTTP_TIMEOUT', 15),

        // Shared secret the device's HTTP event notification must present.
        'push_token' => env('BIOMETRIC_PUSH_TOKEN'),

        // Each pull re-requests this much time before the high-water mark.
        // Readers buffer events and can report one a few seconds late; without
        // the overlap those events are never seen again. Re-reading them is
        // free because event_hash makes ingestion idempotent.
        'pull_overlap_minutes' => (int) env('BIOMETRIC_PULL_OVERLAP_MINUTES', 10),

        // How far back a first-ever sync reaches (SRS 14 recovery).
        'initial_lookback_days' => (int) env('BIOMETRIC_INITIAL_LOOKBACK_DAYS', 7),

        // Events per page when paging the device's event log.
        'page_size' => (int) env('BIOMETRIC_PAGE_SIZE', 100),

        // Stop after this many pages in one run, so a device returning a
        // pathological log cannot hold the sync worker forever.
        'max_pages' => (int) env('BIOMETRIC_MAX_PAGES', 50),

        // Registered drivers (SRS 35). Add a class here to support another vendor.
        'drivers' => [
            'hikvision' => HikvisionDriver::class,
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Memberships & notifications (SRS 6, 21)
    |---------------------------------------------------------------------
    */
    'membership' => [
        // Days before expiry on which a reminder is queued.
        'expiry_reminder_days' => array_map(
            'intval',
            array_filter(explode(',', (string) env('MEMBERSHIP_EXPIRY_REMINDER_DAYS', '7,3,1'))),
        ),

        // Renewing before the current term ends continues from the old expiry
        // date rather than today, so a member who renews early is not
        // penalised by losing the days they already paid for.
        'renew_from_expiry' => env('MEMBERSHIP_RENEW_FROM_EXPIRY', true),

        // How long after expiry an early renewal still chains onto the old
        // term. Past this, the new term simply starts today.
        'renew_chain_grace_days' => (int) env('MEMBERSHIP_RENEW_CHAIN_GRACE_DAYS', 30),

        // Days before expiry in which the "expiring soon" dashboard card and
        // the renewal prompt at reception light up.
        'expiring_soon_days' => (int) env('MEMBERSHIP_EXPIRING_SOON_DAYS', 30),
    ],

    /*
    |---------------------------------------------------------------------
    | Backups (SRS 30)
    |---------------------------------------------------------------------
    */
    'backup' => [
        'path' => env('BACKUP_PATH', 'backups'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
        'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
    ],

    /*
    |---------------------------------------------------------------------
    | Member app sign-in
    |---------------------------------------------------------------------
    |
    | Members sign in with a one-time code sent to the telephone number or
    | email already on their record. They have no password. See
    | App\Services\MemberAuthService and docs/MOBILE.md.
    |
    */
    'member_auth' => [
        // Long enough to find the phone, short enough that a code read over
        // somebody's shoulder is worthless by the time they act on it.
        'code_ttl_minutes' => (int) env('MEMBER_CODE_TTL_MINUTES', 10),

        // Guesses allowed before the code is destroyed. This, the expiry and
        // single use are the three things that make six digits safe.
        'max_attempts' => (int) env('MEMBER_CODE_MAX_ATTEMPTS', 5),

        /*
         * Return the code in the API response.
         *
         * For local development only: until an SMS provider is configured
         * there is no way to receive one, and the app could not be built or
         * demonstrated at all. Ignored outright when APP_ENV=production, so
         * setting it by mistake on a live server does nothing.
         */
        'expose_code' => env('MEMBER_CODE_EXPOSE', false),
    ],

    /*
    |---------------------------------------------------------------------
    | Session security (SRS 26)
    |---------------------------------------------------------------------
    |
    | The session token is delivered to the browser in an httpOnly cookie,
    | never in a JavaScript-readable store. See docs/SECURITY.md.
    |
    */
    'security' => [
        // Name of the cookie carrying the session token. Prefixed __Host- in
        // production by the auth controller when the connection is HTTPS:
        // that prefix is enforced by the browser and means the cookie cannot
        // be set by a subdomain, which is how a compromised blog.gym.lk would
        // otherwise fixate a session on gym.lk.
        'cookie' => env('SESSION_TOKEN_COOKIE', 'gms_session'),

        // Normally derived from whether the request arrived over HTTPS, which
        // is right behind a TLS-terminating proxy and right in local
        // development. Set only to override that judgement.
        'force_secure_cookie' => env('SESSION_FORCE_SECURE_COOKIE'),

        // How long a session lasts at most, however active it is. A stolen
        // token stops working on its own.
        'lifetime_days' => (int) env('SESSION_LIFETIME_DAYS', 30),

        /*
         * How long a session survives without being used.
         *
         * This is the one that matters at a gym: the front desk terminal is
         * in a public room and is walked away from. 0 disables it.
         */
        'idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT_MINUTES', 720),

        /*
         * Require a custom header on cookie-authenticated writes.
         *
         * Defence in depth behind SameSite. A cross-site form POST cannot set
         * a custom header, and a cross-site fetch that sets one triggers a
         * CORS preflight our origin list refuses — so a write can only come
         * from our own front end. Bearer-token callers are unaffected.
         */
        'require_request_header' => env('SESSION_REQUIRE_REQUEST_HEADER', true),

        // Sent on every response. Disable only if the app is deliberately
        // embedded in another site's frame.
        'headers' => [
            'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
            'frame_ancestors' => env('SECURITY_FRAME_ANCESTORS', "'none'"),
        ],
    ],
];
