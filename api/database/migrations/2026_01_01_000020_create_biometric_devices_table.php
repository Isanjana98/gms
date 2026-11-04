<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 23 - Biometric device registry.
 *
 * `driver` keeps the vendor pluggable (SRS 35): the shipped implementation is
 * Hikvision ISAPI, but the rest of the system only ever talks to the driver
 * contract, so adding ZKTeco or Suprema is a new class and a new enum value.
 *
 * Credentials are encrypted at rest by the model cast - the device password is
 * effectively a key to the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('driver', 30)->default('hikvision');
            $table->string('model')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('firmware', 100)->nullable();

            $table->string('ip_address', 45);
            $table->unsignedSmallInteger('port')->default(80);
            $table->boolean('use_https')->default(false);
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();

            // AUTO = first scan in, next scan out (SRS 11).
            $table->enum('mode', ['AUTO', 'CHECK_IN', 'CHECK_OUT'])->default('AUTO');
            // PULL polls the device over ISAPI; PUSH receives device-initiated
            // HTTP event notifications on the listener endpoint.
            $table->enum('sync_mode', ['PULL', 'PUSH', 'BOTH'])->default('PULL');
            $table->boolean('is_active')->default(true);

            $table->enum('connection_status', ['UNKNOWN', 'ONLINE', 'OFFLINE', 'ERROR'])->default('UNKNOWN');
            $table->string('last_error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            // High-water mark: only events after this are pulled (SRS 13).
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
            $table->index('connection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
