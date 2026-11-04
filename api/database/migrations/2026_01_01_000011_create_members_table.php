<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4 - Member master record.
 *
 * `current_membership_id` is a denormalised pointer maintained by
 * MembershipService. Attendance validation (SRS 10) runs on every fingerprint
 * scan and must not pay for a sort over the whole memberships table, so the
 * active membership is cached here. The FK is added in a later migration
 * because memberships.member_id points back at this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();

            $table->string('membership_number', 30)->unique();
            $table->string('full_name');
            $table->string('nic_passport', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['MALE', 'FEMALE', 'OTHER'])->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number', 30)->nullable();
            $table->date('join_date');
            $table->string('photo_path')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'EXPIRED', 'BLACKLISTED'])
                ->default('ACTIVE');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('current_membership_id')->nullable();
            $table->date('membership_expires_on')->nullable();
            $table->timestamp('last_visit_at')->nullable();
            $table->unsignedInteger('total_visits')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index('membership_expires_on');
            $table->index('nic_passport');
            $table->index('telephone');
            // Name search from the reception desk.
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
