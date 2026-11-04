<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes that let a member sign in to the mobile app.
 *
 * Members authenticate with a code sent to the phone number or email already
 * on their record, not with a password. Three reasons, in order of how much
 * they matter here:
 *
 *  - There is no password to lose. A gym member signs in perhaps monthly, and
 *    a forgotten password is a queue at reception.
 *  - It needs no new field on the member record and no enrolment step. Anyone
 *    already in the members table can sign in from the day the app ships.
 *  - A stolen database gives an attacker nothing to crack. These rows hold a
 *    hash of a six-digit code that expires in minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_login_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            // Hashed, never stored in the clear. A six-digit code is only
            // weak because it is short-lived and attempt-limited; leaving it
            // readable in the database would throw away both protections.
            $table->string('code_hash');

            // 'SMS' or 'EMAIL' — which route it was sent by, so the app can
            // tell the member where to look without repeating their number.
            $table->string('channel', 20);

            // Masked for display: "07… …567". Never the full destination.
            $table->string('destination_hint', 60)->nullable();

            /*
             * dateTime, not timestamp.
             *
             * MariaDB gives the first TIMESTAMP column in a table an implicit
             * `DEFAULT current_timestamp() ON UPDATE current_timestamp()`. That
             * silently reset the expiry to "now" on every update to the row —
             * so counting a wrong guess expired the code the member was still
             * typing, and a single mistyped digit meant starting again.
             *
             * DATETIME carries no such behaviour. The same trap bit the
             * attendance table's started_at column; it is worth knowing about
             * whenever a timestamp is written by the application rather than
             * by the database.
             */
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // The verify path reads the newest live code for one member.
            $table->index(['member_id', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_login_codes');
    }
};
