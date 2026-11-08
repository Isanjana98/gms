<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-registration from a QR code at the desk.
 *
 * A walk-in scans a printed code, fills in their own details, answers the
 * PAR-Q and signs with their finger. What that produces is a *registration*,
 * not a member.
 *
 * The distinction is the whole point of this table. The code is public and
 * printed on a wall, so anyone who sees it can submit - which means nothing
 * submitted through it can be allowed to create a member, a membership
 * number, or anything else the gym counts. A registration sits here until a
 * member of staff looks at it and approves it, and only then does a Member
 * row exist.
 *
 * It deliberately mirrors the columns of `members` and `health_screenings`
 * rather than referencing them: a registration has to be able to hold what
 * somebody typed even when it is wrong, duplicated, or never approved at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_registrations', function (Blueprint $table) {
            $table->id();

            // Which branch's code was scanned. Nullable so a registration
            // survives the branch being removed - the submission is evidence
            // of what somebody signed and should not vanish with it.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ----------------------------------------------- what they typed
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

            // ------------------------------------------------------- the PAR-Q
            // Stored as given, with the questionnaire version alongside it.
            // The wording changes over time and a signed answer only means
            // something next to the question it was an answer to.
            $table->string('parq_version', 10)->nullable();
            $table->json('parq_answers')->nullable();
            $table->unsignedTinyInteger('parq_flag_count')->default(0);

            /*
             * The gym's own extra questions, stored with their wording.
             *
             * Not just {key: answer}. The PAR-Q already showed what goes
             * wrong otherwise: the screening PDF prints a section headed "the
             * questions, as they were asked" from today's wording, so editing
             * a question silently rewrites what every past signatory appears
             * to have been asked. These questions *are* editable from the
             * panel, which makes that failure likely rather than theoretical.
             *
             * So each entry keeps the label as it stood at the moment of
             * signing:
             *   [{key, label, type, answer}]
             */
            $table->json('custom_answers')->nullable();

            // ---------------------------------------------- what was signed
            $table->string('signature_path', 512)->nullable();
            $table->string('signed_name')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->text('declaration')->nullable();

            /*
             * Recorded as evidence of who submitted this and from where, the
             * same way a health screening does. It is also what the public
             * form is rate limited by, so it has to be the real client
             * address - see TRUSTED_PROXIES in .env.
             */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // --------------------------------------------------- the review
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Set on approval. Keeping it means a registration can be traced
            // to the member it became, and that approving twice is visible
            // rather than quietly creating a second member.
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['branch_id', 'status']);
            $table->index('telephone');
            $table->index('nic_passport');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_registrations');
    }
};
