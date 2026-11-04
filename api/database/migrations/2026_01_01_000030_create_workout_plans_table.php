<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 20 - Workout plans authored by a trainer for a member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('goal', ['WEIGHT_LOSS', 'MUSCLE_GAIN', 'ENDURANCE', 'STRENGTH', 'GENERAL_FITNESS', 'REHAB'])
                ->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'status']);
            $table->index('trainer_id');
        });

        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_plan_id')->constrained('workout_plans')->cascadeOnDelete();

            // 1 = Monday ... 7 = Sunday; null = applies to every session.
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->string('exercise_name');
            $table->string('muscle_group', 100)->nullable();
            $table->unsignedSmallInteger('sets')->nullable();
            $table->string('repetitions', 50)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workout_plan_id', 'day_of_week', 'sort_order'], 'workout_ex_plan_day_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_exercises');
        Schema::dropIfExists('workout_plans');
    }
};
