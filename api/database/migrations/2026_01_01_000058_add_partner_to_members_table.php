<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the other half of a couple package is.
 *
 * A couple membership is sold once and used by two people, and each of them
 * needs their own member record so each can enrol a fingerprint. Nothing
 * connected the two, so reception could see that a price had been halved but
 * not who it was shared with.
 *
 * The link lives on the member rather than the membership. A couple package
 * is strictly a property of the purchase - renew separately next year and the
 * pair no longer exists - but reception looks people up, not purchases, and
 * the question they ask is "who is this person here with".
 *
 * nullOnDelete, never cascade: removing one member must clear the other's
 * link, not take the other member with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('partner_member_id')
                ->nullable()
                ->after('trainer_id')
                ->constrained('members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_member_id');
        });
    }
};
