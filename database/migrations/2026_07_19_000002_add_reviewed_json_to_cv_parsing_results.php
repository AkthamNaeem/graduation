<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_parsing_results', function (Blueprint $table) {
            $table->json('reviewed_json')->nullable()->after('parsed_json');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_json');
        });
    }

    public function down(): void
    {
        Schema::table('cv_parsing_results', function (Blueprint $table) {
            $table->dropColumn(['reviewed_json', 'reviewed_at']);
        });
    }
};
