<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Services\Attendance\AttendanceService;
use App\Services\MembershipService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 5 / 6 - Membership dates, renewal chaining and expiry. */
class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_one_month_term_ends_the_day_before_the_same_date_next_month(): void
    {
        // The member pays for a month and gets a month - not a month plus the
        // free day that an inclusive end date would hand them on every renewal.
        $plan = $this->plan(['duration_value' => 1, 'duration_unit' => 'MONTH']);

        $end = $plan->calculateEndDate(now()->parse('2026-01-01'));

        $this->assertSame('2026-01-31', $end->toDateString());
    }

    #[Test]
    public function month_arithmetic_does_not_overflow_into_the_next_month(): void
    {
        // 31 Jan + 1 month must land in February, not spill into 3 March.
        $plan = $this->plan(['duration_value' => 1, 'duration_unit' => 'MONTH']);

        $end = $plan->calculateEndDate(now()->parse('2026-01-31'));

        $this->assertSame('2026-02-27', $end->toDateString());
    }

    #[Test]
    public function a_new_membership_starts_pending_and_unpaid(): void
    {
        $member = $this->member();

        $membership = app(MembershipService::class)->create($member, $this->plan());

        $this->assertSame(MembershipStatus::PENDING, $membership->status);
        $this->assertSame(PaymentStatus::UNPAID, $membership->payment_status);
        // SRS 18 - an invoice is raised with the sale.
        $this->assertCount(1, $membership->invoices);
    }

    #[Test]
    public function paying_in_full_activates_the_membership_and_the_member(): void
    {
        // SRS 32 steps 3 and 6: payment is what turns a sale into access.
        $member = $this->member();
        $memberships = app(MembershipService::class);
        $membership = $memberships->create($member, $this->plan(['price' => 5000]));

        app(PaymentService::class)->record($member, [
            'amount' => 5000,
            'membership_id' => $membership->id,
            'method' => 'CASH',
        ]);

        $membership->refresh();
        $member->refresh();

        $this->assertSame(MembershipStatus::ACTIVE, $membership->status);
        $this->assertSame(PaymentStatus::PAID, $membership->payment_status);
        $this->assertSame(MemberStatus::ACTIVE, $member->status);
        $this->assertSame($membership->id, $member->current_membership_id);
    }

    #[Test]
    public function a_partial_payment_leaves_the_membership_pending(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));

        app(PaymentService::class)->record($member, [
            'amount' => 2000,
            'membership_id' => $membership->id,
            'method' => 'CASH',
        ]);

        $membership->refresh();

        $this->assertSame(PaymentStatus::PARTIAL, $membership->payment_status);
        $this->assertSame(MembershipStatus::PENDING, $membership->status);
        $this->assertSame(3000.0, $membership->balance);
    }

    #[Test]
    public function renewing_early_continues_from_the_existing_expiry_date(): void
    {
        // A member who renews a week early must not lose the week they have
        // already paid for.
        $member = $this->member();
        $memberships = app(MembershipService::class);

        $first = $memberships->create($member, $this->plan(), [
            'start_date' => today()->subDays(23)->toDateString(),
        ]);

        $renewal = $memberships->renew($first);

        $this->assertSame(
            $first->end_date->copy()->addDay()->toDateString(),
            $renewal->start_date->toDateString(),
        );
        $this->assertSame($first->id, $renewal->renewed_from_id);
    }

    #[Test]
    public function renewing_early_does_not_lock_the_member_out_of_their_paid_term(): void
    {
        // The renewal is dated in the future and unpaid. If it were treated as
        // the member's current membership, the door would refuse them for
        // "awaiting payment" while they still hold a paid, running term - a
        // member would be punished for renewing early.
        $member = $this->member();
        $memberships = app(MembershipService::class);

        $current = $memberships->create($member, $this->plan(), [
            'start_date' => today()->subDays(20)->toDateString(),
            'activate' => true,
        ]);

        $memberships->renew($current);
        $member->refresh();

        $this->assertSame($current->id, $member->current_membership_id);
        $this->assertSame(MemberStatus::ACTIVE, $member->status);
        $this->assertSame($current->end_date->toDateString(), $member->membership_expires_on->toDateString());

        $check = app(AttendanceService::class)->validateEntry($member);

        $this->assertTrue($check->valid, "Entry was refused: {$check->message}");
    }

    #[Test]
    public function a_future_dated_membership_becomes_current_once_the_old_one_lapses(): void
    {
        // With nothing covering today, the profile should point at what the
        // member has actually bought rather than at nothing.
        $member = $this->member();
        $memberships = app(MembershipService::class);

        $expired = $memberships->create($member, $this->plan(), [
            'start_date' => today()->subMonths(3)->toDateString(),
            'activate' => true,
        ]);

        $future = $memberships->create($member, $this->plan(), [
            'start_date' => today()->addDays(10)->toDateString(),
        ]);

        $memberships->expireLapsed();
        $member->refresh();

        $this->assertSame($future->id, $member->current_membership_id);
        $this->assertNotSame($expired->id, $member->current_membership_id);
    }

    #[Test]
    public function renewing_long_after_expiry_starts_today(): void
    {
        // Beyond the chaining grace period, pretending the term was continuous
        // would sell the member months they were never able to use.
        config(['gms.membership.renew_chain_grace_days' => 30]);

        $member = $this->member();
        $memberships = app(MembershipService::class);

        $first = $memberships->create($member, $this->plan(), [
            'start_date' => today()->subMonths(8)->toDateString(),
        ]);

        $renewal = $memberships->renew($first);

        $this->assertSame(today()->toDateString(), $renewal->start_date->toDateString());
    }

    #[Test]
    public function a_renewal_does_not_recharge_the_registration_fee(): void
    {
        $member = $this->member();
        $memberships = app(MembershipService::class);
        $plan = $this->plan(['price' => 5000, 'registration_fee' => 1000]);

        $first = $memberships->create($member, $plan);
        $renewal = $memberships->renew($first);

        $this->assertSame(6000.0, (float) $first->final_amount);
        $this->assertSame(0.0, (float) $renewal->registration_fee);
        $this->assertSame(5000.0, (float) $renewal->final_amount);
    }

    #[Test]
    public function a_discount_larger_than_the_price_cannot_produce_a_negative_invoice(): void
    {
        $member = $this->member();

        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]), [
            'discount' => 9000,
        ]);

        $this->assertSame(0.0, (float) $membership->final_amount);
    }

    #[Test]
    public function the_expiry_sweep_only_takes_terms_past_their_grace_period(): void
    {
        $memberships = app(MembershipService::class);

        $lapsed = $memberships->create($this->member(['full_name' => 'Lapsed']), $this->plan(['grace_days' => 0]), [
            'start_date' => today()->subMonths(3)->toDateString(),
            'activate' => true,
        ]);

        $inGrace = $memberships->create($this->member(['full_name' => 'In grace']), $this->plan(['grace_days' => 10]), [
            'start_date' => today()->subMonth()->subDays(2)->toDateString(),
            'activate' => true,
        ]);

        $memberships->expireLapsed();

        $this->assertSame(MembershipStatus::EXPIRED, $lapsed->refresh()->status);
        $this->assertSame(MembershipStatus::ACTIVE, $inGrace->refresh()->status);
    }

    #[Test]
    public function expiring_a_membership_does_not_un_blacklist_the_member(): void
    {
        // An administrative decision about a person must survive a nightly job.
        $member = $this->member(['status' => MemberStatus::BLACKLISTED]);
        $memberships = app(MembershipService::class);

        $memberships->create($member, $this->plan(), ['activate' => true]);

        $memberships->refreshMemberState($member->refresh());

        $this->assertSame(MemberStatus::BLACKLISTED, $member->refresh()->status);
    }
}
