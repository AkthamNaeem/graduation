<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('interview_type');
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('interview_mode');
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->text('note')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_application_id', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
