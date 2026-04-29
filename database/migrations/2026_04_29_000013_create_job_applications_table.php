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
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_seeker_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_status_id')->constrained('application_statuses')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['job_posting_id', 'job_seeker_profile_id']);
            $table->index('application_status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
