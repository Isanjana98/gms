<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gym's own questions on the joining form.
 *
 * These sit *below* the PAR-Q, never among it. The seven PAR-Q questions are
 * a standard instrument whose wording is what makes an answer comparable to
 * any other gym's and recognisable to a doctor; they stay in code. What a gym
 * legitimately wants to ask on top of that - current injuries, training
 * goals, how they heard about the place - is its own business and belongs
 * here.
 *
 * Answers are written onto the registration with the question's wording
 * attached, so editing a question here can never change what somebody who
 * already signed appears to have been asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_questions', function (Blueprint $table) {
            $table->id();

            // Null means every branch. A single-site gym never touches this.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            /*
             * The stable identifier an answer is filed under, and the reason
             * answers survive renaming. Set once when the question is created
             * and never editable afterwards - changing it would orphan every
             * answer already given.
             */
            $table->string('key', 50);

            $table->string('label');
            $table->string('help')->nullable();

            /*
             * BOOLEAN carries an optional detail box, the way a PAR-Q "yes"
             * does. CHOICE needs `options`. The rest are free text.
             */
            $table->enum('type', ['BOOLEAN', 'TEXT', 'TEXTAREA', 'CHOICE'])->default('TEXT');
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            /*
             * Retired rather than deleted. A question that has been answered
             * by a hundred people should stop appearing on the form without
             * taking the meaning of those answers with it.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Unique per branch, so two branches can each ask "goal" without
            // colliding, and one branch cannot ask it twice.
            $table->unique(['branch_id', 'key']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_questions');
    }
};
