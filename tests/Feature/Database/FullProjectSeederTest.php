<?php

namespace Tests\Feature\Database;

use App\Data\Recommendation\RecommendationEngine;
use App\Enums\ApplicationInformationRequestStatus;
use App\Enums\CompanyApprovalStatus;
use App\Enums\EducationLevel;
use App\Enums\InterviewAttendanceStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Enums\InterviewType;
use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use App\Enums\ScreeningQuestionType;
use App\Enums\TestAnswerGradingType;
use App\Enums\TestAttemptGradingStatus;
use App\Enums\TestQuestionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationStatus;
use App\Models\JobApplication;
use App\Models\TestAttempt;
use App\Services\ApplicationWorkflowService;
use Database\Seeders\FullProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FullProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const COUNTED_TABLES = [
        'users',
        'companies',
        'job_seeker_profiles',
        'cv_files',
        'job_postings',
        'job_applications',
        'application_status_histories',
        'application_information_requests',
        'application_test_assignments',
        'test_attempts',
        'interviews',
        'notifications',
        'audit_logs',
        'recommendation_runs',
        'recommendation_items',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_full_project_seeder_is_destructive_and_rerunnable_with_stable_counts_and_files(): void
    {
        $this->seed(FullProjectSeeder::class);
        $first = $this->counts();
        $firstFiles = Storage::disk('local')->allFiles('demo-seed');

        DB::table('notifications')->insert([
            'user_id' => DB::table('users')->value('id'),
            'type' => 'stale.demo',
            'title' => 'Stale',
            'message' => 'Must be removed by the next run.',
            'created_at' => now(),
        ]);
        Storage::disk('local')->put('demo-seed/stale.txt', 'stale');

        $this->seed(FullProjectSeeder::class);

        $this->assertSame($first, $this->counts());
        $this->assertSame($firstFiles, Storage::disk('local')->allFiles('demo-seed'));
        $this->assertDatabaseMissing('notifications', ['type' => 'stale.demo']);
        Storage::disk('local')->assertMissing('demo-seed/stale.txt');
    }

    public function test_all_database_backed_enum_cases_and_application_statuses_are_represented(): void
    {
        $this->seed(FullProjectSeeder::class);

        $this->assertValues('users', 'role', UserRole::cases());
        $this->assertValues('users', 'status', UserStatus::cases());
        $this->assertValues('companies', 'approval_status', CompanyApprovalStatus::cases());
        $this->assertValues('job_postings', 'education_level', EducationLevel::cases());
        $this->assertValues('job_postings', 'work_mode', JobWorkMode::cases());
        $this->assertValues('job_posting_skills', 'requirement_type', JobSkillRequirementType::cases());
        $this->assertValues('job_screening_questions', 'question_type', ScreeningQuestionType::cases());
        $this->assertValues('application_information_requests', 'status', ApplicationInformationRequestStatus::cases());
        $this->assertValues('test_questions', 'question_type', TestQuestionType::cases());
        $this->assertValues('test_attempts', 'grading_status', TestAttemptGradingStatus::cases());
        $this->assertValues('test_answer_gradings', 'grading_type', TestAnswerGradingType::cases());
        $this->assertValues('interviews', 'interview_type', InterviewType::cases());
        $this->assertValues('interviews', 'interview_mode', InterviewMode::cases());
        $this->assertValues('interviews', 'status', InterviewStatus::cases());
        $this->assertValuesAcross(
            'interviews',
            ['candidate_attendance_status', 'interviewer_attendance_status'],
            InterviewAttendanceStatus::cases(),
        );
        $this->assertValues('recommendation_runs', 'engine', RecommendationEngine::cases());

        $representedStatuses = JobApplication::query()
            ->join('application_statuses', 'application_statuses.id', '=', 'job_applications.application_status_id')
            ->pluck('application_statuses.slug')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            ApplicationStatus::query()->pluck('slug')->sort()->values()->all(),
            $representedStatuses,
        );
    }

    public function test_application_histories_are_ordered_valid_and_match_current_status(): void
    {
        $this->seed(FullProjectSeeder::class);

        foreach (JobApplication::query()->with(['applicationStatus', 'statusHistory.toStatus', 'statusHistory.fromStatus'])->get() as $application) {
            $history = $application->statusHistory->sortBy(fn ($row) => [$row->created_at?->timestamp, $row->id])->values();

            $this->assertNotEmpty($history);
            $this->assertSame('submitted', $history->first()->toStatus->slug);
            $this->assertNull($history->first()->from_application_status_id);
            $this->assertSame($application->applicationStatus->slug, $history->last()->toStatus->slug);

            foreach ($history as $index => $entry) {
                if ($index === 0) {
                    continue;
                }
                $previous = $history[$index - 1];
                $this->assertTrue($previous->created_at->lessThanOrEqualTo($entry->created_at));
                $this->assertSame($previous->to_application_status_id, $entry->from_application_status_id);
                $this->assertTrue(
                    $this->validApplicationTransition($entry->fromStatus->slug, $entry->toStatus->slug),
                    "{$entry->fromStatus->slug} -> {$entry->toStatus->slug} is invalid",
                );
            }
        }
    }

    public function test_required_relationships_have_no_orphans_and_modules_are_populated(): void
    {
        $this->seed(FullProjectSeeder::class);

        foreach ([
            ['job_applications', 'job_posting_id', 'job_postings'],
            ['job_applications', 'job_seeker_profile_id', 'job_seeker_profiles'],
            ['employer_profiles', 'company_id', 'companies'],
            ['application_test_assignments', 'job_application_id', 'job_applications'],
            ['application_test_assignments', 'test_id', 'tests'],
            ['interviews', 'job_application_id', 'job_applications'],
            ['application_status_histories', 'job_application_id', 'job_applications'],
            ['recommendation_items', 'recommendation_run_id', 'recommendation_runs'],
            ['recommendation_items', 'job_posting_id', 'job_postings'],
        ] as [$child, $foreignKey, $parent]) {
            $orphans = DB::table($child)
                ->leftJoin($parent, "{$parent}.id", '=', "{$child}.{$foreignKey}")
                ->whereNull("{$parent}.id")
                ->count();
            $this->assertSame(0, $orphans, "{$child}.{$foreignKey} has orphan rows");
        }

        foreach ([
            'cv_files',
            'job_postings',
            'job_applications',
            'application_information_requests',
            'tests',
            'interviews',
            'notifications',
            'audit_logs',
            'recommendation_runs',
        ] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} must be populated");
        }
    }

    public function test_score_and_recommendation_invariants_are_consistent(): void
    {
        $this->seed(FullProjectSeeder::class);

        foreach (TestAttempt::query()->get() as $attempt) {
            if ($attempt->max_score !== null) {
                $this->assertGreaterThanOrEqual(0, (float) $attempt->total_score);
                $this->assertLessThanOrEqual((float) $attempt->max_score, (float) ($attempt->total_score ?? 0));
            }
            if ($attempt->percentage !== null) {
                $this->assertGreaterThanOrEqual(0, (float) $attempt->percentage);
                $this->assertLessThanOrEqual(100, (float) $attempt->percentage);
            }
        }

        foreach (DB::table('recommendation_runs')->get() as $run) {
            $this->assertSame(36, strlen($run->request_id));
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/',
                $run->request_id,
            );

            $items = DB::table('recommendation_items')
                ->where('recommendation_run_id', $run->id)
                ->orderBy('rank')
                ->get();
            $this->assertSame((int) $run->returned_count, $items->count());
            $this->assertSame(range(1, $items->count()), $items->pluck('rank')->map(fn ($rank) => (int) $rank)->all());
            foreach ($items as $item) {
                $this->assertGreaterThanOrEqual(0, (float) $item->score);
                $this->assertLessThanOrEqual(100, (float) $item->score);
            }
        }
    }

    public function test_production_guard_rejects_destructive_seeding_before_mutation(): void
    {
        $this->seed(FullProjectSeeder::class);
        $before = $this->counts();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $seeder = app()->make(FullProjectSeeder::class);
            $seeder->setContainer(app());
            $seeder();
            $this->fail('Expected destructive demo seeding to be rejected in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Demo database seeding is disabled in production.', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_foreign_keys_login_roles_and_demo_files_remain_available(): void
    {
        $this->seed(FullProjectSeeder::class);

        foreach (UserRole::cases() as $role) {
            $this->assertDatabaseHas('users', ['role' => $role->value]);
        }
        foreach (DB::table('cv_files')->get(['disk', 'stored_path']) as $file) {
            Storage::disk($file->disk)->assertExists($file->stored_path);
        }
        foreach (DB::table('application_information_response_attachments')->get(['disk', 'stored_path']) as $file) {
            Storage::disk($file->disk)->assertExists($file->stored_path);
        }

        if (DB::getDriverName() === 'sqlite') {
            $enabled = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
            $this->assertSame(1, $enabled);
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect(self::COUNTED_TABLES)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    /** @param array<int, \BackedEnum> $cases */
    private function assertValues(string $table, string $column, array $cases): void
    {
        $actual = DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->sort()->values()->all();
        $expected = collect($cases)->map->value->sort()->values()->all();
        $this->assertSame($expected, $actual, "{$table}.{$column} is not fully covered");
    }

    /** @param list<string> $columns @param array<int, \BackedEnum> $cases */
    private function assertValuesAcross(string $table, array $columns, array $cases): void
    {
        $actual = collect($columns)
            ->flatMap(fn (string $column) => DB::table($table)->pluck($column))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expected = collect($cases)->map->value->sort()->values()->all();
        $this->assertSame($expected, $actual);
    }

    private function validApplicationTransition(string $from, string $to): bool
    {
        $transitions = (new \ReflectionClass(ApplicationWorkflowService::class))
            ->getConstant('VALID_TRANSITIONS');

        return in_array($to, $transitions[$from] ?? [], true);
    }
}
