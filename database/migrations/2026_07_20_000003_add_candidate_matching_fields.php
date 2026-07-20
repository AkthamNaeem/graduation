<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_posting_skills', function (Blueprint $table): void {
            $table->unsignedTinyInteger('weight')->default(1)->after('requirement_type');
            $table->index(['job_posting_id', 'requirement_type'], 'job_skills_job_type_idx');
        });

        DB::table('job_posting_skills')
            ->where('requirement_type', 'optional')
            ->update(['requirement_type' => 'nice_to_have']);

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->string('education_level')->nullable()->after('experience_level');
        });
    }

    public function down(): void
    {
        DB::table('job_posting_skills')
            ->where('requirement_type', 'nice_to_have')
            ->update(['requirement_type' => 'optional']);

        Schema::table('job_posting_skills', function (Blueprint $table): void {
            $table->dropIndex('job_skills_job_type_idx');
            $table->dropColumn('weight');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropColumn('education_level');
        });
    }
};
