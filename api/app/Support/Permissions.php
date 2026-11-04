<?php

namespace App\Support;

/**
 * SRS 3 / 26 - The permission catalogue and the roles built from it.
 *
 * Kept in code rather than only in the database so route middleware and the
 * seeder cannot drift apart: a typo in a permission name becomes a failing
 * test instead of a route nobody can reach.
 */
final class Permissions
{
    // Members
    public const MEMBERS_VIEW = 'members.view';

    public const MEMBERS_CREATE = 'members.create';

    public const MEMBERS_UPDATE = 'members.update';

    public const MEMBERS_DELETE = 'members.delete';

    /**
     * Member documents are separate from the member record.
     *
     * A signed agreement carries an address, an ID number and a signature.
     * Everyone who may look up a member's phone number should not thereby be
     * able to read and download that.
     */
    public const DOCUMENTS_VIEW = 'documents.view';

    public const DOCUMENTS_MANAGE = 'documents.manage';

    /**
     * Health screening (PAR-Q).
     *
     * Separate from documents.* on purpose. A screening holds health answers,
     * which is the most sensitive thing in this database: whoever may file an
     * ID copy should not automatically learn that a member has a heart
     * condition.
     *
     * ISSUE is the counter job — hand somebody a QR. REVIEW is the clinical
     * judgement that follows a "yes", and belongs to whoever the gym trusts to
     * make it.
     */
    public const HEALTH_VIEW = 'health.view';

    public const HEALTH_ISSUE = 'health.issue';

    public const HEALTH_REVIEW = 'health.review';

    // Memberships & plans
    public const PLANS_VIEW = 'plans.view';

    public const PLANS_MANAGE = 'plans.manage';

    public const MEMBERSHIPS_VIEW = 'memberships.view';

    public const MEMBERSHIPS_CREATE = 'memberships.create';

    public const MEMBERSHIPS_RENEW = 'memberships.renew';

    public const MEMBERSHIPS_CANCEL = 'memberships.cancel';

    // Money
    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_CREATE = 'payments.create';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const INVOICES_VIEW = 'invoices.view';

    /**
     * Money going out.
     *
     * Separate from the payment permissions rather than folded into them: the
     * person who takes a membership fee at the counter is not usually the
     * person who records what the gym paid for rent, and seeing the cost base
     * is seeing the gym's margin.
     */
    public const EXPENSES_VIEW = 'expenses.view';

    public const EXPENSES_MANAGE = 'expenses.manage';

    /**
     * The chart of accounts itself.
     *
     * Held apart from EXPENSES_MANAGE because renaming or retiring a category
     * changes how every past year's spending reads, while recording a cost
     * against an existing category does not.
     */
    public const EXPENSE_CATEGORIES_MANAGE = 'expense-categories.manage';

    // Attendance
    public const ATTENDANCE_VIEW = 'attendance.view';

    public const ATTENDANCE_MANAGE = 'attendance.manage';

    // Biometrics
    public const DEVICES_VIEW = 'devices.view';

    public const DEVICES_MANAGE = 'devices.manage';

    public const DEVICES_SYNC = 'devices.sync';

    public const ENROLLMENT_MANAGE = 'enrollment.manage';

    // People & operations
    public const TRAINERS_VIEW = 'trainers.view';

    public const TRAINERS_MANAGE = 'trainers.manage';

    public const WORKOUTS_VIEW = 'workouts.view';

    public const WORKOUTS_MANAGE = 'workouts.manage';

    /**
     * See every member's schedule, not only your own members'.
     *
     * Separate from WORKOUTS_VIEW because a trainer holds both view and
     * manage for their own members: without this, "can they manage?" was
     * standing in for "can they see everyone?", and every trainer could read
     * the whole gym's programmes.
     */
    public const WORKOUTS_VIEW_ALL = 'workouts.view-all';

    // Oversight
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_FINANCIAL = 'reports.financial';

    public const AUDIT_VIEW = 'audit.view';

    // Administration
    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const BRANCHES_VIEW_ALL = 'branches.view-all';

    public const SETTINGS_MANAGE = 'settings.manage';

    /**
     * The gym's name, logo and sign-in imagery.
     *
     * Separate from settings.manage: this changes what every member and
     * visitor sees, so a gym may well want it held by fewer people than the
     * ones who can adjust an attendance window.
     */
    public const BRANDING_MANAGE = 'branding.manage';

    /**
     * The public website: its copy, images, posts, testimonials and timetable.
     *
     * Separate from BRANDING_MANAGE and from SETTINGS_MANAGE because this is
     * the job that a gym is most likely to hand to somebody outside the
     * management team — whoever writes the marketing. Holding it should not
     * carry the ability to change an attendance rule or see a member's phone
     * number.
     */
    public const WEBSITE_MANAGE = 'website.manage';

