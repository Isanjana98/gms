<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money going out, so the system can answer what it never could: did the gym
 * make anything this month?
 *
 * Deliberately a separate table from `payments` rather than negative rows in
 * the ledger. A payment belongs to a member and settles an invoice; an expense
 * belongs to a supplier and settles nothing. Folding them together would mean
 * every revenue query had to remember to exclude expenses, and the first one
 * that forgot would quietly under-report takings.
 *
 * Amounts are stored positive. The sign lives in the table the row is in, not
 * in the number, so a mistyped minus cannot turn a cost into income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Restrictive: a category with spending recorded against it must be
            // deactivated, not deleted. Losing the category would leave last
            // year's accounts with an unexplained total.
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();

            $table->string('reference', 30)->unique();

            // The date the money was spent, which is not always the date it was
            // entered — an invoice keyed in on Monday may be dated Friday, and
            // the accounts follow the spend.
            $table->date('expense_date');

            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->enum('method', ['CASH', 'CARD', 'BANK_TRANSFER', 'ONLINE'])->default('CASH');

            $table->string('payee')->nullable();
            // The supplier's own invoice or receipt number, for reconciliation.
            $table->string('supplier_reference', 100)->nullable();
            $table->text('description')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The expense report and the profit summary both read a date range
            // within one branch; this is the index both of them use.
            $table->index(['branch_id', 'expense_date']);
            $table->index(['expense_category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
