<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_answer_options', function (Blueprint $table): void {
            $table->foreignId('test_answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_option_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(['test_answer_id', 'test_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_answer_options');
    }
};
