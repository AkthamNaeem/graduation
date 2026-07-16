<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_question_id')->constrained()->cascadeOnDelete();
            $table->text('option_text');
            $table->unsignedInteger('order_index');
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['test_question_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_options');
    }
};
