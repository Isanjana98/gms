<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 5 / 6 - A membership is one purchased term of a plan. Renewals create a
 * new row linked to the one they replace (`renewed_from_id`) so the member's
 * full history stays intact and auditable.
 *
 * Plan fields are copied onto the row at sale time: changing a plan's price
 * later must never rewrite what a member was actually charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->foreignId('renewed_from_id')->nullable()->constrained('memberships')->nullOnDelete();

            $table->string('reference', 30)->unique();
            $table->string('plan_name');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('grace_days')->default(0);

            $table->decimal('price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->decimal('final_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->enum('payment_status', ['UNPAID', 'PARTIAL', 'PAID', 'REFUNDED'])->default('UNPAID');
            $table->enum('status', ['PENDING', 'ACTIVE', 'EXPIRED', 'SUSPENDED', 'CANCELLED'])
                ->default('PENDING');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'status']);
            $table->index(['branch_id', 'status']);
            // Drives the "expiring soon" dashboard card and the nightly
            // expiry sweep (SRS 15, 21).
            $table->index(['status', 'end_date']);
            $table->index('payment_status');
        });

        // Deferred FK: members is created before memberships.
        Schema::table('members', function (Blueprint $table) {
            $table->foreign('current_membership_id')
                ->references('id')->on('memberships')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['current_membership_id']);
        });

        Schema::dropIfExists('memberships');
    }
};
