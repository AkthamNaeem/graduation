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
            ->assertJsonPath('data.interview_type', 'Technical Interview')
            ->assertJsonPath('data.interview_mode', 'video')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.job_application.status.slug', 'interview_scheduled');

        $interviewId = $response->json('data.id');

        $this->assertDatabaseHas('interviews', [
            'id' => $interviewId,
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $employer->id,
            'interview_type' => 'Technical Interview',
            'interview_mode' => 'video',
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_scheduled')->value('id'),
        ]);

        $this->assertFalse(Schema::hasColumn('job_applications', 'interview_type'));
        $this->assertFalse(Schema::hasColumn('job_applications', 'scheduled_at'));
    }

    public function test_interview_creation_is_blocked_for_terminal_statuses_and_second_active_round(): void
    {
        [$employer, $acceptedApplication] = $this->employerApplicationScenario('accepted', 'accepted@example.com', 'owner-one@example.com');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$acceptedApplication->id}/interviews", $this->interviewPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_application_id']);

        [$sameEmployer, $application] = $this->employerApplicationScenario('shortlisted', 'candidate@example.com', 'owner-two@example.com');

        $this->withToken($this->tokenFor($sameEmployer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertCreated();

        $this->withToken($this->tokenFor($sameEmployer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->interviewPayload([
                'scheduled_at' => now()->addDays(3)->toISOString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_application_id']);
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
            ->putJson("/api/v1/interviews/{$interviewId}", $this->interviewPayload([
                'interview_type' => 'Final Team Interview',
                'interview_mode' => 'in_person',
                'location' => 'Damascus HQ',
                'meeting_link' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('data.interview_type', 'Final Team Interview')
            ->assertJsonPath('data.interview_mode', 'in_person')
            ->assertJsonPath('data.location', 'Damascus HQ');

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$interviewId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('interviews', [
            'id' => $interviewId,
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_pending')->value('id'),
        ]);
    }

    public function test_completed_interviews_cannot_be_updated_or_deleted_and_completion_moves_status(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('test_completed');

        $interviewId = $this->scheduleInterview($employer, $application);

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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interview_id']);

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$interviewId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interview_id']);
    }

    public function test_interview_must_be_completed_before_evaluation_and_cannot_be_evaluated_twice(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('shortlisted');
        $interviewId = $this->scheduleInterview($employer, $application);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/evaluate", $this->evaluationPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interview_id']);

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

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/evaluate", $this->evaluationPayload([
                'recommendation' => 'hold',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interview_id']);
    }

    public function test_multiple_sequential_interviews_are_supported_and_statuses_follow_the_workflow(): void
    {
        [$employer, $application] = $this->employerApplicationScenario('under_review');

        $firstInterviewId = $this->scheduleInterview($employer, $application);

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
            'interview_type' => 'Leadership Interview',
            'scheduled_at' => now()->addDays(2)->toISOString(),
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
            'interview_type' => 'Executive Interview',
            'scheduled_at' => now()->addDays(4)->toISOString(),
        ]);

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

    /**
     * @return array{0: User, 1: JobApplication}
     */
    private function employerApplicationScenario(
        string $statusSlug = 'under_review',
        string $candidateEmail = 'candidate@example.com',
        string $employerEmail = 'owner@example.com',
    ): array
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
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
            'interview_type' => 'Technical Interview',
            'scheduled_at' => now()->addDay()->toISOString(),
            'duration_minutes' => 60,
            'interview_mode' => 'video',
            'location' => 'Remote',
            'meeting_link' => 'https://meet.example.com/interview-room',
            'note' => 'Focus on Laravel architecture and APIs.',
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
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email]);

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
