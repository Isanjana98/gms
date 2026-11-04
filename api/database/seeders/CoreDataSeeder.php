<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\MembershipPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\SiteContent;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * The minimum a fresh installation needs to be usable: one branch, one
 * administrator, the packages from SRS 5 and the settings SRS 10 requires to
 * be configurable.
 *
 * Idempotent throughout, so it can be re-run after an upgrade.
 */
class CoreDataSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Branch',
                'city' => 'Colombo',
                'is_active' => true,
                'is_default' => true,
            ],
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@gms.local'],
            [
                'branch_id' => null, // Unscoped: sees every branch (SRS 22).
                'name' => 'System Administrator',
                'password' => 'ChangeMe123!',
                'is_active' => true,
            ],
        );

        if (! $admin->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            $admin->assignRole(Permissions::ROLE_SUPER_ADMIN);
        }

        // SRS 5 - the example packages, at the prices given.
        $plans = [
            ['code' => 'MONTHLY', 'name' => 'Monthly', 'duration_value' => 1, 'duration_unit' => 'MONTH', 'price' => 5000, 'sort_order' => 1],
            ['code' => 'QUARTERLY', 'name' => 'Quarterly', 'duration_value' => 3, 'duration_unit' => 'MONTH', 'price' => 13500, 'sort_order' => 2],
            ['code' => 'HALFYEAR', 'name' => 'Half Year', 'duration_value' => 6, 'duration_unit' => 'MONTH', 'price' => 25000, 'sort_order' => 3],
            ['code' => 'ANNUAL', 'name' => 'Annual', 'duration_value' => 12, 'duration_unit' => 'MONTH', 'price' => 45000, 'sort_order' => 4],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::firstOrCreate(
                ['code' => $plan['code']],
                [
                    ...$plan,
                    'branch_id' => null, // Available at every branch.
                    'registration_fee' => 1000,
                    'grace_days' => 0,
                    'is_active' => true,
                ],
            );
        }

        $this->seedExpenseCategories();
        $this->seedSettings();
        $this->seedWebsiteContent();

        $this->command?->info('Administrator: admin@gms.local / ChangeMe123!  (change this before going live)');
        $this->command?->info("Default branch: {$branch->name} ({$branch->code})");
    }

    /**
     * A starting chart of accounts.
     *
     * Seeded rather than left empty because an expense screen with no
     * categories cannot record anything, and the first thing the gym wants to
     * enter is usually the rent. These are ordinary rows: rename them, retire
     * them, add your own.
     */
    private function seedExpenseCategories(): void
    {
        $categories = [
            ['code' => 'RENT', 'name' => 'Rent', 'is_recurring' => true, 'sort_order' => 1],
            ['code' => 'SALARIES', 'name' => 'Salaries & wages', 'is_recurring' => true, 'sort_order' => 2],
            ['code' => 'UTILITIES', 'name' => 'Utilities', 'description' => 'Electricity, water, internet.', 'is_recurring' => true, 'sort_order' => 3],
            ['code' => 'EQUIPMENT', 'name' => 'Equipment', 'description' => 'Machines and weights, bought or leased.', 'sort_order' => 4],
            ['code' => 'MAINTENANCE', 'name' => 'Repairs & maintenance', 'sort_order' => 5],
            ['code' => 'CLEANING', 'name' => 'Cleaning & supplies', 'is_recurring' => true, 'sort_order' => 6],
            ['code' => 'MARKETING', 'name' => 'Marketing', 'sort_order' => 7],
            ['code' => 'INSURANCE', 'name' => 'Insurance & licences', 'is_recurring' => true, 'sort_order' => 8],
            ['code' => 'OTHER', 'name' => 'Other', 'sort_order' => 99],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::firstOrCreate(['code' => $category['code']], [
                'is_recurring' => false,
                'is_active' => true,
                ...$category,
            ]);
        }
    }

    /**
     * The public website's editable copy, seeded with its defaults.
     *
     * Seeded so the Website screen opens with fields to edit rather than a
     * blank page, and so a fresh installation has something coherent to look
     * at the moment it is switched on. `website.enabled` stays off: nobody
     * wants placeholder copy published under their gym's name.
     */
    private function seedWebsiteContent(): void
    {
        $descriptions = [
            'website.enabled' => 'Publish the public website. While this is off, visitors see a "not available" page.',
            'website.hero_headline' => 'The first line a visitor reads.',
            'website.hero_subtext' => 'One or two sentences under the headline.',
            'website.hero_cta_label' => 'Text on the main button.',
            'website.about_title' => 'Heading of the About page.',
            'website.about_body' => 'The About page text. Leave a blank line between paragraphs.',
            'website.facilities' => 'What the gym offers, as a list of title and description.',
            'website.opening_hours' => 'Shown in the footer and on the Contact page.',
            'website.social' => 'Links to your social media profiles.',
            'website.contact_note' => 'A line encouraging visitors to get in touch.',
            'website.seo_title' => 'Title shown in search results. Defaults to the gym name.',
            'website.seo_description' => 'The sentence search engines show under the title.',
            'website.map_embed' => 'Embed URL of a map showing the gym.',
            'website.show_pricing' => 'Show membership prices on the website.',
            'website.show_trainers' => 'Show the trainers page.',
            'website.show_gallery' => 'Show the photo gallery.',
            'website.show_blog' => 'Show the news section.',
            'website.show_timetable' => 'Show the class timetable.',
            'website.show_testimonials' => 'Show member testimonials.',
        ];

        foreach (SiteContent::DEFAULTS as $key => $value) {
            $type = match (true) {
                str_starts_with($value, '[') || str_starts_with($value, '{') => 'json',
                $value === '0' || $value === '1' => 'boolean',
                default => 'string',
            };

            Setting::firstOrCreate(
                ['branch_id' => null, 'key' => $key],
                [
                    'group' => 'website',
                    'value' => $value,
                    'type' => $type,
                    'label' => ucfirst(str_replace(['website.', '_'], ['', ' '], $key)),
                    'description' => $descriptions[$key] ?? null,
                ],
            );
        }

        Setting::flush();
    }

    /**
     * SRS 10 / 23 - The settings an administrator is expected to change,
     * seeded with their defaults and a description so the settings screen is
     * self-explaining.
     */
    private function seedSettings(): void
    {
        $settings = [
            [
                'group' => 'branding',
                'key' => 'branding.gym_name',
                'value' => 'Gym Manager',
                'type' => 'string',
                'label' => 'Gym name',
                'description' => 'Shown on the sign-in screen, in the sidebar and on printed receipts and invoices.',
            ],
            [
                'group' => 'branding',
                'key' => 'branding.tagline',
                'value' => 'Every member, every visit, in one place.',
                'type' => 'string',
                'label' => 'Tagline',
                'description' => 'A short line under the gym name on the sign-in screen.',
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.reject_invalid_membership',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Refuse entry on an invalid membership',
                'description' => 'When on, a scan from an expired or suspended membership is refused. When off, the visit is recorded but flagged for reception to follow up.',
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.duplicate_window_minutes',
                'value' => '2',
                'type' => 'integer',
                'label' => 'Duplicate scan window (minutes)',
                'description' => 'A repeated scan from the same member inside this window is ignored rather than recorded twice.',
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.min_session_minutes',
                'value' => '5',
                'type' => 'integer',
                'label' => 'Minimum session length (minutes)',
                'description' => 'In automatic mode, a scan sooner than this after check-in is treated as another check-in rather than a check-out.',
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.auto_close_hour',
                'value' => '23',
                'type' => 'integer',
                'label' => 'Auto-close hour',
                'description' => 'Hour of the day at which visits with no check-out are closed automatically.',
            ],
            [
                'group' => 'membership',
                'key' => 'membership.expiring_soon_days',
                'value' => '30',
                'type' => 'integer',
                'label' => 'Expiring soon window (days)',
                'description' => 'How far ahead the dashboard and renewal prompts look for memberships about to lapse.',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['branch_id' => null, 'key' => $setting['key']],
                $setting,
            );
        }

        Setting::flush();
    }
}
