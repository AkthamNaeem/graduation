<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_question_id')->constrained()->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_original_name')->nullable();
            $table->string('file_mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->unique(['test_attempt_id', 'test_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_answers');
    }
};
