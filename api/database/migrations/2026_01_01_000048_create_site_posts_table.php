<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** News and articles on the public website. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_posts', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            // The URL. Unique because two posts sharing one would make a
            // published article unreachable, and it is generated from the
            // title where the author does not supply one.
            $table->string('slug', 200)->unique();

            $table->string('excerpt', 500)->nullable();
            $table->longText('body');
            $table->string('image_path', 512)->nullable();

            // Separate from published_at so a post can be scheduled, and
            // unpublishing does not lose the date it originally went out.
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The public index reads exactly this: published, newest first.
            $table->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_posts');
    }
};
