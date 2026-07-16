<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('question_type', 30);
            $table->unsignedInteger('order_index');
            $table->decimal('points', 8, 2)->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['test_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_questions');
    }
};
