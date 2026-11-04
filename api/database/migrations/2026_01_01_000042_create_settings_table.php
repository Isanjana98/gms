<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable settings. SRS 10 requires that whether an invalid
 * membership blocks entry be "subject to administrator configuration", which
 * cannot live in .env if reception is to change it.
 *
 * A null branch_id is the global default; a branch row overrides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('group', 50)->default('general');
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'integer', 'decimal', 'boolean', 'json'])->default('string');
            $table->string('label')->nullable();
            $table->string('description', 512)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
            $table->index('group');
        });

        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['DAILY', 'WEEKLY', 'MANUAL'])->default('DAILY');
            $table->string('filename');
            $table->string('path', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('status', ['RUNNING', 'SUCCESS', 'FAILED'])->default('RUNNING');
            $table->text('message')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
        Schema::dropIfExists('settings');
    }
};
