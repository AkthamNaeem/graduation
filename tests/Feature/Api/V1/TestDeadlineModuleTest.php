<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test as RecruitmentTest;
use App\Models\TestAnswer;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestDeadlineModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        CarbonImmutable::setTestNow('2026-07-20T12:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_test_assignment_creation_supports_null_and_future_deadlines_in_utc(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario();

        $without = $this->assign($employer, $application, $test);
        $this->withToken($this->tokenFor($candidate))->getJson('/api/v1/my/tests')
            ->assertOk()
            ->assertJsonPath('data.data.0.deadline_at', null)
            ->assertJsonPath('data.data.0.has_deadline', false)
            ->assertJsonPath('data.data.0.is_expired', false)
            ->assertJsonMissingPath('data.data.0.extension_count');

        [$employer2, $candidate2, $application2, $test2] = $this->scenario('two');
        $assignment = $this->assign($employer2, $application2, $test2, '2026-07-21T15:00:00+03:00');

        $this->assertSame('2026-07-21T12:00:00.000000Z', $assignment->deadline_at->toISOString());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test_assignment.deadline_set',
            'entity_id' => $assignment->id,
        ]);
        $this->assertDatabaseMissing('application_test_assignment_deadline_changes', [
            'application_test_assignment_id' => $assignment->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate2->id,
            'type' => 'test.assigned',
        ]);

        [$employer3, , $application3, $test3] = $this->scenario('three');
        $this->withToken($this->tokenFor($employer3))
            ->postJson("/api/v1/applications/{$application3->id}/assign-test", [
                'test_id' => $test3->id,
                'deadline_at' => '2026-07-20T11:59:59Z',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deadline_at');
    }

    public function test_start_obeys_exact_deadline_boundary_and_null_deadline(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario();
        $assignment = $this->assign($employer, $application, $test, '2026-07-20T13:00:00Z');

        CarbonImmutable::setTestNow('2026-07-20T13:00:00Z');
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()
            ->assertJsonPath('data.is_expired', false);

        [$employer2, $candidate2, $application2, $test2] = $this->scenario('expired');
        CarbonImmutable::setTestNow('2026-07-20T12:00:00Z');
        $expired = $this->assign($employer2, $application2, $test2, '2026-07-20T13:00:00Z');
        CarbonImmutable::setTestNow('2026-07-20T13:00:01Z');
        $this->withToken($this->tokenFor($candidate2))
            ->postJson("/api/v1/tests/{$expired->id}/start")
            ->assertConflict()
            ->assertJsonPath('message', 'This test assignment has expired and can no longer be started.');
        $this->assertDatabaseMissing('test_attempts', ['application_test_assignment_id' => $expired->id]);

        [$employer3, $candidate3, $application3, $test3] = $this->scenario('none');
        $noDeadline = $this->assign($employer3, $application3, $test3);
        CarbonImmutable::setTestNow('2036-07-20T13:00:01Z');
        $this->withToken($this->tokenFor($candidate3))
            ->postJson("/api/v1/tests/{$noDeadline->id}/start")
            ->assertCreated();
    }

    public function test_all_answer_mutations_are_blocked_after_expiry_without_orphan_files(): void
    {
        Storage::fake('local');
        [$employer, $candidate, $application, $test] = $this->scenario();
        $text = $this->question($test, 'short_text', 1);
        $file = $this->question($test, 'file_upload', 2);
        $assignment = $this->assign($employer, $application, $test, '2026-07-20T13:00:00Z');
        $attempt = $this->start($candidate, $assignment);
        CarbonImmutable::setTestNow('2026-07-20T13:00:00Z');
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$attempt->id}/answers/{$text->id}", ['answer_text' => 'Original answer'])
            ->assertOk();
        $oldPath = "test-answers/{$attempt->id}/old.pdf";
        Storage::disk('local')->put($oldPath, 'old file');
        TestAnswer::create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $file->id,
            'file_path' => $oldPath,
            'file_disk' => 'local',
            'file_original_name' => 'old.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size' => 8,
        ]);

        CarbonImmutable::setTestNow('2026-07-20T13:00:01Z');
        $url = "/api/v1/test-attempts/{$attempt->id}/answers/{$text->id}";
        $this->withToken($this->tokenFor($candidate))->putJson($url, ['answer_text' => 'Changed'])
            ->assertConflict()
            ->assertJsonPath('message', 'The allowed time for this test attempt has expired.')
            ->assertJsonPath('code', 'TEST_ATTEMPT_TIME_EXPIRED');
        $this->withToken($this->tokenFor($candidate))->deleteJson($url)->assertConflict();
        $this->withToken($this->tokenFor($candidate))->postJson("/api/v1/test-attempts/{$attempt->id}/answers/bulk", [
            'answers' => [['question_id' => $text->id, 'answer_text' => 'Bulk changed']],
        ])->assertConflict();
        $this->withToken($this->tokenFor($candidate))->post(
            "/api/v1/test-attempts/{$attempt->id}/answers/{$file->id}/file",
            ['answer_file' => UploadedFile::fake()->create('solution.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertConflict();

        $this->assertDatabaseHas('test_answers', ['test_question_id' => $text->id, 'answer_text' => 'Original answer']);
        $this->assertDatabaseHas('test_answers', ['test_question_id' => $file->id, 'file_path' => $oldPath]);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles("test-answers/{$attempt->id}"));
    }

    public function test_submit_obeys_boundary_and_expired_submit_is_fully_atomic(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario();
        $assignment = $this->assign($employer, $application, $test, '2026-07-20T13:00:00Z');
        $attempt = $this->start($candidate, $assignment);
        CarbonImmutable::setTestNow('2026-07-20T13:00:00Z');
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertOk();
        $this->assertNotNull($attempt->refresh()->submitted_at);

        [$employer2, $candidate2, $application2, $test2] = $this->scenario('late');
        CarbonImmutable::setTestNow('2026-07-20T12:00:00Z');
        $lateAssignment = $this->assign($employer2, $application2, $test2, '2026-07-20T13:00:00Z');
        $lateAttempt = $this->start($candidate2, $lateAssignment);
        $historyBefore = $application2->statusHistory()->count();
        $notificationsBefore = $candidate2->notifications()->count();
        CarbonImmutable::setTestNow('2026-07-20T13:00:01Z');

        $this->withToken($this->tokenFor($candidate2))
            ->postJson("/api/v1/tests/{$lateAssignment->id}/submit", ['confirm' => true])
            ->assertConflict()
            ->assertJsonPath('message', 'The allowed time for this test attempt has expired.')
            ->assertJsonPath('code', 'TEST_ATTEMPT_TIME_EXPIRED');

        $this->assertNull($lateAttempt->refresh()->submitted_at);
        $this->assertSame(0, $lateAttempt->testAnswers()->whereHas('grading')->count());
        $this->assertSame($historyBefore, $application2->statusHistory()->count());
        $this->assertSame($notificationsBefore, $candidate2->notifications()->count());
        $this->assertSame('test_pending', $application2->refresh()->applicationStatus->slug);
    }

    public function test_owner_and_admin_can_extend_with_history_audit_and_one_safe_notification(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario();
        $assignment = $this->assign($employer, $application, $test, '2026-07-20T13:00:00Z');
        CarbonImmutable::setTestNow('2026-07-20T14:00:00Z');

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", [
                'deadline_at' => '2026-07-21T14:00:00Z',
                'reason' => '  Internal technical issue.  ',
            ])
            ->assertOk()
            ->assertJsonPath('data.deadline_at', '2026-07-21T14:00:00.000000Z')
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.extension_count', 1);

        $this->assertDatabaseHas('application_test_assignment_deadline_changes', [
            'application_test_assignment_id' => $assignment->id,
            'changed_by_user_id' => $employer->id,
            'reason' => 'Internal technical issue.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test_assignment.deadline_extended',
            'entity_id' => $assignment->id,
            'actor_user_id' => $employer->id,
        ]);
        $this->assertSame(1, $candidate->notifications()->where('type', 'test.deadline_extended')->count());

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/test-assignments/{$assignment->id}/deadline-history")
            ->assertOk()
            ->assertJsonPath('data.0.previous_deadline_at', '2026-07-20T13:00:00.000000Z')
            ->assertJsonPath('data.0.new_deadline_at', '2026-07-21T14:00:00.000000Z')
            ->assertJsonPath('data.0.changed_by.id', $employer->id)
            ->assertJsonPath('data.0.reason', 'Internal technical issue.');

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", [
                'deadline_at' => '2026-07-22T14:00:00Z',
            ])->assertOk();
        $this->assertDatabaseHas('application_test_assignment_deadline_changes', [
            'application_test_assignment_id' => $assignment->id,
            'previous_deadline_at' => '2026-07-21 14:00:00',
            'new_deadline_at' => '2026-07-22 14:00:00',
            'changed_by_user_id' => $admin->id,
        ]);
    }

    public function test_extension_rules_authorization_null_deadline_and_reactivation_are_enforced(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario();
        $question = $this->question($test, 'short_text', 1);
        $assignment = $this->assign($employer, $application, $test);
        $otherEmployer = $this->employer(Company::create(['name' => 'Other', 'approval_status' => 'approved']), 'other@example.com');

        $this->withToken($this->tokenFor($candidate))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-21T12:00:00Z'])
            ->assertForbidden();
        $this->withToken($this->tokenFor($candidate))
            ->getJson("/api/v1/test-assignments/{$assignment->id}/deadline-history")
            ->assertForbidden();
        $this->withToken($this->tokenFor($otherEmployer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-21T12:00:00Z'])
            ->assertForbidden();
        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-21T12:00:00Z'])
            ->assertOk();
        $this->assertDatabaseHas('application_test_assignment_deadline_changes', [
            'application_test_assignment_id' => $assignment->id,
            'previous_deadline_at' => null,
        ]);

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-21T11:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');

        CarbonImmutable::setTestNow('2026-07-22T12:00:00Z');
        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-23T12:00:00Z'])
            ->assertOk();
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated();
        $attempt = TestAttempt::where('application_test_assignment_id', $assignment->id)->firstOrFail();
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}", ['answer_text' => 'Saved after extension'])
            ->assertOk();

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertOk();
        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", ['deadline_at' => '2026-07-24T12:00:00Z'])
            ->assertConflict()
            ->assertJsonPath('message', 'A submitted test assignment can no longer be extended.');
    }

    public function test_final_application_assignments_cannot_be_extended(): void
    {
        foreach (['accepted', 'rejected', 'withdrawn'] as $index => $status) {
            [$employer, , $application, $test] = $this->scenario('final-'.$index);
            $assignment = $this->assign($employer, $application, $test, '2026-07-21T12:00:00Z');
            $application->forceFill([
                'application_status_id' => ApplicationStatus::where('slug', $status)->value('id'),
            ])->save();

            $this->withToken($this->tokenFor($employer))
                ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", [
                    'deadline_at' => '2026-07-22T12:00:00Z',
                ])->assertConflict();
        }
    }

    public function test_duration_deadline_is_snapshotted_and_repeated_start_is_idempotent(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario('duration-snapshot');
        $assignment = $this->assign($employer, $application, $test);

        $first = $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()
            ->assertJsonPath('data.duration_deadline_at', '2026-07-20T13:00:00.000000Z')
            ->assertJsonPath('data.effective_deadline_at', '2026-07-20T13:00:00.000000Z')
            ->assertJsonPath('data.remaining_seconds', 3600)
            ->assertJsonPath('data.is_time_expired', false)
            ->json('data');

        CarbonImmutable::setTestNow('2026-07-20T12:30:00Z');
        $second = $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['started_at'], $second['started_at']);
        $this->assertSame($first['effective_deadline_at'], $second['effective_deadline_at']);
        $this->assertDatabaseCount('test_attempts', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_attempt.started', 'entity_id' => $first['id']]);
    }

    public function test_duration_boundary_allows_mutation_and_submit_only_through_exact_deadline(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario('duration-boundary');
        $question = $this->question($test, 'short_text', 1);
        $assignment = $this->assign($employer, $application, $test);
        $attempt = $this->start($candidate, $assignment);

        CarbonImmutable::setTestNow('2026-07-20T13:00:00Z');
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}", ['answer_text' => 'At boundary'])
            ->assertOk();
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertOk();

        [$employer2, $candidate2, $application2, $test2] = $this->scenario('duration-after');
        CarbonImmutable::setTestNow('2026-07-20T12:00:00Z');
        $question2 = $this->question($test2, 'short_text', 1);
        $assignment2 = $this->assign($employer2, $application2, $test2);
        $attempt2 = $this->start($candidate2, $assignment2);
        CarbonImmutable::setTestNow('2026-07-20T13:00:01Z');

        $this->withToken($this->tokenFor($candidate2))
            ->putJson("/api/v1/test-attempts/{$attempt2->id}/answers/{$question2->id}", ['answer_text' => 'Too late'])
            ->assertConflict()
            ->assertJsonPath('code', 'TEST_ATTEMPT_TIME_EXPIRED');
        $this->withToken($this->tokenFor($candidate2))
            ->postJson("/api/v1/tests/{$assignment2->id}/submit", ['confirm' => true])
            ->assertConflict()
            ->assertJsonPath('code', 'TEST_ATTEMPT_TIME_EXPIRED');
        $this->assertNull($attempt2->refresh()->submitted_at);
    }

    public function test_assignment_extension_recalculates_but_never_exceeds_duration_deadline(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario('extension-cap');
        $assignment = $this->assign($employer, $application, $test, '2026-07-20T12:30:00Z');
        $attempt = $this->start($candidate, $assignment);
        $this->assertSame('2026-07-20T12:30:00.000000Z', $attempt->refresh()->effective_deadline_at->toISOString());

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/deadline", [
                'deadline_at' => '2026-07-20T14:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.effective_deadline_at', '2026-07-20T13:00:00.000000Z');

        $this->assertSame('2026-07-20T13:00:00.000000Z', $attempt->refresh()->effective_deadline_at->toISOString());
        $this->assertSame('2026-07-20T12:00:00.000000Z', $attempt->started_at->toISOString());
    }

    public function test_legacy_unsubmitted_attempt_snapshots_on_first_mutation_without_free_time(): void
    {
        [$employer, $candidate, $application, $test] = $this->scenario('legacy');
        $question = $this->question($test, 'short_text', 1);
        $assignment = $this->assign($employer, $application, $test);
        $attempt = $this->start($candidate, $assignment);
        $attempt->forceFill(['effective_deadline_at' => null])->save();

        CarbonImmutable::setTestNow('2026-07-20T12:30:00Z');
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}", ['answer_text' => 'Legacy save'])
            ->assertOk();

        $this->assertSame('2026-07-20T13:00:00.000000Z', $attempt->refresh()->effective_deadline_at->toISOString());
        $this->assertSame('2026-07-20T12:00:00.000000Z', $attempt->started_at->toISOString());
    }

    /** @return array{User, User, JobApplication, RecruitmentTest} */
    private function scenario(string $suffix = 'one'): array
    {
        $company = Company::create(['name' => "Company {$suffix}", 'approval_status' => 'approved']);
        $employer = $this->employer($company, "employer-{$suffix}@example.com");
        $candidate = User::factory()->create(['email' => "candidate-{$suffix}@example.com", 'role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => "Job {$suffix}",
            'description' => 'Description',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id'),
        ])->load(['jobPosting', 'jobSeekerProfile.user', 'applicationStatus']);
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => "Test {$suffix}",
            'duration_minutes' => 60,
            'max_score' => 10,
            'passing_score' => 5,
            'is_active' => true,
        ]);

        return [$employer, $candidate->load('jobSeekerProfile'), $application, $test];
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile');
    }

    private function assign(User $employer, JobApplication $application, RecruitmentTest $test, ?string $deadline = null): ApplicationTestAssignment
    {
        $payload = ['test_id' => $test->id];
        if ($deadline !== null) {
            $payload['deadline_at'] = $deadline;
        }
        $id = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", $payload)
            ->assertCreated()->json('data.id');

        return ApplicationTestAssignment::with(['jobApplication.jobSeekerProfile.user', 'jobApplication.applicationStatus', 'assignedBy', 'test', 'testAttempt'])
            ->findOrFail($id);
    }

    private function start(User $candidate, ApplicationTestAssignment $assignment): TestAttempt
    {
        $id = $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()->json('data.id');

        return TestAttempt::findOrFail($id);
    }

    private function question(RecruitmentTest $test, string $type, int $order): TestQuestion
    {
        return TestQuestion::create([
            'test_id' => $test->id,
            'question_text' => "Question {$order}",
            'question_type' => $type,
            'order_index' => $order,
            'points' => 5,
            'is_required' => false,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
