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
        Schema::create('interview_evaluation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('criterion');
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_evaluation_items');
    }
};
