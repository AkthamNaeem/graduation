<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_internal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_application_id', 'created_at']);
            $table->index('author_user_id');
            $table->index('deleted_at');
        });

        Schema::create('application_internal_note_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_internal_note_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->foreignId('edited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['application_internal_note_id', 'version'], 'application_note_revision_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_internal_note_revisions');
        Schema::dropIfExists('application_internal_notes');
    }
};
