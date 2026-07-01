<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cv_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_seeker_profile_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->string('suggestion_type', 30);
            $table->string('status', 30)->default('pending');
            $table->string('source', 50)->default('cv_parsed');
            $table->json('old_value')->nullable();
            $table->json('new_value');
            $table->json('user_edited_value')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['cv_file_id', 'status']);
            $table->index(['job_seeker_profile_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_suggestions');
    }
};
