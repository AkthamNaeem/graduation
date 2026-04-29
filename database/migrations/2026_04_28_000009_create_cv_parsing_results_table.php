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
        Schema::create('cv_parsing_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cv_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('raw_text');
            $table->json('parsed_json');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_parsing_results');
    }
};
