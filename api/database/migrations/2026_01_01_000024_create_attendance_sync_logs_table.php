<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 13 - One row per synchronisation run, so "why is attendance missing?"
 * has an answer: which device, which window, what came back, what failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->cascadeOnDelete();

            $table->enum('trigger', ['SCHEDULED', 'MANUAL', 'PUSH', 'RECOVERY'])->default('SCHEDULED');
            $table->enum('type', ['ATTENDANCE', 'USERS_UPLOAD', 'USERS_DOWNLOAD', 'CONNECTION_TEST'])
                ->default('ATTENDANCE');
            $table->enum('status', ['RUNNING', 'SUCCESS', 'PARTIAL', 'FAILED'])->default('RUNNING');

            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();

            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_new')->default(0);
            $table->unsignedInteger('records_duplicate')->default(0);
            $table->unsignedInteger('records_unmatched')->default(0);
            $table->unsignedInteger('records_rejected')->default(0);
            $table->unsignedInteger('records_failed')->default(0);

            // useCurrent() is explicit on purpose: MySQL/MariaDB only grant an
            // implicit default to the *first* timestamp column in a table, so
            // relying on column order here is a migration that breaks when
            // someone reorders the fields.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_logs');
    }
};
