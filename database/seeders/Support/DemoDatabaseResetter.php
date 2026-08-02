<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DemoDatabaseResetter
{
    /**
     * Child tables are intentionally listed before their parents. The explicit
     * inventory keeps the reset scoped to application data and leaves the
     * migrations and cache tables intact.
     *
     * @var list<string>
     */
    private const TABLES = [
        'event_side_effect_executions',
        'personal_access_tokens',
        'push_deliveries',
        'device_tokens',
        'notifications',
        'audit_logs',
        'recommendation_items',
        'recommendation_runs',
        'application_information_response_attachments',
        'application_information_responses',
        'application_information_request_items',
        'application_information_requests',
        'application_internal_note_revisions',
        'application_internal_notes',
        'interview_evaluation_items',
        'interview_evaluations',
        'interview_schedule_changes',
        'interview_status_histories',
        'interviews',
        'test_answer_options',
        'test_answer_gradings',
        'test_answers',
        'test_attempts',
        'application_test_assignment_deadline_changes',
        'application_test_assignments',
        'test_options',
        'test_questions',
        'tests',
        'job_application_screening_answer_options',
        'job_application_screening_answers',
        'job_application_screening_question_options',
        'job_application_screening_questions',
        'application_snapshots',
        'application_status_histories',
        'job_applications',
        'job_screening_question_options',
        'job_screening_questions',
        'job_posting_skills',
        'job_postings',
        'profile_change_suggestions',
        'job_seeker_skills',
        'education',
        'experiences',
        'cv_parsing_results',
        'job_seeker_profiles',
        'cv_files',
        'company_invitations',
        'employer_profiles',
        'companies',
        'skills',
        'cities',
        'application_statuses',
        'email_verification_otps',
        'password_reset_otps',
        'password_reset_tokens',
        'sessions',
        'jobs',
        'failed_jobs',
        'job_batches',
        'users',
    ];

    public function reset(): void
    {
        $this->assertSafeEnvironment();
        Storage::disk('local')->deleteDirectory('demo-seed');

        Schema::disableForeignKeyConstraints();

        try {
            if (Schema::hasTable('application_test_assignments')) {
                DB::table('application_test_assignments')->update([
                    'series_root_assignment_id' => null,
                    'previous_assignment_id' => null,
                ]);
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->delete();
                $this->resetIdentity($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function assertSafeEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo database seeding is disabled in production.');
        }
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return self::TABLES;
    }

    private function resetIdentity(string $table): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement(sprintf(
                'ALTER TABLE `%s` AUTO_INCREMENT = 1',
                str_replace('`', '``', $table),
            )),
            'pgsql' => DB::statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), 1, false)",
                [$table],
            ),
            'sqlite' => DB::table('sqlite_sequence')->where('name', $table)->delete(),
            default => null,
        };
    }
}
