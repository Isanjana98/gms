<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member quotes shown on the public website.
 *
 * Not linked to the members table by a foreign key, deliberately. A quote is
 * published with someone's consent at a moment in time; it must not silently
 * vanish because that member later left, nor silently change because their
 * record was edited. The name here is the name they agreed to be quoted under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // "Member since 2019", "Marathon runner" — optional context that
            // makes a quote land.
            $table->string('role')->nullable();
            $table->text('quote');
            $table->string('photo_path', 512)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