    /**
     * Enquiries from the website.
     *
     * Reception holds both: following up an enquiry is the job, and a lead is
     * a stranger's contact details rather than member data.
     */
    public const LEADS_VIEW = 'leads.view';

    public const LEADS_MANAGE = 'leads.manage';

    public const BACKUPS_MANAGE = 'backups.manage';

    /**
     * Clearing operational data.
     *
     * Deliberately excluded from the Gym Administrator role below: it is the
     * one action with no undo beyond restoring a backup, and it should take a
     * decision at the level of whoever owns the system, not whoever runs the
     * gym day to day.
     */
    public const DATA_RESET = 'data.reset';

    public const ROLE_SUPER_ADMIN = 'Super Administrator';

    public const ROLE_GYM_ADMIN = 'Gym Administrator';

    public const ROLE_RECEPTIONIST = 'Receptionist';

    public const ROLE_TRAINER = 'Trainer';

    public const ROLE_ACCOUNTANT = 'Accountant';

    /** @return array<int, string> */
    public static function all(): array
    {
        $reflection = new \ReflectionClass(self::class);

        return array_values(array_filter(
            $reflection->getConstants(),
            fn (string $value, string $name) => ! str_starts_with($name, 'ROLE_'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * SRS 3 - Role definitions.
     *
     * Super Administrator is intentionally absent: it is granted everything
     * via a Gate::before rule, so adding a new permission never leaves the
     * top-level role unable to use the feature it is meant to administer.
     *
     * @return array<string, array<int, string>>
     */
    public static function roles(): array
    {
        $receptionist = [
            self::MEMBERS_VIEW, self::MEMBERS_CREATE, self::MEMBERS_UPDATE,
            // Reception is who collects the signed agreement at the counter.
            self::DOCUMENTS_VIEW, self::DOCUMENTS_MANAGE,
            // Reception hands over the QR and can see whether it came back
            // signed. Deciding what a "yes" means is not their call, so
            // HEALTH_REVIEW is deliberately absent.
            self::HEALTH_VIEW, self::HEALTH_ISSUE,
            self::PLANS_VIEW,
            self::MEMBERSHIPS_VIEW, self::MEMBERSHIPS_CREATE, self::MEMBERSHIPS_RENEW,
            self::PAYMENTS_VIEW, self::PAYMENTS_CREATE,
            self::INVOICES_VIEW,
            self::ATTENDANCE_VIEW, self::ATTENDANCE_MANAGE,
            self::ENROLLMENT_MANAGE,
            self::DEVICES_VIEW,
            self::TRAINERS_VIEW,
            // Reception books members in and is asked about their schedule at
            // the counter, so they can see and edit every member's.
            self::WORKOUTS_VIEW, self::WORKOUTS_MANAGE, self::WORKOUTS_VIEW_ALL,
            self::REPORTS_VIEW,
            // Website enquiries are reception's to chase, and converting one
            // into a member is exactly the job they already do.
            self::LEADS_VIEW, self::LEADS_MANAGE,
        ];

        return [
            // Everything operational, including the gym's own branding, but
            // not user/role administration or branch creation - those stay
            // with the super administrator.
            self::ROLE_GYM_ADMIN => array_values(array_diff(self::all(), [
                self::ROLES_MANAGE,
                self::BRANCHES_MANAGE,
                self::BRANCHES_VIEW_ALL,
                self::DATA_RESET,
            ])),

            self::ROLE_RECEPTIONIST => $receptionist,

            // Sees their own members and writes programmes; deliberately has
            // no access to money, and no WORKOUTS_VIEW_ALL - a trainer reads
            // their own members' schedules, not the whole gym's.
            self::ROLE_TRAINER => [
                self::MEMBERS_VIEW,
                self::ATTENDANCE_VIEW,
                self::WORKOUTS_VIEW, self::WORKOUTS_MANAGE,
                self::TRAINERS_VIEW,
                // A trainer is usually the person who actually has the
                // conversation a flagged PAR-Q calls for, and who writes the
                // programme around it, so the review is theirs to record.
                self::HEALTH_VIEW, self::HEALTH_REVIEW,
            ],

            // Full financial picture, read-only on everything else - an
            // accountant should not be able to edit the members whose
            // payments they reconcile.
            self::ROLE_ACCOUNTANT => [
                self::MEMBERS_VIEW,
                self::PLANS_VIEW,
                self::MEMBERSHIPS_VIEW,
                self::PAYMENTS_VIEW, self::PAYMENTS_CREATE, self::PAYMENTS_REFUND,
                self::INVOICES_VIEW,
                // Both sides of the books, including the chart of accounts:
                // reconciling against the gym's own ledger is the accountant's
                // job, and it is they who know what the categories should be.
                self::EXPENSES_VIEW, self::EXPENSES_MANAGE, self::EXPENSE_CATEGORIES_MANAGE,
                self::REPORTS_VIEW, self::REPORTS_FINANCIAL,
                self::AUDIT_VIEW,
            ],
        ];
    }
}
