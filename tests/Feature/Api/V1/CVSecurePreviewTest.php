<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CVSecurePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_can_preview_current_pdf_with_private_inline_headers_and_full_streaming(): void
    {
        [$user, $profile] = $this->candidate();
        $cv = $this->cv($user, ['confirmed_at' => now()], '%PDF-current');
        $profile->update(['primary_cv_file_id' => $cv->id]);

        $response = $this->withToken($this->tokenFor($user))
            ->withHeader('Range', 'bytes=0-3')
            ->get("/api/v1/cv/{$cv->id}/preview")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('pragma', 'no-cache')
            ->assertHeader('accept-ranges', 'none');

        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertStringNotContainsString("\r", (string) $response->headers->get('content-disposition'));
        $this->assertStringNotContainsString("\n", (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertSame('%PDF-current', $response->streamedContent());
        $this->assertStringNotContainsString($cv->stored_path, $response->streamedContent());
        $this->assertSame('parsed', $cv->refresh()->status);
    }

    public function test_owner_can_preview_an_active_pending_pdf(): void
    {
        [$user] = $this->candidate();
        $cv = $this->cv($user, [
            'status' => 'processing',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
        ], '%PDF-pending');

        $this->withToken($this->tokenFor($user))
            ->get("/api/v1/cv/{$cv->id}/preview")
            ->assertOk()
            ->assertStreamedContent('%PDF-pending');

        $this->assertNull($cv->refresh()->confirmed_at);
    }

    public function test_pdf_preview_streams_from_an_s3_compatible_disk(): void
    {
        Storage::fake('s3');
        [$user] = $this->candidate();
        $cv = $this->cv($user, ['disk' => 's3'], '%PDF-s3');

        $this->withToken($this->tokenFor($user))
            ->get("/api/v1/cv/{$cv->id}/preview")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertStreamedContent('%PDF-s3');
    }

    public function test_docx_preview_is_typed_unsupported_but_download_remains_attachment(): void
    {
        [$user] = $this->candidate();
        $cv = $this->cv($user, [
            'original_name' => 'Resume.docx',
            'stored_path' => 'cv-files/'.Str::uuid().'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
        ], 'docx-content');
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson("/api/v1/cv/{$cv->id}/preview")
            ->assertStatus(415)
            ->assertJsonPath('code', 'CV_PREVIEW_NOT_SUPPORTED')
            ->assertJsonPath('statusCode', 415)
            ->assertJsonPath('data.allowed_actions', ['download'])
            ->assertJsonMissingPath('data.stored_path');

        $response = $this->withToken($token)->get("/api/v1/cv/{$cv->id}/download")
            ->assertOk()
            ->assertStreamedContent('docx-content');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_preview_enforces_owner_role_and_active_workflow(): void
    {
        [$owner, $profile] = $this->candidate();
        $current = $this->cv($owner, ['confirmed_at' => now()], '%PDF-owner');
        $profile->update(['primary_cv_file_id' => $current->id]);

        $this->getJson("/api/v1/cv/{$current->id}/preview")->assertUnauthorized();

        [$other] = $this->candidate();
        $this->withToken($this->tokenFor($other))
            ->getJson("/api/v1/cv/{$current->id}/preview")
            ->assertNotFound();

        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            $this->app['auth']->forgetGuards();
            $this->withToken($this->tokenFor($viewer))
                ->getJson("/api/v1/cv/{$current->id}/preview")
                ->assertForbidden();
        }

        $cancelled = $this->cv($owner, ['cancelled_at' => now()], '%PDF-cancelled');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($owner))
            ->getJson("/api/v1/cv/{$cancelled->id}/preview")
            ->assertForbidden()
            ->assertJsonPath('code', 'CV_PREVIEW_FORBIDDEN');
    }

    public function test_missing_and_empty_files_return_safe_distinct_errors(): void
    {
        [$user, $profile] = $this->candidate();
        $missing = $this->cv($user, ['confirmed_at' => now()], null);
        $profile->update(['primary_cv_file_id' => $missing->id]);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/cv/{$missing->id}/preview")
            ->assertNotFound()
            ->assertJsonPath('code', 'CV_FILE_NOT_FOUND')
            ->assertJsonMissingPath('errors.path');

        $empty = $this->cv($user, [
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
        ], '');
        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/cv/{$empty->id}/preview")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CV_FILE_UNAVAILABLE');
    }

    /** @return array{User, JobSeekerProfile} */
    private function candidate(): array
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);

        return [$user, $profile];
    }

    /** @param array<string, mixed> $overrides */
    private function cv(User $user, array $overrides = [], ?string $contents = '%PDF-file'): CVFile
    {
        $cv = CVFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => "../Backend\r\nDeveloper CV.pdf",
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => max(1, strlen((string) $contents)),
            'status' => 'parsed',
        ], $overrides));

        if ($contents !== null) {
            Storage::disk($cv->disk)->put($cv->stored_path, $contents);
        }

        return $cv;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('preview-test')->plainTextToken;
    }
}
