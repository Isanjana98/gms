<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 5 - Unlimited membership packages. Duration is stored as a unit + count
 * rather than a fixed month count so day- and week-based passes are possible
 * without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('duration_value')->default(1);
            $table->enum('duration_unit', ['DAY', 'WEEK', 'MONTH', 'YEAR'])->default('MONTH');

            $table->decimal('price', 12, 2);
            $table->decimal('default_discount', 12, 2)->default(0);
            $table->decimal('registration_fee', 12, 2)->default(0);

            // Grace days added to the expiry date before the membership is
            // treated as EXPIRED by the nightly job (SRS 6).
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
