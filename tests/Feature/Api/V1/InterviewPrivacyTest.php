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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterviewPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_candidate_interview_list_and_details_omit_private_inventory_and_nested_application_history(): void
    {
        [$employer, $candidate, $interview] = $this->scenario();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $list = $this->withToken($this->tokenFor($candidate))
            ->getJson('/api/v1/my/interviews')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $interview->id)
            ->assertJsonPath('data.data.0.interview_mode', 'video')
            ->assertJsonPath('data.data.0.meeting_link', 'https://meet.example.com/private-room')
            ->assertJsonPath('data.data.0.state', 'completed')
            ->assertJsonMissingPath('data.data.0.location');

        $this->assertSafeInterview($list, 'data.data.0');

        $details = $this->withToken($this->tokenFor($candidate))
            ->getJson("/api/v1/interviews/{$interview->id}")
            ->assertOk()
            ->assertJsonPath('data.job_application.status.slug', 'interview_completed');

        $this->assertSafeInterview($details, 'data');
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'interview_evaluations')
                || str_contains($sql, 'interview_evaluation_items'),
        ), 'Candidate interview endpoints queried hidden evaluation tables.');

        $otherCandidate = $this->jobSeeker('other-candidate@example.com');
        $this->withToken($this->tokenFor($otherCandidate))
            ->getJson("/api/v1/interviews/{$interview->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('interview_evaluations', [
            'interview_id' => $interview->id,
            'recommendation' => 'advance',
            'overall_comment' => 'Private evaluator comment.',
            'evaluated_by_user_id' => $employer->id,
        ]);
    }

    public function test_owner_employer_keeps_interview_management_contract_and_other_company_is_denied(): void
    {
        [$employer, , $interview] = $this->scenario();

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/interviews/{$interview->id}")
            ->assertOk()
            ->assertJsonPath('data.scheduled_by_user_id', $employer->id)
            ->assertJsonPath('data.completed_by_user_id', $employer->id)
            ->assertJsonPath('data.note', 'Internal interview preparation note.')
            ->assertJsonPath('data.completion_note', 'Private completion note.')
            ->assertJsonPath('data.evaluation.recommendation', 'advance')
            ->assertJsonPath('data.evaluation.overall_comment', 'Private evaluator comment.')
            ->assertJsonPath('data.evaluation.evaluated_by.id', $employer->id)
            ->assertJsonCount(1, 'data.evaluation.items');

        $otherEmployer = $this->employer('other-owner@example.com');
        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/interviews/{$interview->id}")
            ->assertForbidden();
    }

    private function assertSafeInterview($response, string $prefix): void
    {
        foreach ([
            'scheduled_by_user_id',
            'completed_by_user_id',
            'evaluated_by_user_id',
            'note',
            'completion_note',
            'internal_note',
            'private_note',
            'evaluation',
            'evaluation_items',
            'recommendation',
            'overall_comment',
            'reviewer_note',
            'job_application.status_history.0.note',
            'job_application.status_history.0.changed_by_user_id',
            'job_application.status_history.0.changed_by',
        ] as $path) {
            $response->assertJsonMissingPath("{$prefix}.{$path}");
        }
    }

    /** @return array{User, User, Interview} */
    private function scenario(): array
    {
        $company = Company::create(['name' => 'Interview Privacy Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $status = ApplicationStatus::query()->where('slug', 'interview_completed')->firstOrFail();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'application_status_id' => $status->id,
        ]);
        $application->statusHistory()->create([
            'from_application_status_id' => ApplicationStatus::query()->where('slug', 'interview_scheduled')->value('id'),
            'to_application_status_id' => $status->id,
            'changed_by_user_id' => $employer->id,
            'note' => 'Internal status note.',
        ]);
        $interview = Interview::create([
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $employer->id,
            'interview_type' => 'Technical Interview',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'interview_mode' => 'video',
            'location' => 'Internal remote label',
            'meeting_link' => 'https://meet.example.com/private-room',
            'note' => 'Internal interview preparation note.',
            'completion_note' => 'Private completion note.',
            'completed_at' => now(),
            'completed_by_user_id' => $employer->id,
        ]);
        $evaluation = InterviewEvaluation::create([
            'interview_id' => $interview->id,
            'evaluated_by_user_id' => $employer->id,
            'recommendation' => 'advance',
            'overall_comment' => 'Private evaluator comment.',
            'evaluated_at' => now(),
        ]);
        $evaluation->items()->create([
            'criterion' => 'Architecture',
            'score' => 5,
            'comment' => 'Private criterion comment.',
            'sort_order' => 1,
        ]);

        return [$employer, $candidate, $interview];
    }

    private function employer(string $email, ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Company '.$email, 'approval_status' => 'approved']);
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile');
    }

    private function jobSeeker(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id, 'headline' => 'Engineer']);

        return $user->load('jobSeekerProfile');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
