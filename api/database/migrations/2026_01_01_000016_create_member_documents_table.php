<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed agreements, ID copies and medical clearances held against a member.
 *
 * These are the documents a gym has to produce when something goes wrong - a
 * disputed cancellation, an injury claim - so the table is built for evidence
 * rather than convenience:
 *
 *  - Files live on the private disk and are streamed through an authenticated
 *    endpoint. An agreement carries a signature, an address and often an ID
 *    number; it must not sit on a public URL.
 *  - Replacing a document does not overwrite it. The new row points at the old
 *    one through `replaces_id` and the old one is soft-deleted, so "what did
 *    they actually sign in March?" stays answerable.
 *  - The original filename is kept separately from the stored path, because
 *    the stored name is a generated hash and "agreement.pdf" is what the
 *    member recognises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('replaces_id')->nullable()->constrained('member_documents')->nullOnDelete();

            $table->enum('type', [
                'AGREEMENT',
                'ID_PROOF',
                'MEDICAL',
                'WAIVER',
                'PHOTO_CONSENT',
                'OTHER',
            ])->default('AGREEMENT');

            $table->string('title');
            $table->string('original_filename');
            $table->string('path', 512);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');

            // An agreement is usually for a term, and reception needs to know
            // when a new one is due.
            $table->date('signed_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'type']);
            // Drives an "agreements expiring" view without scanning the table.
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
    }
};
