<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more kinds of image: the website hero and the public gallery.
 *
 * The same table rather than a new one, because these are the same thing as
 * the sign-in imagery in every way that matters — a public file with a
 * caption, an order and an on/off switch — and one upload path is easier to
 * keep correct than three.
 *
 * A raw statement rather than ->change(): altering an enum in place needs the
 * full column definition restated, and doing it through the schema builder on
 * MariaDB drops the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE brand_assets MODIFY COLUMN type
             ENUM('LOGO','LOGIN_BACKGROUND','HERO','GALLERY')
             NOT NULL DEFAULT 'LOGIN_BACKGROUND'"
        );
    }

    public function down(): void
    {
        // Anything using the new types would violate the narrower enum, so it
        // goes first. Rolling back a website is meant to remove the website.
        DB::table('brand_assets')->whereIn('type', ['HERO', 'GALLERY'])->delete();

        DB::statement(
            "ALTER TABLE brand_assets MODIFY COLUMN type
             ENUM('LOGO','LOGIN_BACKGROUND')
             NOT NULL DEFAULT 'LOGIN_BACKGROUND'"
        );
    }
};
