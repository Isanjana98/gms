<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\MembershipService;
use App\Services\NumberGenerator;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Money going out, and the profit report that needs both halves of the books. */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function category(array $attributes = []): ExpenseCategory
    {
        return ExpenseCategory::create([
            'code' => 'CAT-'.uniqid(),
            'name' => 'Rent',
            'is_recurring' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function expense(array $attributes = []): Expense
    {
        return Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $attributes['expense_category_id'] ?? $this->category()->id,
            'reference' => app(NumberGenerator::class)->expenseReference(),
            'expense_date' => today()->toDateString(),
            'amount' => 1000,
            'tax_amount' => 0,
            'method' => 'CASH',
            ...$attributes,
        ]);
    }

    #[Test]
    public function an_expense_is_recorded_with_a_generated_reference(): void
    {
        $category = $this->category(['name' => 'Utilities']);

        $response = $this->actingAs($this->admin())->postJson('/api/expenses', [
            'expense_category_id' => $category->id,
            'expense_date' => today()->toDateString(),
            'amount' => 38000,
            'tax_amount' => 2000,
            'method' => 'BANK_TRANSFER',
            'payee' => 'CEB',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 38000)
            // Tax is carried separately but the total is what the reports use.
            ->assertJsonPath('data.total', 40000);

        $this->assertMatchesRegularExpression('/^EXP-\d{4}-\d{5}$/', $response->json('data.reference'));
    }

    #[Test]
    public function an_expense_cannot_be_dated_in_the_future(): void
    {
        // Committed spending that has not left the account yet is a different
        // thing; allowing it would make this month's costs depend on next
        // month's invoices.
        $this->actingAs($this->admin())
            ->postJson('/api/expenses', [
                'expense_category_id' => $this->category()->id,
                'expense_date' => today()->addDay()->toDateString(),
                'amount' => 100,
                'method' => 'CASH',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_date');
    }

    #[Test]
    public function an_expense_can_be_corrected_and_the_change_is_audited(): void
    {
        $expense = $this->expense(['amount' => 1000]);

        $this->actingAs($this->admin())
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_category_id' => $expense->expense_category_id,
                'expense_date' => $expense->expense_date->toDateString(),
                'amount' => 1500,
                'method' => 'CASH',
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', 1500);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Expense::class,
            'auditable_id' => $expense->id,
            'event' => 'updated',
        ]);
    }

    #[Test]
    public function a_category_with_spending_against_it_cannot_be_deleted(): void
    {
        $category = $this->category();
        $this->expense(['expense_category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/expense-categories/{$category->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    #[Test]
    public function an_unused_category_can_be_deleted(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin())
            ->deleteJson("/api/expense-categories/{$category->id}")
            ->assertOk();

        $this->assertSoftDeleted('expense_categories', ['id' => $category->id]);
    }

    #[Test]
    public function reception_cannot_see_what_the_gym_spends(): void
    {
        // Seeing the cost base is seeing the gym's margin.
        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->getJson('/api/expenses')
            ->assertForbidden();
    }

    #[Test]
    public function an_accountant_manages_expenses(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_ACCOUNTANT))
            ->getJson('/api/expenses')
            ->assertOk();
    }

    #[Test]
    public function a_trainer_cannot_record_an_expense(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER))
            ->postJson('/api/expenses', [
                'expense_category_id' => $this->category()->id,
                'expense_date' => today()->toDateString(),
                'amount' => 100,
                'method' => 'CASH',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_list_total_covers_every_matching_row_not_just_the_page(): void
    {
        // A footer that silently totals one page of a filtered set is a bug
        // nobody reports, because it still looks like a number.
        $category = $this->category();

        for ($i = 0; $i < 30; $i++) {
            $this->expense(['expense_category_id' => $category->id, 'amount' => 100]);
        }

        $response = $this->actingAs($this->admin())
            ->getJson('/api/expenses?per_page=10')
            ->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(30, $response->json('summary.entries'));
        $this->assertSame(3000.0, (float) $response->json('summary.total'));
    }

    #[Test]
    public function the_expense_report_totals_are_not_zero(): void
    {
        /*
         * A regression test with a specific cause. Expense has a `total`
         * accessor (amount + tax), and the aggregate queries originally
         * aliased their SUM as `total` too. The accessor shadowed the
         * aggregate and recomputed from attributes the query never selected,
         * so every figure came back as 0.00 — no error, just a wrong answer.
         */
        $recurring = $this->category(['name' => 'Rent', 'is_recurring' => true]);
        $oneOff = $this->category(['name' => 'Equipment', 'is_recurring' => false]);

        $this->expense(['expense_category_id' => $recurring->id, 'amount' => 1000, 'tax_amount' => 200]);
        $this->expense(['expense_category_id' => $oneOff->id, 'amount' => 500]);

        $report = app(ReportService::class)->expenses(
            today()->subDay()->toDateString(),
            today()->toDateString(),
        );

        $this->assertSame(1700.0, $report['total']);
        $this->assertSame(1200.0, $report['recurring']);
        $this->assertSame(500.0, $report['one_off']);
        $this->assertSame(1200.0, $report['by_category'][0]['total']);
        $this->assertSame(1700.0, array_sum(array_column($report['by_method'], 'total')));
        $this->assertSame(1700.0, array_sum(array_column($report['daily'], 'total')));
    }

    #[Test]
    public function profit_is_income_less_expenses(): void
    {
        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        app(PaymentService::class)->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);

        $this->expense(['amount' => 2000]);

        $report = app(ReportService::class)->profitAndLoss(
            today()->startOfMonth()->toDateString(),
            today()->toDateString(),
        );

        $this->assertSame(5000.0, $report['income']);
        $this->assertSame(2000.0, $report['expenses']);
        $this->assertSame(3000.0, $report['profit']);
        $this->assertSame(60.0, $report['margin_percent']);
    }

    #[Test]
    public function a_month_with_costs_but_no_income_still_appears(): void
    {
        // Dropping empty months would hide exactly the ones worth looking at.
        $this->expense(['amount' => 2000]);

        $report = app(ReportService::class)->profitAndLoss(
            today()->subMonths(2)->startOfMonth()->toDateString(),
            today()->toDateString(),
        );

        $this->assertCount(3, $report['by_month']);
        $this->assertNull($report['margin_percent']);
        $this->assertSame(-2000.0, $report['profit']);
    }

    #[Test]
    public function profit_and_loss_needs_both_halves_of_the_books(): void
    {
        // Reception holds reports.view but not expenses.view, so a margin
        // would be a number they cannot check.
        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->getJson('/api/reports/profit-loss')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Permissions::ROLE_ACCOUNTANT))
            ->getJson('/api/reports/profit-loss')
            ->assertOk();
    }
}
