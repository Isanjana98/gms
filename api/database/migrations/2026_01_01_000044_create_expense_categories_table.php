<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a gym spends money on: rent, salaries, equipment, utilities.
 *
 * A table rather than an enum because the categories are the gym's own chart of
 * accounts. A gym that wants "Pool chemicals" as a line should not need a
 * migration, and an accountant reconciling against their books needs the
 * categories to match the ones they already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();

            /*
             * Rent and salaries recur every month; equipment does not. Marking
             * which is which lets the profit report say what a quiet month
             * still costs, rather than treating every line as a one-off.
             */
            $table->boolean('is_recurring')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
