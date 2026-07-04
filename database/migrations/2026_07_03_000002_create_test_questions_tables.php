<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('question_type', 30);
            $table->decimal('points', 8, 2)->default(1);
            $table->unsignedInteger('order_index')->default(0);
            $table->boolean('is_required')->default(true);
            $table->text('expected_answer')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['test_id', 'order_index'], 'tq_test_order_idx');
            $table->index(['test_id', 'is_active'], 'tq_test_active_idx');
        });

        Schema::create('test_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_question_id')->constrained()->cascadeOnDelete();
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();

            $table->index(['test_question_id', 'order_index'], 'tqo_question_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_question_options');
        Schema::dropIfExists('test_questions');
    }
};
