<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 12 - One row per gym visit: the check-in, its matching check-out, and
 * the derived duration used by the reports in SRS 16.
 *
 * A visit stays OPEN until a check-out arrives or the nightly roll-over closes
 * it, which is why `check_out_time` is nullable and `duration_minutes` is
 * computed rather than sent by the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('check_in_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('check_out_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();

            $table->string('biometric_user_id', 64)->nullable();
            $table->date('attendance_date');
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->enum('attendance_type', ['BIOMETRIC', 'MANUAL', 'QR', 'IMPORTED'])->default('BIOMETRIC');
            $table->enum('verification_type', ['FINGERPRINT', 'CARD', 'FACE', 'PASSWORD', 'MANUAL', 'OTHER'])
                ->default('FINGERPRINT');
            // OPEN = checked in, not yet out. AUTO_CLOSED = closed by the
            // nightly job because no check-out ever arrived.
            $table->enum('status', ['OPEN', 'COMPLETED', 'AUTO_CLOSED', 'CANCELLED'])->default('OPEN');

            // Membership state captured at scan time (SRS 10), so a report run
            // months later still shows whether entry was legitimate then.
            $table->boolean('membership_valid')->default(true);
            $table->string('validation_message')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Member attendance history + "total visits per member" (SRS 16).
            $table->index(['member_id', 'attendance_date']);
            // Daily/monthly branch reports and today's-attendance card.
            $table->index(['branch_id', 'attendance_date']);
            // Finding the open visit to close on the next scan - the hot path
            // of every check-out.
            $table->index(['member_id', 'status']);
            $table->index('check_in_time');
        });

        Schema::table('biometric_event_logs', function (Blueprint $table) {
            $table->foreign('attendance_id')->references('id')->on('attendance')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('biometric_event_logs', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
        });

        Schema::dropIfExists('attendance');
    }
};
