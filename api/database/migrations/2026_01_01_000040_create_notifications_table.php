<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 21 - Outbound notification queue.
 *
 * Rows are written by the reminder jobs and drained by a channel driver, so
 * the system works today with the in-app and mail channels and gains SMS or
 * WhatsApp by registering another driver rather than by changing callers.
 *
 * `dedupe_key` stops the nightly expiry sweep re-sending the same "expires in
 * 3 days" message every time it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->enum('type', [
                'MEMBERSHIP_EXPIRY_REMINDER',
                'MEMBERSHIP_EXPIRED',
                'PAYMENT_DUE',
                'PAYMENT_CONFIRMATION',
                'NEW_MEMBERSHIP',
                'PROMOTIONAL',
                'SYSTEM',
            ]);
            $table->enum('channel', ['IN_APP', 'EMAIL', 'SMS', 'WHATSAPP'])->default('IN_APP');

            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('data')->nullable();

            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'CANCELLED'])->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['member_id', 'type']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
