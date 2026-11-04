<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\NumberGenerator;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'TEST',
            'name' => 'Test Branch',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * A user holding every permission, for tests that are not about access
     * control.
     *
     * Idempotent: a test that acts as the admin for several requests calls
     * this more than once, and it should hand back the same account rather
     * than collide on the unique email.
     */
    protected function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Test Admin', 'password' => 'password', 'is_active' => true],
        );

        if (! $user->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            $user->assignRole(Permissions::ROLE_SUPER_ADMIN);
        }

        return $user;
    }

    protected function userWithRole(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::firstOrCreate(
            ['email' => str($role)->slug().'@test.local'],
            [
                'branch_id' => $this->branch->id,
                'name' => "Test {$role}",
                'password' => 'password',
                'is_active' => true,
            ],
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user;
    }

    protected function plan(array $attributes = []): MembershipPlan
    {
        return MembershipPlan::create([
            'code' => 'PLAN-'.uniqid(),
            'name' => 'Monthly',
            'duration_value' => 1,
            'duration_unit' => 'MONTH',
            'price' => 5000,
            'default_discount' => 0,
            'registration_fee' => 0,
            'grace_days' => 0,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    protected function member(array $attributes = []): Member
    {
        return Member::create([
            'branch_id' => $this->branch->id,
            'membership_number' => app(NumberGenerator::class)->memberNumber(),
            'full_name' => 'Test Member',
            'join_date' => today(),
            ...$attributes,
        ]);
    }
}
