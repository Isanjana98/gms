<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gym's own logo and sign-in imagery.
 *
 * On the public disk, unlike member documents: these are meant to be seen by
 * anyone who reaches the sign-in screen, which is by definition someone who
 * has not authenticated yet. There is nothing here to protect.
 *
 * A table rather than a settings row because a gym may want several sign-in
 * images in rotation, each with its own ordering and an on/off switch —
 * awkward to express as a single value and awkward to edit as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $table) {
            $table->id();

            // LOGO replaces the "G" mark; LOGIN_BACKGROUND is the sign-in
            // panel. Kept as one table so uploads share a single code path.
            $table->enum('type', ['LOGO', 'LOGIN_BACKGROUND'])->default('LOGIN_BACKGROUND');

            $table->string('path', 512);
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
