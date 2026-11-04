<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seed: roles, permissions, the default branch, the
 * administrator account, the SRS 5 plans and the configurable settings.
 *
 * Sample members and attendance are deliberately not included - run
 * `php artisan db:seed --class=DemoDataSeeder` for those.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            CoreDataSeeder::class,
        ]);
    }
}
