<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\CV\CVDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CurrentCVDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->app->bind(CVDocumentRenderer::class, static fn () => new class extends CVDocumentRenderer
        {
            public function render(array $data): string
            {
                return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        });
    }

    public function test_job_seeker_can_preview_and_download_the_same_generated_current_profile_cv(): void
    {
        [$user] = $this->confirmedProfile();
        $token = $this->tokenFor($user);

        $preview = $this->withToken($token)->get('/api/v1/profile/cv/preview')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('accept-ranges', 'none');
        $this->assertStringContainsString('inline;', (string) $preview->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('cache-control'));

        $this->app['auth']->forgetGuards();
        $download = $this->withToken($token)->get('/api/v1/profile/cv/download')->assertOk();
        $this->assertStringContainsString('attachment;', (string) $download->headers->get('content-disposition'));
        $this->assertSame($preview->getContent(), $download->getContent());

        $data = json_decode($preview->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Structured Candidate', $data['name']);
        $this->assertSame('Confirmed database summary', $data['summary']);
        $this->assertSame('Backend Engineer', $data['experiences'][0]['title']);
        $this->assertSame('Workey', $data['experiences'][0]['company']);
        $this->assertSame('Damascus University', $data['education'][0]['institution']);
        $this->assertSame(['Laravel'], $data['skills']);
        $this->assertStringNotContainsString('ORIGINAL-UPLOAD-CONTENT', $preview->getContent());
    }

    public function test_generated_current_cv_reflects_later_structured_profile_updates(): void
    {
        [$user, $profile] = $this->confirmedProfile();
        $token = $this->tokenFor($user);

        $before = json_decode(
            $this->withToken($token)->get('/api/v1/profile/cv/preview')->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $profile->update(['summary' => 'Updated confirmed profile summary']);

        $this->app['auth']->forgetGuards();
        $after = json_decode(
            $this->withToken($token)->get('/api/v1/profile/cv/preview')->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('Confirmed database summary', $before['summary']);
        $this->assertSame('Updated confirmed profile summary', $after['summary']);
    }

    public function test_current_cv_document_requires_the_authenticated_owners_confirmed_cv(): void
    {
        $this->getJson('/api/v1/profile/cv/preview')->assertUnauthorized();

        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/profile/cv/preview')
            ->assertForbidden();

        $otherCandidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $otherCandidate->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($otherCandidate))
            ->getJson('/api/v1/profile/cv/download')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PRIMARY_CV_REQUIRED');
    }

    public function test_profile_exposes_one_logical_cv_state_without_version_management_actions(): void
    {
        [$user] = $this->confirmedProfile();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.cv.status.key', 'confirmed')
            ->assertJsonPath('data.cv.is_ready', true)
            ->assertJsonPath('data.cv.allowed_actions', ['preview_cv', 'download_cv', 'update_cv'])
            ->assertJsonPath('data.cv.pending_update', null);

        foreach (['manage_versions', 'make_primary', 'archive_cv', 'restore_cv'] as $action) {
            $this->assertNotContains($action, $response->json('data.cv.allowed_actions'));
        }
    }

    public function test_document_generation_failure_uses_the_normal_domain_error_contract(): void
    {
        [$user] = $this->confirmedProfile();
        $this->app->bind(CVDocumentRenderer::class, static fn () => new class extends CVDocumentRenderer
        {
            public function render(array $data): string
            {
                throw new RuntimeException('Internal renderer details');
            }
        });

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile/cv/preview')
            ->assertStatus(500)
            ->assertJsonPath('code', 'CV_DOCUMENT_GENERATION_FAILED')
            ->assertJsonMissing(['Internal renderer details']);
    }

    /** @return array{User, JobSeekerProfile, CVFile} */
    private function confirmedProfile(): array
    {
        $user = User::factory()->create([
            'name' => 'Structured Candidate',
            'email' => 'structured@example.com',
            'role' => UserRole::JOB_SEEKER,
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Platform Developer',
            'summary' => 'Confirmed database summary',
            'phone' => '+963111111',
            'location' => 'Damascus',
            'linkedin_url' => 'https://linkedin.com/in/structured',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Backend Engineer',
            'company_name' => 'Workey',
            'start_date' => '2023-01-01',
            'is_current' => true,
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
            'degree' => 'BSc',
        ]);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel-'.Str::lower(Str::random(6))]);
        $profile->skills()->attach($skill->id);

        $cv = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'old-source.docx',
            'stored_path' => 'cv-files/'.Str::uuid().'.docx',
            'disk' => 'local',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => strlen('ORIGINAL-UPLOAD-CONTENT'),
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now(),
        ]);
        Storage::disk('local')->put($cv->stored_path, 'ORIGINAL-UPLOAD-CONTENT');
        $profile->update(['primary_cv_file_id' => $cv->id]);

        return [$user->load('jobSeekerProfile'), $profile, $cv];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
