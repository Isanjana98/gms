<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAR-Q health screening and the signature that goes with it.
 *
 * This table exists to be produced years later, in front of somebody who is
 * disputing what happened. That shapes nearly every column:
 *
 *  - **The answers are stored, not just the outcome.** "They passed" is
 *    useless after an injury; "they answered no to all seven on 3 November,
 *    and here is the wording they were shown" is not.
 *  - **The terms are versioned and hashed.** A member signs a particular form
 *    of words. Editing the gym's waiver text afterwards must not silently
 *    rewrite what three hundred people are recorded as having agreed to, so
 *    the text's version and a hash of it are copied onto the row.
 *  - **The whole record is hashed.** `record_hash` covers the answers, the
 *    terms hash, the signer and the timestamp. It does not make the row
 *    tamper-proof — anything in a database you control can be changed — but
 *    it makes a quiet, partial edit detectable, which is the realistic threat.
 *  - **Nothing is ever overwritten.** A re-screening is a new row pointing at
 *    the old one through `replaces_id`, exactly as member_documents does.
 *
 * The token columns are what let a member complete this on their own phone
 * from a QR at the desk. Only a hash of the token is stored: a leaked database
 * must not hand out working links to other people's health forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_screenings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('replaces_id')->nullable()->constrained('health_screenings')->nullOnDelete();

            $table->string('reference', 30)->unique();

            // ---------------------------------------------------- the link
            //
            // Hashed, never stored raw: the plain token lives only in the QR
            // and the URL the member holds.
            $table->string('token_hash', 64)->nullable()->unique();
            // dateTime, not timestamp: MariaDB gives the first TIMESTAMP column
            // in a table an implicit ON UPDATE current_timestamp(), which would
            // silently extend the expiry every time the row was touched.
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            // ------------------------------------------------- the answers
            $table->enum('status', ['PENDING', 'COMPLETED', 'EXPIRED', 'CANCELLED'])->default('PENDING');

            // {"q1": {"answer": true, "detail": "..."}, ...} — keyed by the
            // question ids in App\Support\ParQ so a reordered questionnaire
            // cannot shift old answers onto different questions.
            $table->json('answers')->nullable();

            // Denormalised so "who needs reviewing" is an index hit rather
            // than a JSON scan across every member who ever joined.
            $table->boolean('has_flags')->default(false);
            $table->unsignedTinyInteger('flag_count')->default(0);

            $table->string('questionnaire_version', 20)->nullable();

            // --------------------------------------------- what was signed
            $table->string('terms_version', 20)->nullable();
            $table->string('terms_hash', 64)->nullable();

            $table->string('signature_path', 512)->nullable();
            $table->string('signed_name')->nullable();
            $table->dateTime('signed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->string('record_hash', 64)->nullable();

            // The rendered PDF, filed against the member like any other
            // document so it appears wherever agreements already appear.
            $table->foreignId('document_id')->nullable()->constrained('member_documents')->nullOnDelete();

            // --------------------------------------------------- the review
            //
            // NOT_REQUIRED when every answer was no. The point of the whole
            // feature is that PENDING cannot be ignored: a "yes" recorded with
            // no response to it is worse than never having asked.
            $table->enum('review_status', [
                'NOT_REQUIRED',
                'PENDING',
                'CLEARED',
                'DOCTOR_NOTE_REQUIRED',
                'RESTRICTED',
            ])->default('NOT_REQUIRED');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'status']);
            // Drives the "health reviews outstanding" queue.
            $table->index(['review_status', 'branch_id']);
            $table->index('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_screenings');
    }
};
