<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BiometricDeviceController;
use App\Http\Controllers\Api\BiometricPushController;
use App\Http\Controllers\Api\BiometricSyncController;
use App\Http\Controllers\Api\BiometricUserController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataResetController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\HealthScreeningController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\Member\MemberAuthController;
use App\Http\Controllers\Api\Member\MemberPortalController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberDocumentController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MembershipPlanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteController;
use App\Http\Controllers\Api\WorkoutPlanController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gym Management System API (SRS 29)
|--------------------------------------------------------------------------
|
| Permission middleware is applied at the route level as well as inside the
| controllers. Both, deliberately: the route is the readable contract, and
| the controller check is what still holds if a route is ever regrouped.
|
*/

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

/*
 * The gym's name, logo and sign-in imagery.
 *
 * Public because the sign-in screen has to render before anyone has a token.
 * It returns only what is already on display there - a name, a tagline and
 * some image URLs - and nothing that is not.
 */
Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');

/*
 * SRS 13 - Device event push.
 *
 * Unauthenticated by Sanctum because a fingerprint reader cannot hold a
 * bearer token; it presents a shared secret instead, checked in constant time
 * inside the controller. Throttled generously: a busy door at peak time is
 * genuinely a few events a second, but an open endpoint should not be able to
 * flood the ingest path without limit.
 */
Route::post('biometric/push/{deviceCode}', BiometricPushController::class)
    ->middleware('throttle:120,1')
    ->name('biometric.push');

// ---------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------

/*
 * The member app.
 *
 * Its own branch of the API, and deliberately not nested inside the staff
 * group below. Every endpoint returns only the caller's own data and takes no
 * member id from the request, so there is no id to check and no way to ask
 * about anybody else.
 */
