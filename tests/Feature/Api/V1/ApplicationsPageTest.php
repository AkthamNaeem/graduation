<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\City;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $candidate;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
        $this->company = Company::create(['name' => 'Damascus Tech', 'approval_status' => 'approved']);
        $this->candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE]);
        JobSeekerProfile::create(['user_id' => $this->candidate->id, 'headline' => 'Backend Developer']);
        $this->candidate->load('jobSeekerProfile');
    }

    public function test_filters_search_counts_and_pagination_are_candidate_scoped(): void
    {
        $city = City::create(['code' => 'damascus', 'name_ar' => 'دمشق', 'name_en' => 'Damascus']);
        $active = $this->application('under_review', 'Backend Engineer', ['city_id' => $city->id]);
        $completed = $this->application('rejected', 'Frontend Engineer');
        $other = $this->candidate('other@example.com');
        $this->application('accepted', 'Other Candidate Job', [], $other->jobSeekerProfile);

        $this->authenticate($this->candidate);

        $this->getJson('/api/v1/applications/my?group=active&search=دمشق&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $active->id)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.meta.counts.all', 2)
            ->assertJsonPath('data.meta.counts.active', 1)
            ->assertJsonPath('data.meta.counts.completed', 1);

        $this->getJson('/api/v1/applications/my?group=completed&status[]=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $completed->id)
            ->assertJsonCount(1, 'data.data.0.allowed_actions')
            ->assertJsonPath('data.data.0.allowed_actions.0', 'view');

        $this->getJson('/api/v1/applications/my?group=all&search=Backend')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $active->id);

        $this->getJson('/api/v1/applications/my?search=Damascus%20Tech')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_required_actions_and_priority_order_are_derived_from_related_workflows(): void
    {
        $ordinary = $this->application('under_review', 'Ordinary');
        $testApplication = $this->application('test_pending', 'Urgent Test');
        $assignment = $this->assignment($testApplication, now()->subHour());
        $informationApplication = $this->application('need_more_information', 'Information');
        $information = ApplicationInformationRequest::create([
            'job_application_id' => $informationApplication->id,
            'requested_by_user_id' => $this->employer()->id,
            'message' => 'Portfolio required',
            'due_at' => now()->addDay(),
            'status' => 'pending',
            'previous_application_status' => 'under_review',
        ]);

        $this->authenticate($this->candidate);
        $response = $this->getJson('/api/v1/applications/my?group=requires_action&sort_by=priority')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $testApplication->id)
            ->assertJsonPath('data.data.0.requires_action', true)
            ->assertJsonPath('data.data.0.next_action.type.key', 'complete_test')
            ->assertJsonPath('data.data.0.next_action.resource_id', $assignment->id)
            ->assertJsonPath('data.data.0.next_action.is_overdue', true)
            ->assertJsonPath('data.data.1.next_action.type.key', 'submit_information')
            ->assertJsonPath('data.data.1.next_action.resource_id', $information->id)
            ->assertJsonPath('data.meta.counts.requires_action', 2);

        $this->assertNotContains($ordinary->id, collect($response->json('data.data'))->pluck('id')->all());

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/applications/my?group=requires_action&sort_by=priority')
            ->assertOk()
            ->assertJsonPath('data.data.0.next_action.type.key', 'complete_test')
            ->assertJsonPath('data.data.0.next_action.type.value', 'إكمال الاختبار');
    }

    public function test_scheduled_interview_requires_confirmation_and_details_remain_candidate_safe(): void
    {
        $application = $this->application('interview_scheduled', 'Interview Job');
        $interview = Interview::create([
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $this->employer()->id,
            'interview_type' => 'technical',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'scheduled_end_at' => now()->addDay()->addHour(),
            'interview_mode' => 'online',
            'meeting_link' => 'https://meet.example.test/secret',
            'internal_note' => 'Never expose this.',
        ]);

        $this->authenticate($this->candidate);
        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.requires_action', true)
            ->assertJsonPath('data.next_action.type.key', 'confirm_interview')
            ->assertJsonPath('data.next_action.resource_id', $interview->id)
            ->assertJsonPath('data.relevant_interview.requires_confirmation', true)
            ->assertJsonMissingPath('data.relevant_interview.internal_note')
            ->assertJsonMissingPath('data.status_history.0.note')
            ->assertJsonMissingPath('data.status_history.0.changed_by_user_id');
    }

    public function test_my_applications_authorization_and_validation_are_enforced(): void
    {
        $this->getJson('/api/v1/applications/my')->assertUnauthorized();

        $this->authenticate($this->employer());
        $this->getJson('/api/v1/applications/my')->assertForbidden();

        $this->authenticate($this->candidate);
        $this->getJson('/api/v1/applications/my?group=unknown&sort_by=unknown&status[]=not_real')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group', 'sort_by', 'status.0']);
    }

    public function test_list_query_count_remains_bounded_as_page_size_grows(): void
    {
        $this->application('under_review', 'One');
        $this->authenticate($this->candidate);

        DB::enableQueryLog();
        $this->getJson('/api/v1/applications/my')->assertOk();
        $singleCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 15) as $number) {
            $this->application('under_review', "Job {$number}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/applications/my')->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleCount + 3, $manyCount);
    }

    public function test_reapplication_is_allowed_only_after_a_terminal_application(): void
    {
        $job = $this->job('Reapplication Job');
        $first = $this->applicationForJob($job, 'under_review');
        $this->authenticate($this->candidate);

        $payload = [
            'selected_cv_file_id' => $this->cv()->id,
            'consent_to_share_profile' => true,
        ];
        $this->postJson("/api/v1/jobs/{$job->id}/applications", $payload)->assertUnprocessable();

        $first->update(['application_status_id' => $this->statusId('rejected')]);
        $secondId = $this->postJson("/api/v1/jobs/{$job->id}/applications", $payload)
            ->assertCreated()
            ->json('data.id');

        JobApplication::query()->findOrFail($secondId)->update([
            'application_status_id' => $this->statusId('withdrawn'),
        ]);
        $this->postJson("/api/v1/jobs/{$job->id}/applications", $payload)->assertCreated();

        $this->assertSame(3, JobApplication::query()
            ->where('job_posting_id', $job->id)
            ->where('job_seeker_profile_id', $this->candidate->jobSeekerProfile->id)
            ->count());
    }

    private function application(string $status, string $title, array $jobOverrides = [], ?JobSeekerProfile $profile = null): JobApplication
    {
        return $this->applicationForJob($this->job($title, $jobOverrides), $status, $profile);
    }

    private function applicationForJob(JobPosting $job, string $status, ?JobSeekerProfile $profile = null): JobApplication
    {
        $profile ??= $this->candidate->jobSeekerProfile;
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $this->statusId($status),
            'consent_to_share_profile' => true,
        ]);
        $application->statusHistory()->create([
            'to_application_status_id' => $this->statusId($status),
            'changed_by_user_id' => $profile->user_id,
            'note' => 'Private workflow note.',
        ]);

        return $application;
    }

    private function job(string $title, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => $title,
            'description' => 'Description',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now(),
        ], $overrides));
    }

    private function assignment(JobApplication $application, $deadline): ApplicationTestAssignment
    {
        $test = Test::query()->forceCreate([
            'company_id' => $this->company->id,
            'title' => 'Backend Assessment',
            'max_score' => 100,
            'is_active' => true,
        ]);

        return ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $this->employer()->id,
            'assigned_at' => now()->subDay(),
            'deadline_at' => $deadline,
        ]);
    }

    private function employer(): User
    {
        $existing = User::query()->where('email', 'owner@example.com')->first();
        if ($existing !== null) {
            return $existing;
        }

        $user = User::factory()->create(['email' => 'owner@example.com', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $this->company->id]);

        return $user->load('employerProfile');
    }

    private function candidate(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        return $user->load('jobSeekerProfile');
    }

    private function cv(): CVFile
    {
        $cv = CVFile::create([
            'user_id' => $this->candidate->id,
            'original_name' => 'candidate.pdf',
            'stored_path' => 'cv-files/candidate.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
        ]);
        Storage::disk('local')->put($cv->stored_path, 'pdf');

        return $cv;
    }

    private function statusId(string $slug): int
    {
        return (int) ApplicationStatus::query()->where('slug', $slug)->value('id');
    }

    private function authenticate(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('applications-page-test')->plainTextToken);
    }
}
