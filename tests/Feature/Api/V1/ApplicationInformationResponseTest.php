<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatus;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationInformationResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        Storage::fake('local');
    }

    public function test_response_requires_message_or_files_and_validates_file_limits(): void
    {
        [$employer,$candidate,$request] = $this->context();
        $this->withToken($this->token($candidate))->postJson("/api/v1/information-requests/{$request->id}/respond", [])->assertUnprocessable();
        $this->withToken($this->token($candidate))->post("/api/v1/information-requests/{$request->id}/respond", ['attachments' => [UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $files = collect(range(1, 6))->map(fn ($i) => UploadedFile::fake()->create("doc{$i}.pdf", 10, 'application/pdf'))->all();
        $this->withToken($this->token($candidate))->post("/api/v1/information-requests/{$request->id}/respond", ['attachments' => $files], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->assertDatabaseCount('application_information_responses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachment_download_rejects_other_candidate_and_cross_company_employer(): void
    {
        [$employer,$candidate,$request] = $this->context();
        $response = $this->withToken($this->token($candidate))->post("/api/v1/information-requests/{$request->id}/respond", ['attachments' => [UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $attachmentId = $response->json('data.response.attachments.0.id');
        [, $otherCandidate] = $this->context('other');
        $otherCompany = Company::create(['name' => 'Foreign Co', 'approval_status' => 'approved']);
        $otherEmployer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $otherEmployer->id, 'company_id' => $otherCompany->id]);
        $this->withToken($this->token($otherCandidate))->get("/api/v1/information-response-attachments/{$attachmentId}/download")->assertForbidden();
        $this->withToken($this->token($otherEmployer))->get("/api/v1/information-response-attachments/{$attachmentId}/download")->assertForbidden();
        $this->withToken($this->token($employer))->get("/api/v1/information-response-attachments/{$attachmentId}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_suspended_company_blocks_candidate_upload_but_keeps_historical_read(): void
    {
        [$employer,$candidate,$request,$company] = $this->context();
        $company->update(['approval_status' => 'suspended']);
        $this->withToken($this->token($candidate))->postJson("/api/v1/information-requests/{$request->id}/respond", ['message' => 'blocked'])->assertForbidden()->assertJsonPath('code', 'APPLICATION_INFORMATION_REQUEST_COMPANY_UNAVAILABLE');
        $this->withToken($this->token($candidate))->getJson("/api/v1/information-requests/{$request->id}")->assertOk();
        $this->withToken($this->token($employer))->getJson("/api/v1/information-requests/{$request->id}")->assertOk();
    }

    private function context(string $suffix = 'main'): array
    {
        $company = Company::create(['name' => 'Company '.$suffix.Str::random(3), 'approval_status' => 'approved']);
        $employer = User::factory()->create(['email' => 'emp-'.$suffix.Str::random(3).'@example.com', 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['email' => 'cand-'.$suffix.Str::random(3).'@example.com', 'role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create(['company_id' => $company->id, 'title' => 'Engineer', 'description' => 'APIs', 'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'location' => 'Remote', 'status' => 'open', 'published_at' => now()]);
        $cv = CVFile::create(['user_id' => $candidate->id, 'original_name' => 'cv.pdf', 'stored_path' => 'cv.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 10, 'status' => 'parsed']);
        $application = JobApplication::create(['job_posting_id' => $job->id, 'job_seeker_profile_id' => $profile->id, 'selected_cv_file_id' => $cv->id, 'application_status_id' => ApplicationStatus::where('slug', 'submitted')->value('id'), 'consent_to_share_profile' => true]);
        $create = $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/information-requests", ['message' => 'Provide proof', 'requested_items' => [['label' => 'Proof']]])->assertCreated();

        return [$employer, $candidate, ApplicationInformationRequest::findOrFail($create->json('data.id')), $company];
    }

    private function token(User $user): string
    {
        return $user->createToken(Str::random(8))->plainTextToken;
    }
}
