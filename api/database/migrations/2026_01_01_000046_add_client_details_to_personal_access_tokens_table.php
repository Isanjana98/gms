<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each session was signed in from.
 *
 * Without this, "you have 4 active sessions" is not information anyone can act
 * on — the whole point of showing someone their sessions is so they can spot
 * the one they do not recognise, and a name like "web" does not let them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
