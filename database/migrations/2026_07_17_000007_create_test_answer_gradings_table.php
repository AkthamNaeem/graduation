<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_answer_gradings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_answer_id')->constrained()->cascadeOnDelete();
            $table->string('grading_type', 20);
            $table->boolean('is_correct')->nullable();
            $table->decimal('awarded_points', 10, 2)->nullable();
            $table->decimal('max_points', 10, 2);
            $table->text('explanation')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at');
            $table->timestamps();

            $table->unique('test_answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_answer_gradings');
    }
};
