<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class timetable as published on the website.
 *
 * Display only, and the distinction matters: this says "Spin, Tuesday 6pm,
 * with Priya". It does not hold bookings, capacity or attendance, because
 * class booking is a separate feature that is not built. Nothing here should
 * be mistaken for a reservation system — a member reading this turns up, the
 * same as reading a poster on the wall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            $table->string('class_name');
            $table->string('description', 500)->nullable();

            // 1 = Monday, matching ISO-8601 and Carbon's dayOfWeekIso, so the
            // grouping in the view needs no translation table.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('room')->nullable();
            // Nullable and nulled on delete: a trainer leaving should empty the
            // instructor column, not delete the class from the timetable.
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            // Kept as free text as well, so a visiting instructor who is not a
            // staff record can still be named.
            $table->string('instructor_name')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'day_of_week', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
