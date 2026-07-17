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
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestRetakeModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        CarbonImmutable::setTestNow('2026-08-01T12:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function testTestAssignmentInitialRetakePolicyHasBoundedLegacySafeDefaults(): void
    {
        [$employer, , $application, $test] = $this->baseScenario('initial');
        $default = $this->assign($employer, $application, $test);
        $this->assertSame(1, $default->attempt_number);
        $this->assertSame(1, $default->max_attempts);
        $this->assertNull($default->series_root_assignment_id);
        $this->assertNull($default->previous_assignment_id);

        [$employer2, , $application2, $test2] = $this->baseScenario('custom');
        $custom = $this->assign($employer2, $application2, $test2, 3);
        $this->assertSame(3, $custom->max_attempts);

        [$employer3, , $application3, $test3] = $this->baseScenario('invalid');
        foreach ([0, 6] as $invalid) {
            $this->withToken($this->tokenFor($employer3))
                ->postJson("/api/v1/applications/{$application3->id}/assign-test", [
                    'test_id' => $test3->id,
                    'max_attempts' => $invalid,
                ])->assertUnprocessable()->assertJsonValidationErrors('max_attempts');
        }
        $this->assertSame(0, ApplicationTestAssignment::where('job_application_id', $application3->id)->count());
    }

    public function test_owner_and_admin_can_only_increase_policy_with_audit_and_no_notification(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('policy');
        $assignment = $this->assign($employer, $application, $test);
        $notifications = $candidate->notifications()->count();

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/retake-policy", [
                'max_attempts' => 2,
                'reason' => ' One additional attempt approved. ',
            ])->assertOk()->assertJsonPath('data.max_attempts', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test_assignment.retake_policy_updated',
            'entity_id' => $assignment->id,
            'actor_user_id' => $employer->id,
        ]);
        $this->assertSame($notifications, $candidate->notifications()->count());

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/retake-policy", ['max_attempts' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('max_attempts');
        $this->withToken($this->tokenFor($candidate))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/retake-policy", ['max_attempts' => 3])
            ->assertForbidden();

        $other = $this->employer(Company::create(['name' => 'Other Policy Co.', 'approval_status' => 'approved']), 'other-policy@example.com');
        $this->withToken($this->tokenFor($other))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/retake-policy", ['max_attempts' => 3])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/test-assignments/{$assignment->id}/retake-policy", ['max_attempts' => 3])
            ->assertOk()->assertJsonPath('data.max_attempts', 3);
    }

    public function test_grant_creates_new_assignment_preserves_result_reopens_workflow_and_sends_one_notification(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('grant');
        $first = $this->assign($employer, $application, $test, 2);
        $firstAttempt = $this->startAndSubmit($candidate, $first);
        $firstResult = $firstAttempt->only(['submitted_at', 'objective_score', 'total_score', 'grading_status']);
        $historyBefore = $application->statusHistory()->count();

        $response = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$first->id}/retake", [
                'deadline_at' => '2026-08-05T18:00:00+03:00',
                'reason' => ' Second attempt approved after review. ',
                'instructions' => ' Retake the same assessment independently. ',
            ])->assertCreated()
            ->assertJsonPath('data.attempt_number', 2)
            ->assertJsonPath('data.max_attempts', 2)
            ->assertJsonPath('data.previous_assignment_id', $first->id)
            ->assertJsonPath('data.deadline_at', '2026-08-05T15:00:00.000000Z');

        $second = ApplicationTestAssignment::findOrFail($response->json('data.id'));
        $this->assertSame($first->id, $second->series_root_assignment_id);
        $this->assertSame($application->id, $second->job_application_id);
        $this->assertSame($test->id, $second->test_id);
        $this->assertSame($employer->id, $second->retake_granted_by_user_id);
        $this->assertSame('Second attempt approved after review.', $second->retake_reason);
        $this->assertSame('Retake the same assessment independently.', $second->note);
        $this->assertNull($second->testAttempt);
        $this->assertEquals($firstResult, $firstAttempt->refresh()->only(array_keys($firstResult)));
        $this->assertSame('test_pending', $application->refresh()->applicationStatus->slug);
        $this->assertSame($historyBefore + 1, $application->statusHistory()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_assignment.retake_granted', 'entity_id' => $second->id]);
        $this->assertSame(1, $candidate->notifications()->where('type', 'test.retake_granted')->count());
        $notification = $candidate->notifications()->where('type', 'test.retake_granted')->firstOrFail();
        $this->assertSame($second->id, $notification->data['assignment_id']);
        $this->assertArrayNotHasKey('reason', $notification->data);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$first->id}/start")
            ->assertConflict()
            ->assertJsonPath('message', 'This test assignment has been superseded by a newer retake assignment.');
    }

    public function test_three_attempt_series_enforces_maximum_and_workflow_cycle(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('series');
        $first = $this->assign($employer, $application, $test, 3);
        $this->startAndSubmit($candidate, $first);
        $second = $this->grant($employer, $first);
        $secondAttempt = $this->startAndSubmit($candidate, $second);
        $this->assertSame('test_completed', $application->refresh()->applicationStatus->slug);
        $third = $this->grant($employer, $second);
        $thirdAttempt = $this->startAndSubmit($candidate, $third);

        $this->assertSame(2, $second->attempt_number);
        $this->assertSame(3, $third->attempt_number);
        $this->assertSame($first->id, $third->series_root_assignment_id);
        $this->assertSame($second->id, $third->previous_assignment_id);
        $this->assertNotSame($secondAttempt->id, $thirdAttempt->id);
        $this->assertSame('test_completed', $application->refresh()->applicationStatus->slug);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$third->id}/retake", [])
            ->assertConflict()
            ->assertJsonPath('message', 'The maximum number of test attempts has been reached.');
        $this->assertSame(3, ApplicationTestAssignment::where('job_application_id', $application->id)->count());
        $this->assertSame(2, $candidate->notifications()->where('type', 'test.retake_granted')->count());
    }

    public function test_grant_authorization_deadline_latest_and_default_limit_are_enforced(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('grant-rules');
        $first = $this->assign($employer, $application, $test, 2);
        $this->startAndSubmit($candidate, $first);
        $otherEmployer = $this->employer(Company::create(['name' => 'Other Grant Co.', 'approval_status' => 'approved']), 'other-grant@example.com');

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/test-assignments/{$first->id}/retake", [])->assertForbidden();
        $this->withToken($this->tokenFor($otherEmployer))
            ->postJson("/api/v1/test-assignments/{$first->id}/retake", [])->assertForbidden();
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$first->id}/retake", ['deadline_at' => '2026-07-31T12:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
        $this->assertSame(1, ApplicationTestAssignment::where('job_application_id', $application->id)->count());

        $second = $this->grant($employer, $first);
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$first->id}/retake", [])
            ->assertConflict()
            ->assertJsonPath('message', 'This assignment is not the latest assignment in the retake series.');

        [$owner2, $seeker2, $application2, $test2] = $this->baseScenario('default-limit');
        $only = $this->assign($owner2, $application2, $test2);
        $this->startAndSubmit($seeker2, $only);
        $this->withToken($this->tokenFor($owner2))
            ->postJson("/api/v1/test-assignments/{$only->id}/retake", [])
            ->assertConflict()
            ->assertJsonPath('message', 'The maximum number of test attempts has been reached.');

        [$owner3, $seeker3, $application3, $test3] = $this->baseScenario('admin-grant');
        $adminSource = $this->assign($owner3, $application3, $test3, 2);
        $this->startAndSubmit($seeker3, $adminSource);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/test-assignments/{$adminSource->id}/retake", [])
            ->assertCreated()
            ->assertJsonPath('data.retake_granted_by_user_id', $admin->id);
        $this->assertSame(2, $second->attempt_number);
    }

    public function test_invalid_assignment_and_application_states_are_rejected(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('active');
        $active = $this->assign($employer, $application, $test, 2, '2026-08-02T12:00:00Z');
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$active->id}/retake", [])
            ->assertConflict()
            ->assertJsonPath('message', 'A retake can only be granted after the current attempt has been submitted.');
        CarbonImmutable::setTestNow('2026-08-03T12:00:00Z');
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/test-assignments/{$active->id}/retake", [])
            ->assertConflict()
            ->assertJsonPath('message', 'An expired unsubmitted assignment should be extended instead of creating a retake.');

        foreach (['interview_pending', 'interview_scheduled', 'interview_completed', 'final_review', 'accepted', 'rejected', 'withdrawn'] as $index => $status) {
            CarbonImmutable::setTestNow('2026-08-01T12:00:00Z');
            [$owner, $seeker, $jobApplication, $catalogTest] = $this->baseScenario('state-'.$index);
            $assignment = $this->assign($owner, $jobApplication, $catalogTest, 2);
            $this->startAndSubmit($seeker, $assignment);
            $jobApplication->forceFill(['application_status_id' => ApplicationStatus::where('slug', $status)->value('id')])->save();
            $this->withToken($this->tokenFor($owner))
                ->postJson("/api/v1/test-assignments/{$assignment->id}/retake", [])
                ->assertConflict()
                ->assertJsonPath('message', 'The application is no longer eligible for a test retake.');
        }
    }

    public function test_answers_files_gradings_results_and_deadline_history_are_isolated(): void
    {
        Storage::fake('local');
        [$employer, $candidate, $application, $test] = $this->baseScenario('isolation');
        $text = $this->question($test, 'short_text', 1);
        $file = $this->question($test, 'file_upload', 2);
        $first = $this->assign($employer, $application, $test, 2, '2026-08-03T12:00:00Z');
        $firstAttempt = $this->start($candidate, $first);
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$firstAttempt->id}/answers/{$text->id}", ['answer_text' => 'First attempt answer'])
            ->assertOk();
        $this->withToken($this->tokenFor($candidate))->post(
            "/api/v1/test-attempts/{$firstAttempt->id}/answers/{$file->id}/file",
            ['answer_file' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertOk();
        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/test-assignments/{$first->id}/deadline", [
                'deadline_at' => '2026-08-04T12:00:00Z',
                'reason' => 'First attempt extension',
            ])->assertOk();
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$first->id}/submit", ['confirm' => true])->assertOk();
        $firstAnswerCount = $firstAttempt->testAnswers()->count();
        $firstGradingCount = $firstAttempt->testAnswers()->whereHas('grading')->count();
        $files = Storage::disk('local')->allFiles("test-answers/{$firstAttempt->id}");

        $second = $this->grant($employer, $first, ['deadline_at' => '2026-08-06T12:00:00Z']);
        $secondAttempt = $this->start($candidate, $second);
        $this->assertSame(0, $secondAttempt->testAnswers()->count());
        $this->assertSame(0, $second->deadlineChanges()->count());
        $this->assertSame($firstAnswerCount, $firstAttempt->testAnswers()->count());
        $this->assertSame($firstGradingCount, $firstAttempt->testAnswers()->whereHas('grading')->count());
        $this->assertSame($files, Storage::disk('local')->allFiles("test-answers/{$firstAttempt->id}"));
        $this->assertSame([], Storage::disk('local')->allFiles("test-answers/{$secondAttempt->id}"));

        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/test-attempts/{$firstAttempt->id}/answers/{$text->id}", ['answer_text' => 'Tampered'])
            ->assertConflict();
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$second->id}/submit", ['confirm' => true])->assertOk();
        $this->assertNotSame($firstAttempt->id, $secondAttempt->refresh()->id);
        $this->assertSame('test_completed', $application->refresh()->applicationStatus->slug);
        $this->assertFalse(Schema::hasColumn('job_applications', 'score'));
    }

    public function test_series_api_is_role_safe_and_rejects_cross_tenant_access(): void
    {
        [$employer, $candidate, $application, $test] = $this->baseScenario('visibility');
        $first = $this->assign($employer, $application, $test, 2);
        $this->startAndSubmit($candidate, $first);
        $second = $this->grant($employer, $first, ['reason' => 'Internal reason']);

        $this->withToken($this->tokenFor($candidate))
            ->getJson("/api/v1/test-assignments/{$second->id}/attempt-series")
            ->assertOk()
            ->assertJsonPath('data.attempts_used', 2)
            ->assertJsonPath('data.attempts_remaining', 0)
            ->assertJsonPath('data.latest_assignment_id', $second->id)
            ->assertJsonMissingPath('data.assignments.1.retake_reason')
            ->assertJsonMissingPath('data.assignments.1.retake_granted_by');
        $this->withToken($this->tokenFor($candidate))
            ->getJson('/api/v1/my/tests')
            ->assertOk()
            ->assertJsonMissingPath('data.data.0.retake_reason')
            ->assertJsonMissingPath('data.data.0.retake_granted_by_user_id')
            ->assertJsonMissingPath('data.data.0.assigned_by_user_id');

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/test-assignments/{$first->id}/attempt-series")
            ->assertOk()
            ->assertJsonPath('data.assignments.1.retake_reason', 'Internal reason')
            ->assertJsonPath('data.assignments.1.retake_granted_by.id', $employer->id);

        $otherCandidate = $this->jobSeeker('other-series@example.com');
        $otherEmployer = $this->employer(Company::create(['name' => 'Other Series Co.', 'approval_status' => 'approved']), 'other-series-employer@example.com');
        $this->withToken($this->tokenFor($otherCandidate))->getJson("/api/v1/test-assignments/{$first->id}/attempt-series")->assertForbidden();
        $this->withToken($this->tokenFor($otherEmployer))->getJson("/api/v1/test-assignments/{$first->id}/attempt-series")->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))->getJson("/api/v1/test-assignments/{$first->id}/attempt-series")->assertOk();
    }

    /** @return array{User, User, JobApplication, RecruitmentTest} */
    private function baseScenario(string $suffix): array
    {
        $company = Company::create(['name' => "Retake {$suffix}", 'approval_status' => 'approved']);
        $employer = $this->employer($company, "retake-{$suffix}@example.com");
        $candidate = $this->jobSeeker("candidate-{$suffix}@example.com");
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => "Retake Job {$suffix}",
            'description' => 'Description',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id'),
        ])->load(['jobPosting', 'jobSeekerProfile.user', 'applicationStatus']);
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => "Retake Test {$suffix}",
            'duration_minutes' => 60,
            'max_score' => 10,
            'passing_score' => 5,
            'is_active' => true,
        ]);

        return [$employer, $candidate, $application, $test];
    }

    private function assign(User $employer, JobApplication $application, RecruitmentTest $test, int $maxAttempts = 1, ?string $deadline = null): ApplicationTestAssignment
    {
        $payload = ['test_id' => $test->id, 'max_attempts' => $maxAttempts];
        if ($deadline !== null) {
            $payload['deadline_at'] = $deadline;
        }
        $id = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", $payload)
            ->assertCreated()->json('data.id');

        return ApplicationTestAssignment::with(['jobApplication.applicationStatus', 'jobApplication.jobSeekerProfile.user', 'testAttempt'])->findOrFail($id);
    }

    private function grant(User $actor, ApplicationTestAssignment $assignment, array $payload = []): ApplicationTestAssignment
    {
        $id = $this->withToken($this->tokenFor($actor))
            ->postJson("/api/v1/test-assignments/{$assignment->id}/retake", $payload)
            ->assertCreated()->json('data.id');

        return ApplicationTestAssignment::with(['jobApplication.applicationStatus', 'jobApplication.jobSeekerProfile.user', 'testAttempt'])->findOrFail($id);
    }

    private function start(User $candidate, ApplicationTestAssignment $assignment): TestAttempt
    {
        $id = $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()->json('data.id');

        return TestAttempt::findOrFail($id);
    }

    private function startAndSubmit(User $candidate, ApplicationTestAssignment $assignment): TestAttempt
    {
        $attempt = $this->start($candidate, $assignment);
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertOk();

        return $attempt->refresh();
    }

    private function question(RecruitmentTest $test, string $type, int $order): TestQuestion
    {
        return TestQuestion::create([
            'test_id' => $test->id,
            'question_text' => "Retake question {$order}",
            'question_type' => $type,
            'order_index' => $order,
            'points' => 5,
            'is_required' => false,
        ]);
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile');
    }

    private function jobSeeker(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        return $user->load('jobSeekerProfile');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
