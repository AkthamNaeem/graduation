<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_files', function (Blueprint $table) {
            $table->string('review_mode', 30)->nullable()->after('status');
            $table->string('review_status', 30)->nullable()->after('review_mode');
            $table->index(['user_id', 'review_mode', 'review_status'], 'cv_review_state_idx');
        });

        DB::table('cv_files')->whereNull('review_mode')->update(['review_mode' => 'profile_sync']);
        DB::table('cv_files')->whereNotNull('confirmed_at')->update(['review_status' => 'applied']);
        DB::table('cv_files')->whereNull('confirmed_at')->update(['review_status' => 'comparison_pending']);
    }

    public function down(): void
    {
        Schema::table('cv_files', function (Blueprint $table) {
            $table->dropIndex('cv_review_state_idx');
            $table->dropColumn(['review_mode', 'review_status']);
        });
    }
};
