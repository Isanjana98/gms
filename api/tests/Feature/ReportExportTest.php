<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\MembershipService;
use App\Services\NumberGenerator;
use App\Services\PaymentService;
use App\Services\ReportExporter;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Every report is exportable, and every export is gated. */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    /** Runs the streamed response and returns what the browser would receive. */
    private function download(string $url, $user): string
    {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();

        return $response->streamedContent();
    }

    private function seedSomeOfEverything(): void
    {
        $member = $this->member(['full_name' => 'Nimal Perera']);
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        app(PaymentService::class)->record($member, ['amount' => 5000, 'membership_id' => $membership->id]);

        $category = ExpenseCategory::create(['code' => 'RENT', 'name' => 'Rent', 'is_recurring' => true]);

        Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $category->id,
            'reference' => app(NumberGenerator::class)->expenseReference(),
            'expense_date' => today()->toDateString(),
            'amount' => 150000,
            'method' => 'BANK_TRANSFER',
            'payee' => 'Property owner',
        ]);
    }

    #[Test]
    public function every_registered_report_exports_with_its_headings(): void
    {
        $this->seedSomeOfEverything();
        $admin = $this->admin();

        foreach (ReportExporter::reports() as $report => $definition) {
            $body = $this->download("/api/reports/export/{$report}", $admin);

            // The byte-order mark is there so Excel reads the file as UTF-8.
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body, "{$report} is missing its BOM");

            $firstLine = strtok(substr($body, 3), "\n");

            foreach ($definition['columns'] as $column) {
                $this->assertStringContainsString($column, $firstLine, "{$report} is missing the column {$column}");
            }
        }
    }

    #[Test]
    public function an_export_carries_rows_not_just_headings(): void
    {
        $this->seedSomeOfEverything();
        $admin = $this->admin();

        $expenses = $this->download('/api/reports/export/expenses', $admin);
        $this->assertStringContainsString('Property owner', $expenses);
        $this->assertStringContainsString('150000', $expenses);

        $payments = $this->download('/api/reports/export/revenue-payments', $admin);
        $this->assertStringContainsString('Nimal Perera', $payments);
    }

    #[Test]
    public function the_profit_export_ends_with_a_total_row(): void
    {
        // A profit report read without one invites the reader to add the
        // column up themselves and get it wrong.
        $this->seedSomeOfEverything();

        $body = $this->download('/api/reports/export/profit-loss', $this->admin());

        $this->assertStringContainsString('Total', $body);
    }

    #[Test]
    public function an_unknown_report_is_a_404_not_an_empty_file(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/reports/export/everything-please')
            ->assertNotFound();
    }

    #[Test]
    public function reception_can_export_attendance_but_not_money(): void
    {
        $this->seedSomeOfEverything();
        $reception = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($reception)->get('/api/reports/export/attendance-members')->assertOk();

        foreach (['revenue-payments', 'revenue-daily', 'outstanding', 'expenses', 'profit-loss'] as $report) {
            $this->actingAs($reception)
                ->get("/api/reports/export/{$report}")
                ->assertForbidden();
        }
    }

    #[Test]
    public function a_trainer_can_export_nothing(): void
    {
        $trainer = $this->userWithRole(Permissions::ROLE_TRAINER);

        foreach (array_keys(ReportExporter::reports()) as $report) {
            $this->actingAs($trainer)
                ->get("/api/reports/export/{$report}")
                ->assertForbidden();
        }
    }

    #[Test]
    public function the_catalogue_lists_only_what_the_caller_may_download(): void
    {
        $reception = $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->getJson('/api/reports/exports')
            ->assertOk()
            ->json('data');

        $keys = array_column($reception, 'report');

        $this->assertContains('attendance-members', $keys);
        $this->assertNotContains('expenses', $keys);
        $this->assertNotContains('profit-loss', $keys);

        $admin = $this->actingAs($this->admin())
            ->getJson('/api/reports/exports')
            ->assertOk()
            ->json('data');

        $this->assertCount(count(ReportExporter::reports()), $admin);
    }

    #[Test]
    public function the_original_attendance_export_url_still_works(): void
    {
        // Kept so existing links and bookmarks do not break.
        $this->seedSomeOfEverything();

        $body = $this->download('/api/reports/attendance/export', $this->admin());

        $this->assertStringContainsString('Membership No', $body);
    }
}
