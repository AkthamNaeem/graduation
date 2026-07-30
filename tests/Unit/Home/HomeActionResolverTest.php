<?php

namespace Tests\Unit\Home;

use App\Enums\UserRole;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Services\Home\HomeActionResolver;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HomeActionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        Carbon::setTestNow('2026-07-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pending_test_has_priority_over_upcoming_interview(): void
    {
        $fixture = $this->fixture();
        $assignment = $this->assignment($fixture, now()->addDay());
        $this->interview($fixture, 'scheduled', now()->addHour());

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('pending_test', $action['type']);
        $this->assertSame($assignment->id, $action['target']['id']);
        $this->assertSame(100, $action['priority']);
    }

    public function test_expired_test_is_ignored_and_interview_is_returned(): void
    {
        $fixture = $this->fixture();
        $this->assignment($fixture, now()->subSecond());
        $interview = $this->interview(
            $fixture,
            'confirmed',
            now()->addHour(),
            ['confirmed_at' => now()],
        );

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('upcoming_interview', $action['type']);
        $this->assertSame($interview->id, $action['target']['id']);
    }

    public function test_started_unsubmitted_test_is_returned_as_started_action(): void
    {
        $fixture = $this->fixture();
        $assignment = $this->assignment($fixture, now()->addDay());
        TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => now()->subMinute(),
            'effective_deadline_at' => now()->addHour(),
        ]);

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('started_test', $action['type']);
        $this->assertSame('متابعة الاختبار', $action['action_label']);
    }

    public function test_unconfirmed_future_interview_requests_confirmation(): void
    {
        $fixture = $this->fixture();
        $interview = $this->interview(
            $fixture,
            'scheduled',
            now()->addHour(),
        );

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('interview_confirmation', $action['type']);
        $this->assertSame($interview->id, $action['target']['id']);
    }

    public function test_cancelled_and_completed_interviews_are_ignored(): void
    {
        $fixture = $this->fixture();
        $this->interview($fixture, 'cancelled', now()->addHour());
        $this->interview($fixture, 'completed', now()->addHours(2));

        $this->assertNull($this->resolve($fixture['profile'], true));
    }

    public function test_open_information_request_precedes_cv_and_profile_actions(): void
    {
        $fixture = $this->fixture();
        $request = ApplicationInformationRequest::create([
            'job_application_id' => $fixture['application']->id,
            'requested_by_user_id' => $fixture['employer']->id,
            'message' => 'Please attach a certificate.',
            'due_at' => now()->addDay(),
            'status' => 'pending',
            'previous_application_status' => 'under_review',
        ]);
        $this->cv($fixture['user']);

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('information_request', $action['type']);
        $this->assertSame($request->id, $action['target']['id']);
    }

    public function test_unconfirmed_parsed_cv_precedes_profile_sync_suggestion(): void
    {
        $fixture = $this->fixture();
        $cv = $this->cv($fixture['user']);
        ProfileChangeSuggestion::create([
            'user_id' => $fixture['user']->id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $fixture['profile']->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'source' => ProfileChangeSuggestion::SOURCE_CV_PARSED,
            'new_value' => ['headline' => 'Developer'],
        ]);

        $action = $this->resolve($fixture['profile']);

        $this->assertSame('cv_review', $action['type']);
        $this->assertSame($cv->id, $action['target']['id']);
    }

    public function test_profile_sync_suggestion_precedes_incomplete_profile(): void
    {
        $fixture = $this->fixture();
        ProfileChangeSuggestion::create([
            'user_id' => $fixture['user']->id,
            'job_seeker_profile_id' => $fixture['profile']->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'source' => ProfileChangeSuggestion::SOURCE_CV_PARSED,
            'new_value' => ['headline' => 'Developer'],
        ]);

        $this->assertSame(
            'profile_sync',
            $this->resolve($fixture['profile'])['type'],
        );
    }

    public function test_incomplete_profile_is_last_priority_and_complete_profile_has_no_action(): void
    {
        $fixture = $this->fixture();

        $action = $this->resolve($fixture['profile']);
        $this->assertSame('profile_incomplete', $action['type']);
        $this->assertSame(50, $action['priority']);
        $this->assertNull($this->resolve($fixture['profile'], true));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(
        JobSeekerProfile $profile,
        bool $complete = false,
    ): ?array {
        return app(HomeActionResolver::class)->resolve($profile, [
            'percentage' => $complete ? 100 : 25,
            'is_complete' => $complete,
            'missing_items_count' => $complete ? 0 : 1,
            'missing_items' => $complete ? [] : [[
                'key' => 'skills',
                'target' => ['type' => 'profile_section', 'value' => 'skills'],
            ]],
            'next_item' => $complete ? null : [
                'key' => 'skills',
                'target' => ['type' => 'profile_section', 'value' => 'skills'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $company = Company::factory()->create(['approval_status' => 'approved']);
        $user = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        $employer = User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => 'active',
        ]);
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);
        $job = JobPosting::factory()->create([
            'company_id' => $company->id,
            'status' => 'open',
            'published_at' => now(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::query()
                ->where('slug', 'under_review')
                ->value('id'),
        ]);

        return compact(
            'company',
            'user',
            'employer',
            'profile',
            'job',
            'application',
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function assignment(
        array $fixture,
        Carbon $deadline,
    ): ApplicationTestAssignment {
        $test = Test::forceCreate([
            'company_id' => $fixture['company']->id,
            'title' => 'Backend Test',
            'max_score' => 100,
            'is_active' => true,
        ]);

        return ApplicationTestAssignment::create([
            'job_application_id' => $fixture['application']->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $fixture['employer']->id,
            'assigned_at' => now(),
            'deadline_at' => $deadline,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function interview(
        array $fixture,
        string $status,
        Carbon $scheduledAt,
        array $overrides = [],
    ): Interview {
        return Interview::create(array_merge([
            'job_application_id' => $fixture['application']->id,
            'scheduled_by_user_id' => $fixture['employer']->id,
            'interview_type' => 'technical',
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'scheduled_end_at' => $scheduledAt->copy()->addHour(),
            'duration_minutes' => 60,
            'interview_mode' => 'online',
        ], $overrides));
    }

    private function cv(User $user): CVFile
    {
        return CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'cv.pdf',
            'stored_path' => 'cv/test.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
        ]);
    }
}
