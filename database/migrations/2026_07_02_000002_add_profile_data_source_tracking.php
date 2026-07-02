<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->string('source_type', 30)->default('manual')->after('description');
            $table->foreignId('source_cv_file_id')->nullable()->after('source_type')->constrained('cv_files')->nullOnDelete();
            $table->timestamp('user_verified_at')->nullable()->after('source_cv_file_id');
            $table->index(['job_seeker_profile_id', 'source_type'], 'exp_profile_source_idx');
        });

        Schema::table('education', function (Blueprint $table) {
            $table->string('source_type', 30)->default('manual')->after('description');
            $table->foreignId('source_cv_file_id')->nullable()->after('source_type')->constrained('cv_files')->nullOnDelete();
            $table->timestamp('user_verified_at')->nullable()->after('source_cv_file_id');
            $table->index(['job_seeker_profile_id', 'source_type'], 'edu_profile_source_idx');
        });

        Schema::table('job_seeker_skills', function (Blueprint $table) {
            $table->string('source_type', 30)->default('manual')->after('skill_id');
            $table->foreignId('source_cv_file_id')->nullable()->after('source_type')->constrained('cv_files')->nullOnDelete();
            $table->timestamp('user_verified_at')->nullable()->after('source_cv_file_id');
            $table->index(['job_seeker_profile_id', 'source_type'], 'jss_profile_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('job_seeker_skills', function (Blueprint $table) {
            $table->dropIndex('jss_profile_source_idx');
            $table->dropConstrainedForeignId('source_cv_file_id');
            $table->dropColumn(['source_type', 'user_verified_at']);
        });

        Schema::table('education', function (Blueprint $table) {
            $table->dropIndex('edu_profile_source_idx');
            $table->dropConstrainedForeignId('source_cv_file_id');
            $table->dropColumn(['source_type', 'user_verified_at']);
        });

        Schema::table('experiences', function (Blueprint $table) {
            $table->dropIndex('exp_profile_source_idx');
            $table->dropConstrainedForeignId('source_cv_file_id');
            $table->dropColumn(['source_type', 'user_verified_at']);
        });
    }
};
