<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw scan events exactly as received from a device, before interpretation.
 *
 * Not named in SRS 28, but it is what makes SRS 13 and 14 actually work. The
 * device is the source of truth and re-sends overlapping windows: on a retry,
 * after a network outage, or when an administrator hits "Sync Attendance"
 * twice. `event_hash` is a unique fingerprint of (device, user, time, type),
 * so replaying the same log is a no-op at the database level rather than
 * something the sync code has to remember to get right.
 *
 * It also preserves the unmodified device payload, so an attendance dispute
 * can be settled against what the hardware actually reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('attendance_id')->nullable();

            $table->string('biometric_user_id', 64)->nullable();
            $table->timestamp('event_time')->useCurrent();
            $table->string('event_hash', 64)->unique();

            // How the person proved identity at the reader (SRS 12).
            $table->enum('verification_type', ['FINGERPRINT', 'CARD', 'FACE', 'PASSWORD', 'MANUAL', 'OTHER'])
                ->default('FINGERPRINT');
            $table->enum('direction', ['IN', 'OUT', 'UNKNOWN'])->default('UNKNOWN');

            $table->enum('process_status', ['PENDING', 'PROCESSED', 'DUPLICATE', 'UNMATCHED', 'REJECTED', 'ERROR'])
                ->default('PENDING');
            $table->string('process_message')->nullable();

            $table->enum('source', ['PULL', 'PUSH', 'MANUAL'])->default('PULL');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'event_time']);
            $table->index(['process_status', 'event_time']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_event_logs');
    }
};
