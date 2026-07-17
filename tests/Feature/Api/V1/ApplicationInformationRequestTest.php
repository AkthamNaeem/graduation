<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\ApplicationInformationRequestUpdated;
use App\Events\ApplicationInformationResponded;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationInformationResponseAttachment;
use App\Models\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationInformationRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_owner_employer_creates_atomic_request_with_items_history_audit_and_notification(): void
    {
        [$company,$employer,$candidate,$application] = $this->context('under_review');
        $response = $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", $this->payload());

        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.requested_items.0.order_index', 0)->assertJsonPath('data.previous_application_status', 'under_review');
        $requestId = $response->json('data.id');
        $this->assertDatabaseHas('application_information_requests', ['id' => $requestId, 'previous_application_status' => 'under_review', 'status' => 'pending']);
        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'application_status_id' => $this->statusId('need_more_information')]);
        $this->assertDatabaseCount('application_information_request_items', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.information_request_created', 'entity_id' => $requestId]);
        $this->assertDatabaseHas('notifications', ['user_id' => $candidate->id, 'type' => 'application.information_requested']);
        $this->assertSame(2, $application->statusHistory()->count());

        $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", $this->payload())
            ->assertConflict()->assertJsonPath('code', 'APPLICATION_INFORMATION_REQUEST_ALREADY_OPEN');
    }

    public function test_direct_status_bypass_is_blocked_in_both_directions(): void
    {
        [, $employer,, $application] = $this->context('under_review');
        $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/status", ['status' => 'need_more_information'])
            ->assertConflict()->assertJsonPath('code', 'INFORMATION_REQUEST_ENDPOINT_REQUIRED');
        $request = $this->createRequest($employer, $application);
        $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/status", ['status' => 'under_review'])
            ->assertConflict()->assertJsonPath('code', 'INFORMATION_RESPONSE_REQUIRED');
        $this->assertDatabaseHas('application_information_requests', ['id' => $request->id, 'status' => 'pending']);
    }

    public function test_candidate_views_only_owned_safe_request_and_employer_sees_limited_actor_summary(): void
    {
        [, $employer,$candidate,$application] = $this->context();
        $request = $this->createRequest($employer, $application);
        [,,$other] = $this->context('submitted', 'other');

        $candidateResponse = $this->withToken($this->token($candidate))->getJson("/api/v1/information-requests/{$request->id}");
        $candidateResponse->assertOk()->assertJsonMissingPath('data.requested_by')->assertJsonMissingPath('data.cancelled_by')->assertJsonPath('data.can_respond', true);
        $this->withToken($this->token($employer))->getJson("/api/v1/information-requests/{$request->id}")
            ->assertOk()->assertJsonPath('data.requested_by.id', $employer->id)->assertJsonMissingPath('data.requested_by.email');
        $this->withToken($this->token($other))->getJson("/api/v1/information-requests/{$request->id}")->assertForbidden();
    }

    public function test_candidate_submits_message_and_private_file_once_and_application_returns_under_review(): void
    {
        Storage::fake('local');
        [, $employer,$candidate,$application] = $this->context('shortlisted');
        $request = $this->createRequest($employer, $application);
        $file = UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf');

        $response = $this->withToken($this->token($candidate))->post("/api/v1/information-requests/{$request->id}/respond", ['message' => '  Attached as requested.  ', 'attachments' => [$file]], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('data.status', 'responded')->assertJsonPath('data.response.message', 'Attached as requested.')->assertJsonMissingPath('data.response.attachments.0.stored_path')->assertJsonMissingPath('data.response.attachments.0.disk');
        $attachmentId = $response->json('data.response.attachments.0.id');
        $attachment = ApplicationInformationResponseAttachment::findOrFail($attachmentId);
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'application_status_id' => $this->statusId('under_review')]);
        $this->assertDatabaseHas('notifications', ['user_id' => $employer->id, 'type' => 'application.information_submitted']);
        event(new ApplicationInformationResponded($request->id));
        event(new ApplicationInformationResponded($request->id));
        $this->assertSame(1, $employer->notifications()->where('type', 'application.information_submitted')->count());
        $this->withToken($this->token($candidate))->get("/api/v1/information-response-attachments/{$attachmentId}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withToken($this->token($candidate))->postJson("/api/v1/information-requests/{$request->id}/respond", ['message' => 'again'])->assertConflict();
    }

    public function test_deadline_boundary_expiry_and_extension_are_enforced(): void
    {
        Carbon::setTestNow('2026-08-20 18:00:00');
        try {
            [, $employer,$candidate,$application] = $this->context();
            $request = $this->createRequest($employer, $application, ['due_at' => now()->addHour()->toISOString()]);
            Carbon::setTestNow($request->due_at);
            $this->withToken($this->token($candidate))->postJson("/api/v1/information-requests/{$request->id}/respond", ['message' => 'On time'])->assertCreated();

            [, $employer2,$candidate2,$application2] = $this->context('submitted', 'second');
            $request2 = $this->createRequest($employer2, $application2, ['due_at' => now()->addHour()->toISOString()]);
            Carbon::setTestNow($request2->due_at->addSecond());
            $this->withToken($this->token($candidate2))->postJson("/api/v1/information-requests/{$request2->id}/respond", ['message' => 'Late'])->assertConflict()->assertJsonPath('code', 'APPLICATION_INFORMATION_REQUEST_EXPIRED');
            $this->withToken($this->token($employer2))->patchJson("/api/v1/information-requests/{$request2->id}", ['due_at' => now()->addHour()->toISOString()])->assertOk();
            $this->withToken($this->token($candidate2))->postJson("/api/v1/information-requests/{$request2->id}/respond", ['message' => 'After extension'])->assertCreated();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_update_noop_and_cancel_restore_previous_status_and_preserve_history(): void
    {
        [, $employer,$candidate,$application] = $this->context('test_completed');
        $request = $this->createRequest($employer, $application);
        $createdNotifications = $candidate->notifications()->count();
        $this->withToken($this->token($employer))->patchJson("/api/v1/information-requests/{$request->id}", [])->assertOk();
        $this->assertSame($createdNotifications, $candidate->notifications()->count());
        $this->withToken($this->token($employer))->patchJson("/api/v1/information-requests/{$request->id}", ['message' => 'Updated request', 'requested_items' => [['label' => 'Portfolio', 'is_required' => true]]])->assertOk();
        $this->assertSame($createdNotifications + 1, $candidate->notifications()->count());
        $updateOccurrence = AuditLog::query()->where('action', 'application.information_request_updated')->where('entity_id', $request->id)->latest('id')->value('id');
        event(new ApplicationInformationRequestUpdated($request->id, $updateOccurrence));
        event(new ApplicationInformationRequestUpdated($request->id, $updateOccurrence));
        $this->assertSame(1, $candidate->notifications()->where('type', 'application.information_request_updated')->count());
        $this->withToken($this->token($employer))->postJson("/api/v1/information-requests/{$request->id}/cancel", ['reason' => 'Received elsewhere'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'application_status_id' => $this->statusId('test_completed')]);
        $this->assertDatabaseCount('application_information_requests', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.information_request_cancelled', 'entity_id' => $request->id]);
        $this->createRequest($employer, $application->fresh());
        $this->assertDatabaseCount('application_information_requests', 2);
    }

    public function test_validation_company_state_and_cross_company_authorization(): void
    {
        [$company,$employer,$candidate,$application] = $this->context();
        $payload = $this->payload();
        $payload['requested_items'][1]['label'] = ' CERTIFICATE ';
        $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", $payload)->assertUnprocessable()->assertJsonValidationErrors('requested_items');
        $otherCompany = Company::create(['name' => 'Other Co', 'approval_status' => 'approved']);
        $otherEmployer = $this->employer($otherCompany, 'other-employer@example.com');
        $this->withToken($this->token($otherEmployer))->postJson("/api/v1/applications/{$application->id}/information-requests", $this->payload())->assertForbidden();
        $company->update(['approval_status' => 'suspended']);
        $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", $this->payload())->assertForbidden()->assertJsonPath('code', 'APPLICATION_INFORMATION_REQUEST_COMPANY_UNAVAILABLE');
        $this->withToken($this->token($candidate))->postJson("/api/v1/applications/{$application->id}/information-requests", $this->payload())->assertForbidden();
    }

    private function createRequest(User $employer, JobApplication $application, array $overrides = []): ApplicationInformationRequest
    {
        $response = $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", array_replace($this->payload(), $overrides))->assertCreated();

        return ApplicationInformationRequest::findOrFail($response->json('data.id'));
    }

    private function payload(): array
    {
        return ['message' => 'Please provide supporting documents.', 'requested_items' => [['label' => 'Certificate', 'description' => 'PDF copy', 'is_required' => true], ['label' => 'Portfolio', 'is_required' => false]], 'due_at' => now()->addDay()->toISOString()];
    }

    private function context(string $status = 'submitted', string $suffix = 'main'): array
    {
        $company = Company::create(['name' => 'Company '.$suffix.' '.Str::random(4), 'approval_status' => 'approved']);
        $employer = $this->employer($company, "employer-{$suffix}-".Str::random(4).'@example.com');
        $candidate = User::factory()->create(['email' => "candidate-{$suffix}-".Str::random(4).'@example.com', 'role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id, 'headline' => 'Engineer']);
        $candidate->setRelation('jobSeekerProfile', $profile);
        $job = JobPosting::create(['company_id' => $company->id, 'title' => 'Engineer', 'description' => 'Build APIs', 'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'location' => 'Remote', 'status' => 'open', 'published_at' => now()]);
        $cv = CVFile::create(['user_id' => $candidate->id, 'original_name' => 'cv.pdf', 'stored_path' => 'cv/cv.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 100, 'status' => 'parsed']);
        $application = JobApplication::create(['job_posting_id' => $job->id, 'job_seeker_profile_id' => $profile->id, 'selected_cv_file_id' => $cv->id, 'application_status_id' => $this->statusId($status), 'consent_to_share_profile' => true]);
        $application->statusHistory()->create(['to_application_status_id' => $this->statusId($status), 'changed_by_user_id' => $candidate->id]);

        return [$company, $employer, $candidate, $application->load(['applicationStatus', 'jobPosting', 'jobSeekerProfile'])];
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function statusId(string $slug): int
    {
        return (int) ApplicationStatus::where('slug', $slug)->value('id');
    }

    private function token(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
