<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the website, so none of them live or die in an inbox.
 *
 * A lead is a person who has not joined yet, which is why this is its own
 * table rather than a member with a status: it carries none of the obligations
 * of a member record, and most leads never become one. `converted_member_id`
 * is the join that lets the gym see how many did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('name');
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('message')->nullable();

            // What they asked about, so reception opens the conversation
            // already knowing. Nulled rather than cascaded: retiring a plan
            // must not delete the enquiry it generated.
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();

            // Which page or campaign it came from — the only way to find out
            // whether the website is worth what it costs.
            $table->string('source', 100)->default('WEBSITE');

            $table->enum('status', ['NEW', 'CONTACTED', 'CONVERTED', 'CLOSED'])->default('NEW');
            $table->text('notes')->nullable();

            $table->foreignId('converted_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();

            // Kept for abuse investigation, and never shown on screen.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Reception works the new ones, oldest first.
            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
