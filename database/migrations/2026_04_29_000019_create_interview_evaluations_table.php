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
        Schema::create('interview_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('evaluated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('recommendation');
            $table->text('overall_comment')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_evaluations');
    }
};
