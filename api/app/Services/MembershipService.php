<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\Biometric\AccessControlService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SRS 5 / 6 - Selling, renewing and expiring memberships.
 *
 * Every method that writes does so inside a transaction: a membership, its
 * invoice, the member's cached pointer and the member's status are one fact
 * split across four tables, and a half-applied sale is worse than a failed one.
 */
class MembershipService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Sell a new membership term.
     *
     * @param  array{start_date?:string|null, discount?:float|null, registration_fee?:float|null, notes?:string|null, activate?:bool}  $options
     */
    public function create(Member $member, MembershipPlan $plan, array $options = []): Membership
    {
        $membership = $this->numbers->withRetry(fn () => DB::transaction(function () use ($member, $plan, $options) {
            $start = isset($options['start_date'])
                ? Carbon::parse($options['start_date'])->startOfDay()
                : today();

            $discount = round((float) ($options['discount'] ?? $plan->default_discount), 2);
            $registrationFee = round((float) ($options['registration_fee'] ?? $plan->registration_fee), 2);
            $price = round((float) $plan->price, 2);

            $membership = Membership::create([
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'membership_plan_id' => $plan->id,
                'reference' => $this->numbers->membershipReference(),
                // Copied, not referenced: repricing a plan must never rewrite
                // what an existing member was sold.
                'plan_name' => $plan->name,
                'start_date' => $start,
                'end_date' => $plan->calculateEndDate($start),
                'grace_days' => $plan->grace_days,
                'price' => $price,
                'discount' => $discount,
                'registration_fee' => $registrationFee,
                'final_amount' => $this->finalAmount($price, $discount, $registrationFee),
                'paid_amount' => 0,
                'payment_status' => PaymentStatus::UNPAID,
                'status' => MembershipStatus::PENDING,
                'notes' => $options['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->invoices->forMembership($membership);

            // SRS 32 activates only after payment, but a gym that takes a
            // deposit and lets the member start today needs the override.
            if ($options['activate'] ?? false) {
                $this->activate($membership);
            }

            $this->refreshMemberState($member->refresh());

            return $membership->refresh();
        }));

        $this->pushAccessToReaders($member);

        return $membership;
    }

    /**
     * SRS 6 - Renew an existing membership.
     *
     * The new term continues from the old expiry date rather than from today,
     * so a member who renews a week early keeps the days they already paid
     * for. Once the old term is far enough past (renew_chain_grace_days) that
     * continuity is a fiction, the new term simply starts today.
     */
    public function renew(Membership $previous, ?MembershipPlan $plan = null, array $options = []): Membership
    {
        $plan = $plan ?? $previous->plan;

        if ($plan === null) {
            throw new \RuntimeException('Cannot renew: the original plan no longer exists. Choose a plan explicitly.');
        }

        $membership = $this->numbers->withRetry(fn () => DB::transaction(function () use ($previous, $plan, $options) {
            $member = $previous->member;
            $start = $this->renewalStartDate($previous, $options['start_date'] ?? null);

            $discount = round((float) ($options['discount'] ?? $plan->default_discount), 2);
            // A renewal never re-charges the joining fee unless asked to.
            $registrationFee = round((float) ($options['registration_fee'] ?? 0), 2);
            $price = round((float) $plan->price, 2);

            $membership = Membership::create([
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'membership_plan_id' => $plan->id,
                'renewed_from_id' => $previous->id,
                'reference' => $this->numbers->membershipReference(),
                'plan_name' => $plan->name,
                'start_date' => $start,
                'end_date' => $plan->calculateEndDate($start),
                'grace_days' => $plan->grace_days,
                'price' => $price,
                'discount' => $discount,
                'registration_fee' => $registrationFee,
                'final_amount' => $this->finalAmount($price, $discount, $registrationFee),
                'paid_amount' => 0,
                'payment_status' => PaymentStatus::UNPAID,
                'status' => MembershipStatus::PENDING,
                'notes' => $options['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->invoices->forMembership($membership);

            // The superseded term is closed out only once the new one starts,
            // so a member renewing early keeps access in the meantime.
            if ($previous->status === MembershipStatus::ACTIVE && $start->lessThanOrEqualTo(today())) {
                $previous->forceFill(['status' => MembershipStatus::EXPIRED])->save();
            }

            if ($options['activate'] ?? false) {
                $this->activate($membership);
            }

            $this->refreshMemberState($member->refresh());

            return $membership->refresh();
        }));

        // The member of the term being renewed: `$member` inside the closure
        // above is the closure's own, and is not in scope out here.
        $this->pushAccessToReaders($previous->member);

        return $membership;
    }

    /**
     * Where a renewal term begins.
     *
     * Continuing from an expiry date means starting the day *after* it: the
     * old term's end_date is itself a day of access, so reusing it would sell
     * the member a day they already have.
     */
    public function renewalStartDate(Membership $previous, ?string $explicit = null): CarbonInterface
    {
        if ($explicit !== null) {
            return Carbon::parse($explicit)->startOfDay();
        }

        if (! config('gms.membership.renew_from_expiry', true)) {
            return today();
        }

        $dayAfterPrevious = $previous->end_date->copy()->addDay()->startOfDay();

        if ($dayAfterPrevious->greaterThan(today())) {
            return $dayAfterPrevious;
        }

        // Lapsed, but recently enough that the member reads it as continuous.
        $graceDays = (int) config('gms.membership.renew_chain_grace_days', 30);

        if ($dayAfterPrevious->greaterThanOrEqualTo(today()->subDays($graceDays))) {
            return $dayAfterPrevious;
        }

        return today();
    }

    /** SRS 32 step 6 - Activate a membership so it admits the member. */
    public function activate(Membership $membership): Membership
    {
        if ($membership->status === MembershipStatus::CANCELLED) {
            throw new \RuntimeException('A cancelled membership cannot be activated.');
        }

        DB::transaction(function () use ($membership) {
            $membership->forceFill([
                'status' => MembershipStatus::ACTIVE,
                'activated_at' => $membership->activated_at ?? now(),
            ])->save();

            $this->refreshMemberState($membership->member);
        });

        $this->pushAccessToReaders($membership->member);

        return $membership->refresh();
    }

    public function suspend(Membership $membership, ?string $reason = null): Membership
    {
        DB::transaction(function () use ($membership, $reason) {
            $membership->forceFill([
                'status' => MembershipStatus::SUSPENDED,
                'notes' => trim((string) $membership->notes."\n".($reason ? "Suspended: {$reason}" : '')) ?: null,
            ])->save();

            $this->refreshMemberState($membership->member);
        });

        $this->pushAccessToReaders($membership->member);

        return $membership->refresh();
    }

    public function cancel(Membership $membership, ?string $reason = null): Membership
    {
        DB::transaction(function () use ($membership, $reason) {
            $membership->forceFill([
                'status' => MembershipStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->refreshMemberState($membership->member);
        });

        $this->pushAccessToReaders($membership->member);

        return $membership->refresh();
    }

    /**
     * Recompute the member's cached membership state.
     *
     * `current_membership_id` and `membership_expires_on` exist so the
     * fingerprint path can validate entry with one indexed read instead of
     * sorting the member's whole history at the turnstile. They are a cache,
     * and this is the single place allowed to write it.
     */
    public function refreshMemberState(Member $member): void
    {
        $today = today()->toDateString();

        // The term that admits them *today* wins.
        //
        // Picking the furthest-out term instead would break the common case of
        // renewing early: the member would be pointed at next month's unpaid
        // renewal and refused at the door, despite holding a paid term that is
        // still running. Grace days count, so the comparison is against
        // end_date + grace_days rather than end_date.
        $active = $member->memberships()
            ->where('status', MembershipStatus::ACTIVE)
            ->whereDate('start_date', '<=', $today)
            ->whereRaw('DATE_ADD(end_date, INTERVAL grace_days DAY) >= ?', [$today])
            ->orderByDesc('end_date')
            ->first();

        // Nothing covers today: fall back to the soonest term still to come,
        // so the profile shows what they have bought rather than nothing.
        $upcoming = $active ?? $member->memberships()
            ->whereIn('status', [MembershipStatus::ACTIVE, MembershipStatus::PENDING])
            ->orderBy('start_date')
            ->first();

        // Nothing live at all: show the most recent term so the profile still
        // says when they lapsed.
        $latest = $member->memberships()->orderByDesc('end_date')->first();

        $status = $member->status;

        // Never overwrite a deliberate administrative decision. Blacklisting
        // someone must not be undone by the nightly job noticing they renewed.
        if (! in_array($status, [MemberStatus::BLACKLISTED, MemberStatus::SUSPENDED], true)) {
            $status = match (true) {
                $active !== null => MemberStatus::ACTIVE,
                $latest === null => MemberStatus::INACTIVE,
                $latest->is_expired => MemberStatus::EXPIRED,
                default => MemberStatus::INACTIVE,
            };
        }

        $member->forceFill([
            'current_membership_id' => $active?->id ?? $upcoming?->id ?? $latest?->id,
            // The date the member actually loses access, which is the end of
            // the term they are on now - not of a renewal they have not
            // started yet.
            'membership_expires_on' => ($active ?? $latest)?->end_date,
            'status' => $status,
        ])->save();

    }

    /**
     * Tell the readers who may now come in.
     *
     * Called by each public method *after* its transaction has returned, not
     * from inside `refreshMemberState`. Two reasons, and the second is the
     * one that matters:
     *
     *  - A reader on the end of a slow network must never be holding a
     *    database transaction open while somebody is paying at the counter.
     *  - Deferring it to `DB::afterCommit` would put it past the point where
     *    anything can observe it, including a test - and a hook that silently
     *    never runs is worse than not having one.
     *
     * Failure is swallowed deliberately. An unreachable reader is not a
     * reason to fail a payment that has already been taken, and the nightly
     * sweep picks up whatever did not land. Nothing is sent at all unless the
     * decision actually changed, so the ordinary save touches no hardware.
     */
    private function pushAccessToReaders(Member $member): void
    {
        try {
            app(AccessControlService::class)->syncMember($member->refresh());
        } catch (\Throwable $e) {
            Log::warning('Could not push access to the readers after a membership change.', [
                'member_id' => $member->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SRS 6 - Nightly sweep that expires terms whose grace period has run out.
     *
     * @return int number of memberships expired
     */
    public function expireLapsed(): int
    {
        $count = 0;

        Membership::query()
            ->lapsed()
            ->with('member')
            ->chunkById(200, function ($memberships) use (&$count) {
                foreach ($memberships as $membership) {
                    DB::transaction(function () use ($membership, &$count) {
                        $membership->forceFill(['status' => MembershipStatus::EXPIRED])->save();

                        if ($membership->member) {
                            $this->refreshMemberState($membership->member);
                        }

                        $count++;
                    });
                }
            });

        return $count;
    }

    private function finalAmount(float $price, float $discount, float $registrationFee): float
    {
        // Guard against a discount larger than the price producing a negative
        // invoice that the payment ledger would then have to explain.
        return round(max(0, $price - $discount) + $registrationFee, 2);
    }
}
