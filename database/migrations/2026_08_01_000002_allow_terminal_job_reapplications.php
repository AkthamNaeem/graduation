<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'job_applications_job_posting_id_job_seeker_profile_id_unique';

    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_NAME);
            $table->index(
                ['job_posting_id', 'job_seeker_profile_id', 'application_status_id'],
                'job_applications_duplicate_guard_lookup',
            );
        });
    }

    public function down(): void
    {
        $hasHistoricalDuplicates = DB::table('job_applications')
            ->select(['job_posting_id', 'job_seeker_profile_id'])
            ->groupBy(['job_posting_id', 'job_seeker_profile_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropIndex('job_applications_duplicate_guard_lookup');
        });

        if (! $hasHistoricalDuplicates) {
            Schema::table('job_applications', function (Blueprint $table): void {
                $table->unique(['job_posting_id', 'job_seeker_profile_id'], self::UNIQUE_NAME);
            });
        }
    }
};
