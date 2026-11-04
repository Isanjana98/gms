<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SRS 3 - Roles and permissions.
 *
 * Idempotent: safe to run on every deploy, which is how a new permission
 * reaches production without anyone hand-editing the database.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Holds no permissions of its own - Gate::before grants it everything
        // (see AppServiceProvider).
        Role::findOrCreate(Permissions::ROLE_SUPER_ADMIN, 'web');

        foreach (Permissions::roles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            // syncPermissions, not give: a permission removed from the
            // definition must actually be revoked, not linger from an
            // earlier deploy.
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