Route::prefix('member')->name('member.')->group(function () {
    // Public: this is how you sign in. Rate limited inside the controller by
    // identifier as well as address, because each call sends an SMS.
    Route::post('auth/request-code', [MemberAuthController::class, 'requestCode'])
        ->middleware('throttle:20,1')->name('auth.request-code');

    Route::post('auth/verify', [MemberAuthController::class, 'verify'])
        ->middleware('throttle:30,1')->name('auth.verify');

    Route::middleware(['auth:sanctum', 'member.only'])->group(function () {
        Route::get('me', [MemberAuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [MemberAuthController::class, 'logout'])->name('auth.logout');

        Route::get('attendance', [MemberPortalController::class, 'attendance'])->name('attendance');
        Route::get('payments', [MemberPortalController::class, 'payments'])->name('payments');
        Route::get('receipts/{payment}', [MemberPortalController::class, 'receipt'])
            ->whereNumber('payment')->name('receipts');
        Route::get('timetable', [MemberPortalController::class, 'timetable'])->name('timetable');
        Route::get('gym', [MemberPortalController::class, 'gym'])->name('gym');
    });
});

/*
 * Everything below is the staff API.
 *
 * `staff.only` is what stops a member's token reaching any of it. Most routes
 * would refuse a member anyway for want of a permission, but `auth/me` and the
 * session endpoints have no permission gate at all — and "most" is not a
 * security boundary.
 */
Route::middleware(['auth:sanctum', 'staff.only', 'active.user'])->group(function () {

    // --- Session -----------------------------------------------------
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

    /*
     * Where this account is signed in, and how to end those sessions.
     *
     * No permission gate: these are the caller's own sessions. Someone who
     * suspects their login has been used should not have to raise a ticket to
     * find out, and nothing here exposes another account.
     */
    Route::get('auth/sessions', [AuthController::class, 'sessions'])->name('auth.sessions');
    Route::post('auth/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions'])->name('auth.sessions.revoke-others');
    Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession'])
        ->whereNumber('id')->name('auth.sessions.revoke');

    // --- Dashboard (SRS 15) ------------------------------------------
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

    // --- Members (SRS 4) ---------------------------------------------
    Route::middleware('permission:'.Permissions::MEMBERS_VIEW)->group(function () {
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::get('members/duplicate-check', [MemberController::class, 'duplicates'])->name('members.duplicates');
        Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
        Route::get('members/{member}/payments', [PaymentController::class, 'forMember'])->name('members.payments');
    });

    // Member documents: agreements, ID copies, medical clearances.
    // Files are streamed through the API, never served from a public path.
    Route::middleware('permission:'.Permissions::DOCUMENTS_VIEW)->group(function () {
        Route::get('members/{member}/documents', [MemberDocumentController::class, 'index'])->name('documents.index');
        Route::get('members/{member}/documents/{document}', [MemberDocumentController::class, 'show'])->name('documents.show');
    });

    Route::middleware('permission:'.Permissions::DOCUMENTS_MANAGE)->group(function () {
        Route::post('members/{member}/documents', [MemberDocumentController::class, 'store'])->name('documents.store');
        Route::match(['put', 'patch'], 'members/{member}/documents/{document}', [MemberDocumentController::class, 'update'])->name('documents.update');
        Route::delete('members/{member}/documents/{document}', [MemberDocumentController::class, 'destroy'])->name('documents.destroy');
    });

    /*
     * Health screening (PAR-Q).
     *
     * Separate from documents.* because a screening holds health answers,
     * which is the most sensitive thing in this database: whoever may file an
     * ID copy should not automatically learn that a member has a heart
     * condition. Each route carries its own permission rather than inheriting
     * a group's, so issuing, reading and reviewing can be held by different
     * people - which is the point of the split.
     */
    Route::get('health-screenings', [HealthScreeningController::class, 'index'])
        ->middleware('permission:'.Permissions::HEALTH_VIEW)->name('health.index');
    Route::get('health-screenings/{screening}', [HealthScreeningController::class, 'show'])
        ->middleware('permission:'.Permissions::HEALTH_VIEW)->name('health.show');
    Route::get('health-screenings/{screening}/pdf', [HealthScreeningController::class, 'pdf'])
        ->middleware('permission:'.Permissions::HEALTH_VIEW)->name('health.pdf');
    Route::get('members/{member}/health-screenings', [HealthScreeningController::class, 'forMember'])
        ->middleware('permission:'.Permissions::HEALTH_VIEW)->name('health.member');

    Route::post('members/{member}/health-screenings', [HealthScreeningController::class, 'issue'])
        ->middleware('permission:'.Permissions::HEALTH_ISSUE)->name('health.issue');

    Route::post('health-screenings/{screening}/review', [HealthScreeningController::class, 'review'])
        ->middleware('permission:'.Permissions::HEALTH_REVIEW)->name('health.review');

    Route::post('members', [MemberController::class, 'store'])
        ->middleware('permission:'.Permissions::MEMBERS_CREATE)->name('members.store');

    // Photo upload is its own endpoint so the rest of the member form stays
    // JSON. Mixing a file into it would force every field through multipart,
    // where "null" arrives as the string "null".
    Route::post('members/{member}/photo', [MemberController::class, 'uploadPhoto'])
        ->middleware('permission:'.Permissions::MEMBERS_UPDATE)->name('members.photo');

    Route::delete('members/{member}/photo', [MemberController::class, 'deletePhoto'])
        ->middleware('permission:'.Permissions::MEMBERS_UPDATE)->name('members.photo.delete');
    Route::match(['put', 'patch'], 'members/{member}', [MemberController::class, 'update'])
        ->middleware('permission:'.Permissions::MEMBERS_UPDATE)->name('members.update');
    Route::delete('members/{member}', [MemberController::class, 'destroy'])
        ->middleware('permission:'.Permissions::MEMBERS_DELETE)->name('members.destroy');

    // --- Membership plans (SRS 5) ------------------------------------
    Route::get('membership-plans', [MembershipPlanController::class, 'index'])
        ->middleware('permission:'.Permissions::PLANS_VIEW)->name('plans.index');
    Route::get('membership-plans/{membershipPlan}', [MembershipPlanController::class, 'show'])
        ->middleware('permission:'.Permissions::PLANS_VIEW)->name('plans.show');

    Route::middleware('permission:'.Permissions::PLANS_MANAGE)->group(function () {
        Route::post('membership-plans', [MembershipPlanController::class, 'store'])->name('plans.store');
        Route::match(['put', 'patch'], 'membership-plans/{membershipPlan}', [MembershipPlanController::class, 'update'])->name('plans.update');
        Route::delete('membership-plans/{membershipPlan}', [MembershipPlanController::class, 'destroy'])->name('plans.destroy');
    });

    // --- Memberships & renewals (SRS 5, 6) ---------------------------
    Route::middleware('permission:'.Permissions::MEMBERSHIPS_VIEW)->group(function () {
        Route::get('memberships', [MembershipController::class, 'index'])->name('memberships.index');
        Route::get('memberships/{membership}', [MembershipController::class, 'show'])->name('memberships.show');
        Route::get('memberships/{membership}/renewal-preview', [MembershipController::class, 'renewalPreview'])->name('memberships.renewal-preview');
    });

    Route::post('memberships', [MembershipController::class, 'store'])
        ->middleware('permission:'.Permissions::MEMBERSHIPS_CREATE)->name('memberships.store');
    Route::post('memberships/{membership}/renew', [MembershipController::class, 'renew'])
        ->middleware('permission:'.Permissions::MEMBERSHIPS_RENEW)->name('memberships.renew');
    Route::post('memberships/{membership}/activate', [MembershipController::class, 'activate'])
        ->middleware('permission:'.Permissions::MEMBERSHIPS_CREATE)->name('memberships.activate');
    Route::post('memberships/{membership}/suspend', [MembershipController::class, 'suspend'])
        ->middleware('permission:'.Permissions::MEMBERSHIPS_CANCEL)->name('memberships.suspend');
    Route::delete('memberships/{membership}', [MembershipController::class, 'cancel'])
        ->middleware('permission:'.Permissions::MEMBERSHIPS_CANCEL)->name('memberships.cancel');

    // --- Payments, invoices, receipts (SRS 17, 18) -------------------
    Route::middleware('permission:'.Permissions::PAYMENTS_VIEW)->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::get('payments/{payment}/receipt', [InvoiceController::class, 'receipt'])->name('payments.receipt');
    });

    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('permission:'.Permissions::PAYMENTS_CREATE)->name('payments.store');
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])
        ->middleware('permission:'.Permissions::PAYMENTS_REFUND)->name('payments.refund');

    Route::middleware('permission:'.Permissions::INVOICES_VIEW)->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });

    // --- Attendance (SRS 9-12) ---------------------------------------
    Route::middleware('permission:'.Permissions::ATTENDANCE_VIEW)->group(function () {
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('attendance/live', [AttendanceController::class, 'live'])->name('attendance.live');
        Route::get('attendance/validate/{member}', [AttendanceController::class, 'validateEntry'])->name('attendance.validate');
        Route::get('attendance/member/{member}', [AttendanceController::class, 'forMember'])->name('attendance.member');
    });

    Route::middleware('permission:'.Permissions::ATTENDANCE_MANAGE)->group(function () {
        Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::match(['put', 'patch'], 'attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');
        Route::delete('attendance/{attendance}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    });

    // --- Biometric devices & enrolment (SRS 8, 23, 24) ---------------
    Route::prefix('biometric')->name('biometric.')->group(function () {
        Route::middleware('permission:'.Permissions::DEVICES_VIEW)->group(function () {
            Route::get('devices', [BiometricDeviceController::class, 'index'])->name('devices.index');
            Route::get('devices/{device}', [BiometricDeviceController::class, 'show'])->name('devices.show');
            Route::post('devices/{device}/test', [BiometricDeviceController::class, 'test'])->name('devices.test');
            Route::get('drivers', [BiometricDeviceController::class, 'drivers'])->name('drivers');
            Route::get('users', [BiometricUserController::class, 'index'])->name('users.index');
            Route::get('sync-logs', [BiometricSyncController::class, 'logs'])->name('sync-logs');
            Route::get('sync-status', [BiometricSyncController::class, 'status'])->name('sync-status');
        });

        Route::middleware('permission:'.Permissions::DEVICES_MANAGE)->group(function () {
            Route::post('devices', [BiometricDeviceController::class, 'store'])->name('devices.store');
            Route::match(['put', 'patch'], 'devices/{device}', [BiometricDeviceController::class, 'update'])->name('devices.update');
            Route::delete('devices/{device}', [BiometricDeviceController::class, 'destroy'])->name('devices.destroy');
        });

        // Throttled hard on purpose. Releasing a lock is a physical act that
        // holds the door open for a few seconds, and nothing legitimate needs
        // it more than a handful of times a minute.
        Route::middleware(['permission:'.Permissions::DEVICES_OPEN_DOOR, 'throttle:10,1'])->group(function () {
            Route::post('devices/{device}/open-door', [BiometricDeviceController::class, 'openDoor'])
                ->name('devices.open-door');
        });

        Route::middleware('permission:'.Permissions::DEVICES_SYNC)->group(function () {
            Route::post('sync', [BiometricSyncController::class, 'attendance'])->name('sync');
            Route::post('users/sync', [BiometricSyncController::class, 'users'])->name('users.sync');
        });

        Route::middleware('permission:'.Permissions::ENROLLMENT_MANAGE)->group(function () {
            Route::post('users', [BiometricUserController::class, 'store'])->name('users.store');
            Route::post('users/{biometricUser}/verify', [BiometricUserController::class, 'verify'])->name('users.verify');
            Route::post('users/{biometricUser}/match', [BiometricUserController::class, 'match'])->name('users.match');
            Route::post('users/{biometricUser}/change-id', [BiometricUserController::class, 'changeId'])->name('users.change-id');
            Route::delete('users/{biometricUser}', [BiometricUserController::class, 'destroy'])->name('users.destroy');
        });
    });

    // --- Trainers (SRS 19) -------------------------------------------
    Route::middleware('permission:'.Permissions::TRAINERS_VIEW)->group(function () {
        Route::get('trainers', [TrainerController::class, 'index'])->name('trainers.index');
        Route::get('trainers/{trainer}', [TrainerController::class, 'show'])->name('trainers.show');
        Route::get('trainers/{trainer}/members', [TrainerController::class, 'members'])->name('trainers.members');
    });

    Route::middleware('permission:'.Permissions::TRAINERS_MANAGE)->group(function () {
        Route::post('trainers', [TrainerController::class, 'store'])->name('trainers.store');
        Route::match(['put', 'patch'], 'trainers/{trainer}', [TrainerController::class, 'update'])->name('trainers.update');
        Route::delete('trainers/{trainer}', [TrainerController::class, 'destroy'])->name('trainers.destroy');
    });

    // --- Workout plans & gym schedule (SRS 20) -----------------------
    Route::get('members/{member}/schedule', [WorkoutPlanController::class, 'schedule'])
        ->middleware('permission:'.Permissions::WORKOUTS_VIEW)
        ->name('members.schedule');

    Route::apiResource('workout-plans', WorkoutPlanController::class)
        ->middleware('permission:'.Permissions::WORKOUTS_VIEW.'|'.Permissions::WORKOUTS_MANAGE);

    // --- Expenses -----------------------------------------------------
    // The other half of the books. Kept apart from payments: a payment belongs
    // to a member and settles an invoice, an expense belongs to a supplier and
    // settles nothing, and seeing the cost base is seeing the gym's margin.
    Route::middleware('permission:'.Permissions::EXPENSES_VIEW)->group(function () {
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
    });

    Route::middleware('permission:'.Permissions::EXPENSES_MANAGE)->group(function () {
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::match(['put', 'patch'], 'expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    });

    Route::middleware('permission:'.Permissions::EXPENSE_CATEGORIES_MANAGE)->group(function () {
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::match(['put', 'patch'], 'expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
    });

    // --- Reports (SRS 16, 17) ----------------------------------------
    Route::prefix('reports')->name('reports.')->middleware('permission:'.Permissions::REPORTS_VIEW)->group(function () {
        Route::get('attendance/daily', [ReportController::class, 'dailyAttendance'])->name('attendance.daily');
        Route::get('attendance/monthly', [ReportController::class, 'monthlyAttendance'])->name('attendance.monthly');
        Route::get('attendance/members', [ReportController::class, 'memberVisits'])->name('attendance.members');
        Route::get('attendance/member/{member}', [ReportController::class, 'memberAttendance'])->name('attendance.member');
        Route::get('attendance/export', [ReportController::class, 'exportAttendance'])->name('attendance.export');
        Route::get('revenue', [ReportController::class, 'revenue'])->name('revenue');
        Route::get('outstanding', [ReportController::class, 'outstanding'])->name('outstanding');
        Route::get('profit-loss', [ReportController::class, 'profitAndLoss'])->name('profit-loss');
    });

    /*
     * The expense report and the exports sit outside the reports.view group on
     * purpose.
     *
     * Each carries its own permission — the expense report needs expenses.view,
     * and every export names the permission required to download it in its
     * definition. Nesting them under reports.view as well would mean a role
     * that may see the cost base but not the attendance figures could not
     * export its own report, and would make it look as though reports.view were
     * sufficient to download any of them.
     */
    Route::get('reports/expenses', [ReportController::class, 'expenses'])
        ->middleware('permission:'.Permissions::EXPENSES_VIEW)->name('reports.expenses');

    Route::get('reports/exports', [ReportController::class, 'exports'])->name('reports.exports');
    Route::get('reports/export/{report}', [ReportController::class, 'export'])->name('reports.export');

    // --- Notifications (SRS 21) --------------------------------------
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // --- Branches (SRS 22) -------------------------------------------
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');

    Route::middleware('permission:'.Permissions::BRANCHES_MANAGE)->group(function () {
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });

    // --- Users & roles (SRS 3) ---------------------------------------
    Route::middleware('permission:'.Permissions::USERS_MANAGE)->group(function () {
        Route::get('roles', [UserController::class, 'roles'])->name('roles.index');
        Route::apiResource('users', UserController::class);
    });

    // --- Audit trail (SRS 25) ----------------------------------------
    Route::middleware('permission:'.Permissions::AUDIT_VIEW)->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('audit-logs/events', [AuditLogController::class, 'events'])->name('audit.events');
        Route::get('audit-logs/record/{type}/{id}', [AuditLogController::class, 'forRecord'])->name('audit.record');
    });

    // --- Settings (SRS 10, 23) ---------------------------------------
    Route::middleware('permission:'.Permissions::SETTINGS_MANAGE)->group(function () {
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::match(['put', 'patch'], 'settings', [SettingController::class, 'update'])->name('settings.update');
    });

    // --- Branding: gym name, logo, sign-in imagery -------------------
    Route::match(['put', 'patch'], 'branding', [BrandingController::class, 'update'])
        ->middleware('permission:'.Permissions::BRANDING_MANAGE)->name('branding.update');

    /*
     * Image uploads serve both the sign-in screen and the website, so either
     * permission opens the door — and the controller then decides which image
     * *types* the caller may actually touch. Gating the whole endpoint on
     * branding.manage would have forced anyone editing the website's gallery
     * to also hold the ability to replace the gym's logo.
     */
    Route::middleware('permission:'.Permissions::BRANDING_MANAGE.'|'.Permissions::WEBSITE_MANAGE)->group(function () {
        Route::get('branding/assets', [BrandingController::class, 'index'])->name('branding.assets');
        Route::post('branding/assets', [BrandingController::class, 'store'])->name('branding.assets.store');
        Route::match(['put', 'patch'], 'branding/assets/{asset}', [BrandingController::class, 'updateAsset'])->name('branding.assets.update');
        Route::delete('branding/assets/{asset}', [BrandingController::class, 'destroy'])->name('branding.assets.destroy');
    });

    // --- Website content ----------------------------------------------
    // Held apart from branding and settings: writing the marketing copy is the
    // job a gym is most likely to hand to somebody outside the management
    // team, and doing it should not carry the ability to change an attendance
    // rule or read a member's telephone number.
    Route::middleware('permission:'.Permissions::WEBSITE_MANAGE)->group(function () {
        Route::get('website', [WebsiteController::class, 'show'])->name('website.show');
        Route::match(['put', 'patch'], 'website', [WebsiteController::class, 'update'])->name('website.update');

        Route::get('website/posts', [WebsiteController::class, 'posts'])->name('website.posts');
        Route::post('website/posts', [WebsiteController::class, 'storePost'])->name('website.posts.store');
        Route::match(['put', 'patch'], 'website/posts/{post}', [WebsiteController::class, 'updatePost'])->name('website.posts.update');
        Route::delete('website/posts/{post}', [WebsiteController::class, 'destroyPost'])->name('website.posts.destroy');

        Route::get('website/testimonials', [WebsiteController::class, 'testimonials'])->name('website.testimonials');
        Route::post('website/testimonials', [WebsiteController::class, 'storeTestimonial'])->name('website.testimonials.store');
        Route::match(['put', 'patch'], 'website/testimonials/{testimonial}', [WebsiteController::class, 'updateTestimonial'])->name('website.testimonials.update');
        Route::delete('website/testimonials/{testimonial}', [WebsiteController::class, 'destroyTestimonial'])->name('website.testimonials.destroy');

        Route::get('website/timetable', [WebsiteController::class, 'timetable'])->name('website.timetable');
        Route::post('website/timetable', [WebsiteController::class, 'storeTimetableEntry'])->name('website.timetable.store');
        Route::match(['put', 'patch'], 'website/timetable/{entry}', [WebsiteController::class, 'updateTimetableEntry'])->name('website.timetable.update');
        Route::delete('website/timetable/{entry}', [WebsiteController::class, 'destroyTimetableEntry'])->name('website.timetable.destroy');
    });

    // --- Website enquiries ----------------------------------------------
    Route::get('leads', [LeadController::class, 'index'])
        ->middleware('permission:'.Permissions::LEADS_VIEW)->name('leads.index');

    Route::middleware('permission:'.Permissions::LEADS_MANAGE)->group(function () {
        Route::match(['put', 'patch'], 'leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
    });

    // --- Data reset ---------------------------------------------------
    // The only action with no undo beyond restoring a backup. Held by the
    // Super Administrator alone, and the controller re-checks the caller's
    // password before it deletes anything.
    Route::middleware('permission:'.Permissions::DATA_RESET)->group(function () {
        Route::get('data-reset', [DataResetController::class, 'preview'])->name('data-reset.preview');
        Route::post('data-reset', [DataResetController::class, 'store'])
            ->middleware('throttle:5,60')
            ->name('data-reset.store');
    });

    // --- Backups (SRS 30) --------------------------------------------
    Route::middleware('permission:'.Permissions::BACKUPS_MANAGE)->group(function () {
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backupLog}/download', [BackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{backupLog}', [BackupController::class, 'destroy'])->name('backups.destroy');
    });
});
