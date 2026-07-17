<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesInterviewScenarios
{
    /** @return array{User, User, JobApplication} */
    protected function interviewScenario(string $status = 'shortlisted', string $suffix = ''): array
    {
        $company = Company::create(['name' => "Lifecycle Company {$suffix}", 'approval_status' => 'approved']);
        $employer = User::factory()->create(['email' => "owner{$suffix}@example.com", 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['email' => "candidate{$suffix}@example.com", 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $candidate->id, 'headline' => 'Backend Engineer']);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build recruitment services.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', $status)->value('id'),
        ]);

        return [$employer->load('employerProfile.company'), $candidate->load('jobSeekerProfile'), $application->load('jobSeekerProfile.user', 'jobPosting.company', 'applicationStatus')];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    protected function validInterviewPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'technical',
            'mode' => 'online',
            'scheduled_start_at' => now()->addDay()->toISOString(),
            'scheduled_end_at' => now()->addDay()->addHour()->toISOString(),
            'meeting_link' => 'https://meet.example.com/technical',
            'candidate_message' => 'Please join ten minutes early.',
            'internal_note' => 'Internal preparation only.',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function createInterview(User $employer, JobApplication $application, array $overrides = []): int
    {
        return (int) $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload($overrides))
            ->assertCreated()
            ->json('data.id');
    }

    protected function tokenForInterviewUser(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
