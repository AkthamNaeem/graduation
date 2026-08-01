<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_files', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('archived_at');
            $table->timestamp('comparison_profile_updated_at')->nullable()->after('review_status');
            $table->string('comparison_profile_hash', 64)->nullable()->after('comparison_profile_updated_at');
            $table->index(
                ['user_id', 'confirmed_at', 'cancelled_at', 'archived_at'],
                'cv_active_workflow_idx',
            );
        });

        Schema::table('cv_parsing_results', function (Blueprint $table): void {
            $table->json('comparison_base_json')->nullable()->after('reviewed_json');
            $table->json('system_generated_review_json')->nullable()->after('comparison_base_json');
            $table->json('final_approved_json')->nullable()->after('system_generated_review_json');
        });
    }

    public function down(): void
    {
        Schema::table('cv_parsing_results', function (Blueprint $table): void {
            $table->dropColumn(['comparison_base_json', 'system_generated_review_json', 'final_approved_json']);
        });

        Schema::table('cv_files', function (Blueprint $table): void {
            $table->dropIndex('cv_active_workflow_idx');
            $table->dropColumn([
                'cancelled_at',
                'comparison_profile_updated_at',
                'comparison_profile_hash',
            ]);
        });
    }
};
