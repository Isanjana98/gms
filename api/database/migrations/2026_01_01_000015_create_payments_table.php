<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 17 / 18 - Payments and receipts.
 *
 * A refund is stored as its own row with a negative `amount` and
 * `refund_of_payment_id` set, so the ledger is append-only: nothing is ever
 * edited away and the sum of rows is always the true balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('refund_of_payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('receipt_number', 30)->unique();
            $table->enum('payment_type', [
                'MEMBERSHIP',
                'RENEWAL',
                'REGISTRATION_FEE',
                'PERSONAL_TRAINING',
                'OTHER',
                'REFUND',
            ])->default('MEMBERSHIP');

            $table->decimal('amount', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->enum('method', ['CASH', 'CARD', 'BANK_TRANSFER', 'ONLINE'])->default('CASH');
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->enum('status', ['COMPLETED', 'PENDING', 'FAILED', 'REFUNDED'])->default('COMPLETED');
            $table->text('notes')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'paid_at']);
            // Today's-revenue dashboard card and the daily cash report.
            $table->index(['branch_id', 'status', 'paid_at']);
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
