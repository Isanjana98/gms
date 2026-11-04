<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Services\MembershipService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 17 / 18 - The money ledger. */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_payment_settles_its_membership_invoice(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));

        app(PaymentService::class)->record($member, [
            'amount' => 5000,
            'membership_id' => $membership->id,
            'method' => 'CASH',
        ]);

        $invoice = $membership->invoices()->first()->refresh();

        $this->assertSame(5000.0, (float) $invoice->paid_amount);
        $this->assertSame('PAID', $invoice->status);
        $this->assertSame(0.0, $invoice->balance);
    }

    #[Test]
    public function a_refund_is_a_new_negative_row_not_an_edit(): void
    {
        // The receipt the member was handed must still exist as printed.
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payments = app(PaymentService::class);

        $payment = $payments->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);
        $refund = $payments->refund($payment, 2000, 'Partial refund');

        $this->assertSame(-2000.0, (float) $refund->amount);
        $this->assertSame(PaymentType::REFUND, $refund->payment_type);
        $this->assertSame($payment->id, $refund->refund_of_payment_id);
        // The original is untouched and still fully valued.
        $this->assertSame(5000.0, (float) $payment->refresh()->amount);
        $this->assertSame(3000.0, (float) $membership->refresh()->paid_amount);
    }

    #[Test]
    public function a_full_refund_marks_the_original_refunded(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payments = app(PaymentService::class);

        $payment = $payments->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);
        $payments->refund($payment);

        $this->assertSame('REFUNDED', $payment->refresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $membership->refresh()->payment_status);
    }

    #[Test]
    public function refunding_more_than_remains_is_refused(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payments = app(PaymentService::class);

        $payment = $payments->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);
        $payments->refund($payment, 3000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only 2,000.00');

        $payments->refund($payment, 3000);
    }

    #[Test]
    public function a_refund_cannot_itself_be_refunded(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payments = app(PaymentService::class);

        $payment = $payments->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);
        $refund = $payments->refund($payment, 1000);

        $this->expectException(\InvalidArgumentException::class);

        $payments->refund($refund, 500);
    }

    #[Test]
    public function a_zero_or_negative_payment_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->record($this->member(), ['amount' => 0]);
    }

    #[Test]
    public function receipt_numbers_are_unique_and_sequential(): void
    {
        $member = $this->member();
        $payments = app(PaymentService::class);

        $numbers = collect(range(1, 5))
            ->map(fn () => $payments->record($member, ['amount' => 100])->receipt_number);

        $this->assertSame(5, $numbers->unique()->count());
        $this->assertSame($numbers->sort()->values()->all(), $numbers->values()->all());
    }

    #[Test]
    public function the_ledger_sum_always_equals_the_membership_paid_amount(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 10000]));
        $payments = app(PaymentService::class);

        $payments->record($member, ['amount' => 4000, 'membership_id' => $membership->id]);
        $second = $payments->record($member, ['amount' => 6000, 'membership_id' => $membership->id]);
        $payments->refund($second, 1500);

        $ledger = (float) Payment::where('membership_id', $membership->id)->sum('amount');

        $this->assertSame($ledger, (float) $membership->refresh()->paid_amount);
        $this->assertSame(8500.0, $ledger);
    }

    #[Test]
    public function floating_point_rounding_does_not_leave_a_membership_reading_partial(): void
    {
        // 0.1 + 0.2 != 0.3 in binary floating point; a fully paid membership
        // must not read as PARTIAL because of it.
        $this->assertSame(PaymentStatus::PAID, PaymentStatus::fromAmounts(0.1 + 0.2, 0.3));
    }
}
