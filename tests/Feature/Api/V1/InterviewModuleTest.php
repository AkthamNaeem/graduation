<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterviewModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_employer_can_schedule_interview_and_application_moves_to_interview_scheduled(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('under_review');

        $response = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertCreated()
            ->assertJsonPath('data.job_application_id', $application->id)
            ->assertJsonPath('data.interview_type', 'technical')
            ->assertJsonPath('data.interview_mode', 'online')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.job_application.status.slug', 'interview_scheduled');

        $interviewId = $response->json('data.id');

        $this->assertDatabaseHas('interviews', [
            'id' => $interviewId,
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $employer->id,
            'interview_type' => 'technical',
            'interview_mode' => 'online',
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_scheduled')->value('id'),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'interview.scheduled',
            'entity_type' => Interview::class,
            'entity_id' => $interviewId,
            'actor_user_id' => $employer->id,
        ]);

        $this->assertFalse(Schema::hasColumn('job_applications', 'interview_type'));
        $this->assertFalse(Schema::hasColumn('job_applications', 'scheduled_at'));
    }

    public function test_interview_creation_is_blocked_for_terminal_statuses_and_second_active_round(): void
    {
        [$employer, $acceptedApplication] = $this->employerApplicationScenario('accepted', 'accepted@example.com', 'owner-one@example.com');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$acceptedApplication->id}/interviews", $this->interviewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_INVALID_STATUS_TRANSITION');

        [$sameEmployer, $application] = $this->employerApplicationScenario('shortlisted', 'candidate@example.com', 'owner-two@example.com');

        $this->withToken($this->tokenFor($sameEmployer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertCreated();

        $this->withToken($this->tokenFor($sameEmployer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload([
                'scheduled_start_at' => now()->addDays(3)->toISOString(),
                'scheduled_end_at' => now()->addDays(3)->addHour()->toISOString(),
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_ALREADY_ACTIVE_FOR_TYPE');
    }

    public function test_employer_can_list_update_and_delete_interviews_with_status_recalculation(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('shortlisted');

        $createResponse = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertCreated();

        $interviewId = $createResponse->json('data.id');

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/applications/{$application->id}/interviews")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $interviewId);

        $this->withToken($this->tokenFor($employer))
            ->patchJson("/api/v1/interviews/{$interviewId}", [
                'type' => 'final',
                'candidate_message' => 'Please bring an identity document.',
            ])
            ->assertOk()
            ->assertJsonPath('data.interview_type', 'final')
            ->assertJsonPath('data.candidate_message', 'Please bring an identity document.');

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$interviewId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('interviews', [
            'id' => $interviewId,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'interview.cancelled',
            'entity_type' => Interview::class,
            'entity_id' => $interviewId,
            'actor_user_id' => $employer->id,
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_pending')->value('id'),
        ]);
    }

    public function test_completed_interviews_cannot_be_updated_or_deleted_and_completion_moves_status(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('test_completed');

        $interviewId = $this->scheduleConfirmedStartedInterview($employer, $application);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/complete", [
                'completion_note' => 'Candidate attended and completed the round.',
            ])
            ->assertOk()
            ->assertJsonPath('data.completed_by_user_id', $employer->id)
            ->assertJsonPath('data.completion_note', 'Candidate attended and completed the round.')
            ->assertJsonPath('data.job_application.status.slug', 'interview_completed');

        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/interviews/{$interviewId}", $this->interviewPayload([
                'interview_type' => 'Rescheduled Interview',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_INVALID_STATUS_TRANSITION');

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$interviewId}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_CANCELLATION_NOT_ALLOWED');
    }

    public function test_interview_must_be_completed_before_evaluation_and_cannot_be_evaluated_twice(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('shortlisted');
        $interviewId = $this->scheduleInterview($employer, $application, ['type' => 'final']);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/evaluate", $this->evaluationPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_EVALUATION_NOT_ALLOWED');

        $this->confirmAndStartInterview($employer, $application, $interviewId);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/complete", [
                'completion_note' => 'Round completed.',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/evaluate", $this->evaluationPayload())
            ->assertOk()
            ->assertJsonPath('data.evaluation.recommendation', 'advance')
            ->assertJsonCount(2, 'data.evaluation.items')
            ->assertJsonPath('data.job_application.status.slug', 'final_review');

        $this->assertDatabaseHas('interview_evaluations', [
            'interview_id' => $interviewId,
            'evaluated_by_user_id' => $employer->id,
            'recommendation' => 'advance',
        ]);

        $this->assertDatabaseHas('interview_evaluation_items', [
            'interview_evaluation_id' => InterviewEvaluation::query()->where('interview_id', $interviewId)->value('id'),
            'criterion' => 'Communication',
            'score' => 4,
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'interview.evaluated',
            'entity_type' => Interview::class,
            'entity_id' => $interviewId,
            'actor_user_id' => $employer->id,
        ]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/evaluate", $this->evaluationPayload([
                'recommendation' => 'hold',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_EVALUATION_NOT_ALLOWED');
    }

    public function test_multiple_sequential_interviews_are_supported_and_statuses_follow_the_workflow(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('under_review');

        $firstInterviewId = $this->scheduleConfirmedStartedInterview($employer, $application);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$firstInterviewId}/complete", [
                'completion_note' => 'First round complete.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_completed')->value('id'),
        ]);

        $secondInterviewId = $this->scheduleInterview($employer, $application, [
            'type' => 'hr',
            'scheduled_start_at' => now()->addDays(2)->toISOString(),
            'scheduled_end_at' => now()->addDays(2)->addHour()->toISOString(),
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_scheduled')->value('id'),
        ]);

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$secondInterviewId}")
            ->assertOk();

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_completed')->value('id'),
        ]);

        $thirdInterviewId = $this->scheduleInterview($employer, $application, [
            'type' => 'final',
            'scheduled_start_at' => now()->addDays(4)->toISOString(),
            'scheduled_end_at' => now()->addDays(4)->addHour()->toISOString(),
        ]);

        $this->confirmAndStartInterview($employer, $application, $thirdInterviewId);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$thirdInterviewId}/complete", [
                'completion_note' => 'Final round complete.',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$thirdInterviewId}/evaluate", $this->evaluationPayload([
                'recommendation' => 'advance',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'final_review')->value('id'),
        ]);
    }

    public function test_job_seeker_can_list_and_view_only_own_interviews(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('shortlisted');
        $jobSeeker = $application->jobSeekerProfile->user;
        $otherJobSeeker = $this->jobSeeker('other-job-seeker@example.com');
        $otherEmployer = $this->employer('other-employer@example.com');

        $interviewId = $this->scheduleInterview($employer, $application);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/my/interviews')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $interviewId)
            ->assertJsonPath('data.data.0.job_application.id', $application->id)
            ->assertJsonPath('data.meta.current_page', 1);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson("/api/v1/interviews/{$interviewId}")
            ->assertOk()
            ->assertJsonPath('data.id', $interviewId)
            ->assertJsonPath('data.job_application.id', $application->id);

        $this->withToken($this->tokenFor($otherJobSeeker))
            ->getJson("/api/v1/interviews/{$interviewId}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/interviews/{$interviewId}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    private function scheduleInterview(User $employer, JobApplication $application, array $overrides = []): int
    {
        $response = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload($overrides))
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function scheduleConfirmedStartedInterview(User $employer, JobApplication $application, array $overrides = []): int
    {
        $interviewId = $this->scheduleInterview($employer, $application, $overrides);
        $this->confirmAndStartInterview($employer, $application, $interviewId);

        return $interviewId;
    }

    private function confirmAndStartInterview(User $employer, JobApplication $application, int $interviewId): void
    {
        $candidate = $application->jobSeekerProfile->user;
        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/interviews/{$interviewId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        Carbon::setTestNow(Interview::findOrFail($interviewId)->scheduled_at);
        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/interviews/{$interviewId}/attendance", [
                'candidate_status' => 'present',
                'interviewer_status' => 'present',
                'note' => 'Both parties joined.',
            ])->assertOk();
    }

    /**
     * @return array{0: User, 1: JobApplication}
     */
    private function employerApplicationScenario(
        string $statusSlug = 'under_review',
        string $candidateEmail = 'candidate@example.com',
        string $employerEmail = 'owner@example.com',
    ): array {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer($employerEmail, $company);
        $jobSeeker = $this->jobSeeker($candidateEmail);
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, $statusSlug);

        return [$employer, $application];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function interviewPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'technical',
            'scheduled_start_at' => now()->addDay()->toISOString(),
            'scheduled_end_at' => now()->addDay()->addHour()->toISOString(),
            'mode' => 'online',
            'meeting_link' => 'https://meet.example.com/interview-room',
            'internal_note' => 'Focus on Laravel architecture and APIs.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evaluationPayload(array $overrides = []): array
    {
        return array_merge([
            'recommendation' => 'advance',
            'overall_comment' => 'Strong communication and technical depth.',
            'items' => [
                [
                    'criterion' => 'Communication',
                    'score' => 4,
                    'comment' => 'Clear answers and solid explanations.',
                ],
                [
                    'criterion' => 'Problem Solving',
                    'score' => 5,
                    'comment' => 'Excellent reasoning through backend tradeoffs.',
                ],
            ],
        ], $overrides);
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email, 'approval_status' => 'approved']);

        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::EMPLOYER,
        ]);

        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user->load('employerProfile.company');
    }

    private function jobSeeker(string $email = 'jobseeker@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::JOB_SEEKER,
        ]);

        JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Backend Developer',
        ]);

        return $user->load('jobSeekerProfile');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build smart recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'salary_min' => 70000,
            'salary_max' => 90000,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    private function applicationFor(
        JobPosting $jobPosting,
        JobSeekerProfile $jobSeekerProfile,
        string $statusSlug = 'submitted',
    ): JobApplication {
        $statusId = ApplicationStatus::query()->where('slug', $statusSlug)->value('id');

        $application = JobApplication::create([
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $jobSeekerProfile->id,
            'application_status_id' => $statusId,
        ]);

        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $statusId,
            'changed_by_user_id' => $jobSeekerProfile->user_id,
            'note' => null,
        ]);

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile.user');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
