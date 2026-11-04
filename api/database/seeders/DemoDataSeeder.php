<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\VerificationType;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Trainer;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\NumberGenerator;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Sample data for demonstrations and manual testing.
 *
 * Not run by DatabaseSeeder in production - invoke it explicitly with
 * `php artisan db:seed --class=DemoDataSeeder`.
 *
 * Deliberately builds members through the real services rather than inserting
 * rows: the demo data then exercises the same membership, invoice and payment
 * paths as production, so a broken rule shows up here first.
 */
class DemoDataSeeder extends Seeder
{
    public function run(
        NumberGenerator $numbers,
        MembershipService $memberships,
        PaymentService $payments,
    ): void {
        $branch = Branch::default() ?? Branch::firstOrFail();

        $staff = $this->seedStaff($branch);
        // Attribution needs an authenticated user; without it every demo
        // record is created by nobody and the audit log looks broken.
        Auth::login($staff['receptionist']);

        $trainers = $this->seedTrainers($branch);

        // Link the demo trainer login to an actual trainer record. Without it
        // the account has the Trainer role but no members of its own, and the
        // schedule screens correctly show it nothing - which looks like a bug
        // in a demo.
        $headTrainer = $trainers->first();

        if ($headTrainer && $headTrainer->user_id === null) {
            $headTrainer->forceFill(['user_id' => $staff['trainer']->id])->save();
        }
        $plans = MembershipPlan::query()->active()->get();

        if ($plans->isEmpty()) {
            $this->command?->error('Run CoreDataSeeder first: there are no membership plans.');

            return;
        }

        $names = [
            'Nimal Perera', 'Kamala Silva', 'Ruwan Fernando', 'Dilani Jayasuriya',
            'Asanka Bandara', 'Shanika Wickramasinghe', 'Tharindu Gunawardena',
            'Malini Rajapaksa', 'Chamara Dissanayake', 'Ishara Wijesinghe',
            'Pradeep Kumara', 'Sanduni Herath', 'Lakmal Ranatunga', 'Nadeeka Alwis',
            'Roshan Mendis', 'Hiruni Peris', 'Suresh Wijeratne', 'Amara Senanayake',
            'Kasun Liyanage', 'Dinesha Kodikara',
        ];

        $this->command?->info('Creating '.count($names).' demo members...');

        foreach ($names as $index => $name) {
            $joinedDaysAgo = random_int(10, 400);

            $member = $numbers->withRetry(fn () => Member::create([
                'branch_id' => $branch->id,
                'trainer_id' => $index % 3 === 0 ? $trainers->random()->id : null,
                'membership_number' => $numbers->memberNumber(),
                'full_name' => $name,
                'nic_passport' => sprintf('%09dV', random_int(600000000, 999999999)),
                'date_of_birth' => now()->subYears(random_int(19, 55))->subDays(random_int(0, 364))->toDateString(),
                'gender' => $index % 2 === 0 ? 'MALE' : 'FEMALE',
                'city' => 'Colombo',
                'telephone' => '07'.random_int(10000000, 99999999),
                'email' => str($name)->lower()->replace(' ', '.')->append('@example.lk')->toString(),
                'emergency_contact_name' => 'Emergency Contact',
                'emergency_contact_number' => '07'.random_int(10000000, 99999999),
                'join_date' => now()->subDays($joinedDaysAgo)->toDateString(),
                'created_by' => Auth::id(),
            ]));

            $plan = $plans->random();

            // A spread of states: most current, a few lapsed, a couple still
            // awaiting payment - so the dashboard and reports have something
            // real to show.
            $startedDaysAgo = min($joinedDaysAgo, random_int(1, 120));

            $membership = $memberships->create($member, $plan, [
                'start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            ]);

            if ($index % 9 !== 0) {
                $payments->record($member, [
                    'amount' => (float) $membership->final_amount,
                    'membership_id' => $membership->id,
                    'method' => ['CASH', 'CARD', 'BANK_TRANSFER'][$index % 3],
                    'paid_at' => now()->subDays($startedDaysAgo)->toDateTimeString(),
                ]);
            }

            $this->seedAttendance($member->refresh(), $branch->id, $startedDaysAgo);
        }

        $this->seedExpenses($branch, $numbers, $staff['accountant']);

        Auth::logout();

        $this->command?->info('Demo data created. Sign in as receptionist@gms.local / ChangeMe123!');
    }

    /** @return array<string, User> */
    private function seedStaff(Branch $branch): array
    {
        $definitions = [
            'manager' => ['Gym Manager', 'manager@gms.local', Permissions::ROLE_GYM_ADMIN],
            'receptionist' => ['Front Desk', 'receptionist@gms.local', Permissions::ROLE_RECEPTIONIST],
            'accountant' => ['Accounts', 'accountant@gms.local', Permissions::ROLE_ACCOUNTANT],
            'trainer' => ['Head Trainer', 'trainer@gms.local', Permissions::ROLE_TRAINER],
        ];

        $staff = [];

        foreach ($definitions as $key => [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'branch_id' => $branch->id,
                    'name' => $name,
                    'password' => 'ChangeMe123!',
                    'is_active' => true,
                ],
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            $staff[$key] = $user;
        }

        return $staff;
    }

    private function seedTrainers(Branch $branch)
    {
        $definitions = [
            ['Sunil Weerasinghe', 'Strength & Conditioning'],
            ['Priya Gunasekara', 'Yoga & Flexibility'],
            ['Dinesh Alwis', 'Cardio & Weight Loss'],
        ];

        foreach ($definitions as $index => [$name, $specialization]) {
            Trainer::firstOrCreate(
                ['trainer_code' => 'TR-'.now()->format('Y').'-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT)],
                [
                    'branch_id' => $branch->id,
                    'full_name' => $name,
                    'specialization' => $specialization,
                    'telephone' => '07'.random_int(10000000, 99999999),
                    'joining_date' => now()->subMonths(random_int(6, 36))->toDateString(),
                    'status' => 'ACTIVE',
                ],
            );
        }

        return Trainer::query()->where('branch_id', $branch->id)->get();
    }

    /**
     * Six months of running costs.
     *
     * The recurring lines are seeded at the same amount every month and the
     * one-offs are scattered, so the profit report shows the shape a real gym
     * has: a fixed floor of cost with occasional spikes on top. Seeding random
     * amounts everywhere would produce a chart that says nothing.
     */
    private function seedExpenses(Branch $branch, NumberGenerator $numbers, User $accountant): void
    {
        $categories = ExpenseCategory::query()->get()->keyBy('code');

        if ($categories->isEmpty()) {
            $this->command?->warn('No expense categories: run CoreDataSeeder first. Skipping demo expenses.');

            return;
        }

        $monthly = [
            'RENT' => [150000, 'Property owner'],
            'SALARIES' => [420000, 'Staff payroll'],
            'UTILITIES' => [38000, 'CEB & water board'],
            'CLEANING' => [12000, 'Supplies'],
            'INSURANCE' => [18000, 'Insurance provider'],
        ];

        $occasional = [
            'EQUIPMENT' => [25000, 180000, 'Fitness equipment supplier'],
            'MAINTENANCE' => [4000, 35000, 'Service contractor'],
            'MARKETING' => [8000, 60000, 'Advertising'],
            'OTHER' => [2000, 15000, null],
        ];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->subMonths($monthsAgo);

            foreach ($monthly as $code => [$amount, $payee]) {
                if (! $categories->has($code)) {
                    continue;
                }

                // The 1st for rent, a few days in for everything else — and
                // never in the future, or the current month would carry costs
                // that have not happened yet.
                $date = $month->copy()->startOfMonth()->addDays($code === 'RENT' ? 0 : random_int(2, 6));

                if ($date->isFuture()) {
                    continue;
                }

                $this->recordExpense($branch, $numbers, $accountant, $categories[$code], $date, $amount, $payee);
            }

            foreach ($occasional as $code => [$min, $max, $payee]) {
                if (! $categories->has($code) || random_int(1, 10) > 5) {
                    continue;
                }

                $date = $month->copy()->startOfMonth()->addDays(random_int(0, 27));

                if ($date->isFuture()) {
                    continue;
                }

                $this->recordExpense($branch, $numbers, $accountant, $categories[$code], $date, random_int($min, $max), $payee);
            }
        }
    }

    private function recordExpense(
        Branch $branch,
        NumberGenerator $numbers,
        User $accountant,
        ExpenseCategory $category,
        Carbon $date,
        float $amount,
        ?string $payee,
    ): void {
        $numbers->withRetry(fn () => Expense::create([
            'branch_id' => $branch->id,
            'expense_category_id' => $category->id,
            'reference' => $numbers->expenseReference(),
            'expense_date' => $date->toDateString(),
            'amount' => $amount,
            'method' => $amount > 50000 ? 'BANK_TRANSFER' : 'CASH',
            'payee' => $payee,
            'description' => $category->name.' for '.$date->format('F Y'),
            'recorded_by' => $accountant->id,
        ]));
    }

    /**
     * Plausible visit history: members come in on some days and not others,
     * at roughly gym-like hours, with a realistic session length.
     */
    private function seedAttendance(Member $member, int $branchId, int $sinceDays): void
    {
        $days = min($sinceDays, 60);
        $visits = 0;
        $lastVisit = null;

        for ($dayOffset = $days; $dayOffset >= 1; $dayOffset--) {
            // Roughly three visits a week.
            if (random_int(1, 10) > 4) {
                continue;
            }

            $checkIn = now()->subDays($dayOffset)
                ->setTime(random_int(6, 20), random_int(0, 59));

            $checkOut = $checkIn->copy()->addMinutes(random_int(35, 110));

            Attendance::create([
                'branch_id' => $branchId,
                'member_id' => $member->id,
                'membership_id' => $member->current_membership_id,
                'attendance_date' => $checkIn->toDateString(),
                'check_in_time' => $checkIn,
                'check_out_time' => $checkOut,
                'duration_minutes' => (int) $checkIn->diffInMinutes($checkOut),
                'attendance_type' => AttendanceType::BIOMETRIC,
                'verification_type' => VerificationType::FINGERPRINT,
                'status' => AttendanceStatus::COMPLETED,
                'membership_valid' => true,
            ]);

            $visits++;
            $lastVisit = $checkIn;
        }

        if ($visits > 0) {
            $member->forceFill([
                'total_visits' => $visits,
                'last_visit_at' => $lastVisit,
            ])->save();
        }
    }
}
