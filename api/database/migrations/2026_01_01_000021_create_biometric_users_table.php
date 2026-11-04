<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 8 / 24 - Mapping between a gym member and their identity on a device.
 *
 * Deliberately stores only the device-side reference (employeeNo) and metadata
 * about enrolment - never a raw fingerprint template. SRS 26 requires that
 * biometric data stay on the device; this table is what lets us honour that
 * and still resolve a scan to a member.
 *
 * `member_id` is nullable so users discovered on a device that match no member
 * can be recorded and surfaced as unmatched (SRS 24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();

            // The identifier as the device knows it (Hikvision: employeeNo).
            $table->string('biometric_user_id', 64);
            $table->string('device_user_name')->nullable();

            $table->enum('enrollment_status', ['PENDING', 'ENROLLED', 'FAILED', 'REMOVED'])
                ->default('PENDING');
            $table->unsignedTinyInteger('fingerprint_count')->default(0);
            $table->boolean('has_card')->default(false);
            $table->boolean('has_face')->default(false);

            $table->enum('sync_status', ['PENDING', 'SYNCED', 'FAILED'])->default('PENDING');
            $table->string('sync_error')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // One identity per member per device, and one employeeNo per device.
            $table->unique(['device_id', 'biometric_user_id'], 'bio_users_device_uid_unique');
            $table->index(['member_id', 'is_active']);
            $table->index('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_users');
    }
};
