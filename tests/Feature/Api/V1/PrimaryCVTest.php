<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrimaryCVTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_first_upload_is_primary_and_later_upload_respects_make_primary(): void
    {
        $user = $this->jobSeeker();
        $token = $this->tokenFor($user);

        $first = $this->withToken($token)->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            'version_label' => ' Backend CV ',
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->assertJsonPath('data.version_label', 'Backend CV');

        $second = $this->withToken($token)->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.is_primary', false);

        $third = $this->withToken($token)->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('third.pdf', 10, 'application/pdf'),
            'make_primary' => true,
        ])->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->assertSame($third->json('data.id'), $user->jobSeekerProfile->refresh()->primary_cv_file_id);
        $this->assertNull(CVFile::findOrFail($first->json('data.id'))->archived_at);
        $this->assertNull(CVFile::findOrFail($second->json('data.id'))->archived_at);
    }

    public function test_primary_switch_is_idempotent_and_does_not_change_application_reference(): void
    {
        $user = $this->jobSeeker();
        [$a, $b] = [$this->cv($user, 'a.pdf'), $this->cv($user, 'b.pdf')];
        $user->jobSeekerProfile->update(['primary_cv_file_id' => $a->id]);
        $application = $this->application($user, $a);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$b->id}/make-primary")->assertOk();
        $auditCount = \App\Models\AuditLog::query()->where('action', 'cv.primary_changed')->count();
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$b->id}/make-primary")->assertOk();

        $this->assertSame($b->id, $user->jobSeekerProfile->refresh()->primary_cv_file_id);
        $this->assertSame($a->id, $application->refresh()->selected_cv_file_id);
        $this->assertSame($auditCount, \App\Models\AuditLog::query()->where('action', 'cv.primary_changed')->count());
    }

    public function test_primary_archive_requires_replacement_and_preserves_history(): void
    {
        $user = $this->jobSeeker();
        [$a, $b] = [$this->cv($user, 'a.pdf'), $this->cv($user, 'b.pdf')];
        $user->jobSeekerProfile->update(['primary_cv_file_id' => $a->id]);
        $application = $this->application($user, $a);
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson("/api/v1/cv/{$a->id}/archive")
            ->assertStatus(409)->assertJsonPath('code', 'CV_PRIMARY_REPLACEMENT_REQUIRED');

        $this->withToken($token)->postJson("/api/v1/cv/{$a->id}/archive", ['replacement_cv_file_id' => $b->id])
            ->assertOk()->assertJsonPath('data.is_archived', true);

        $this->assertSame($b->id, $user->jobSeekerProfile->refresh()->primary_cv_file_id);
        $this->assertSame($a->id, $application->refresh()->selected_cv_file_id);
    }

    public function test_restore_becomes_primary_only_when_pointer_is_empty(): void
    {
        $user = $this->jobSeeker();
        $cv = $this->cv($user, 'archived.pdf', ['archived_at' => now()]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/restore")
            ->assertOk()->assertJsonPath('data.is_primary', true);

        $this->assertSame($cv->id, $user->jobSeekerProfile->refresh()->primary_cv_file_id);
    }

    public function test_application_uses_primary_when_no_cv_is_explicit(): void
    {
        $user = $this->jobSeeker();
        $primary = $this->cv($user, 'primary.pdf');
        $user->jobSeekerProfile->update(['primary_cv_file_id' => $primary->id]);
        $job = $this->job();

        $response = $this->withToken($this->tokenFor($user))->postJson("/api/v1/applications/{$job->id}", [
            'consent_to_share_profile' => true,
        ])->assertCreated()->assertJsonPath('data.selected_cv_file_id', $primary->id);

        $this->assertSame($primary->id, $response->json('data.selected_cv.id'));
    }

    public function test_application_rejects_archived_and_missing_files_without_side_effects(): void
    {
        $archivedUser = $this->jobSeeker();
        $archived = $this->cv($archivedUser, 'archived.pdf', ['archived_at' => now()]);
        $this->withToken($this->tokenFor($archivedUser))->postJson("/api/v1/applications/{$this->job()->id}", [
            'cv_file_id' => $archived->id, 'consent' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'CV_ARCHIVED');

        $missingUser = $this->jobSeeker();
        $missing = $this->cv($missingUser, 'missing.pdf');
        Storage::disk('local')->delete($missing->stored_path);
        $this->withToken($this->tokenFor($missingUser))->postJson("/api/v1/applications/{$this->job()->id}", [
            'cv_file_id' => $missing->id, 'consent' => true,
        ])->assertNotFound()->assertJsonPath('code', 'CV_FILE_UNAVAILABLE');

        $this->assertDatabaseCount('job_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_archiving_only_primary_clears_pointer_and_label_update_is_scoped(): void
    {
        $user = $this->jobSeeker();
        $cv = $this->cv($user, 'only.pdf');
        $user->jobSeekerProfile->update(['primary_cv_file_id' => $cv->id]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->patchJson("/api/v1/cv/{$cv->id}", ['version_label' => '  Product CV  '])
            ->assertOk()->assertJsonPath('data.version_label', 'Product CV');
        $this->withToken($token)->postJson("/api/v1/cv/{$cv->id}/archive")->assertOk();

        $this->assertNull($user->jobSeekerProfile->refresh()->primary_cv_file_id);
        $this->assertNotNull($cv->refresh()->archived_at);
    }

    public function test_archived_cv_is_read_only_but_remains_downloadable_to_owner(): void
    {
        $user = $this->jobSeeker();
        $cv = $this->cv($user, 'history.pdf', ['archived_at' => now(), 'status' => 'parsed']);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertStatus(409)->assertJsonPath('code', 'CV_ARCHIVED_READ_ONLY');
        $this->withToken($this->tokenFor($user))->get("/api/v1/cv/{$cv->id}/download")
            ->assertOk()->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_list_hides_archived_by_default_and_never_exposes_storage_metadata(): void
    {
        $user = $this->jobSeeker();
        $this->cv($user, 'active.pdf');
        $this->cv($user, 'archived.pdf', ['archived_at' => now()]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/cv')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonMissingPath('data.data.0.stored_path')->assertJsonMissingPath('data.data.0.disk');
        $this->withToken($token)->getJson('/api/v1/cv?include_archived=true')->assertOk()->assertJsonCount(2, 'data.data');
    }

    private function jobSeeker(): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id]);
        return $user->load('jobSeekerProfile');
    }

    private function cv(User $user, string $name, array $extra = []): CVFile
    {
        $cv = CVFile::create(array_merge([
            'user_id' => $user->id, 'original_name' => $name, 'stored_path' => "cv-files/{$user->id}/{$name}",
            'disk' => 'local', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 100,
            'status' => 'parsed',
        ], $extra));
        Storage::disk('local')->put($cv->stored_path, 'cv');
        return $cv;
    }

    private function job(): JobPosting
    {
        $company = Company::create(['name' => 'CV Lifecycle Co', 'approval_status' => 'approved']);
        return JobPosting::create(['company_id' => $company->id, 'title' => 'Engineer', 'description' => 'Build',
            'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'location' => 'Remote',
            'status' => 'open', 'published_at' => now()]);
    }

    private function application(User $user, CVFile $cv): \App\Models\JobApplication
    {
        return \App\Models\JobApplication::create(['job_posting_id' => $this->job()->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id, 'selected_cv_file_id' => $cv->id,
            'application_status_id' => ApplicationStatus::where('slug', 'submitted')->value('id'),
            'consent_to_share_profile' => true]);
    }

    private function tokenFor(User $user): string { return $user->createToken(Str::random(10))->plainTextToken; }
}
