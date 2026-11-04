<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we last told the reader about this user's access.
 *
 * Not the same question as `is_active`, which is ours: whether this mapping is
 * still in use at all. This one records the flag we pushed to the hardware, so
 * the nightly sweep can tell the difference between "this member should be let
 * in" and "the device has already been told". Without it every run would
 * rewrite every user record on the terminal to say what it already said.
 *
 * Defaults to true because that is what the device was sent before this
 * column existed - the driver hardcoded `Valid.enable` on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_users', function (Blueprint $table) {
            $table->boolean('access_enabled')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_users', function (Blueprint $table) {
            $table->dropColumn('access_enabled');
        });
    }
};
