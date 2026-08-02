<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\Test as RecruitmentTest;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\OptionalImageService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OptionalImageFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_avatar_is_optional_nullable_and_can_be_uploaded_replaced_and_removed_by_its_user(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $token = $this->token($user);

        $this->deleteJson('/api/v1/profile/avatar')->assertUnauthorized();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->withToken($token)->patchJson('/api/v1/profile/avatar', [])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(fn ($json) => $json->whereType('data.avatar_url', 'string')->etc());

        $firstPath = $user->refresh()->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('replacement.webp', 180, 180),
        ], ['Accept' => 'application/json'])->assertOk();

        $secondPath = $user->refresh()->avatar_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->withToken($token)->deleteJson('/api/v1/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);
        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($secondPath);

        $this->withToken($token)->deleteJson('/api/v1/profile/avatar')->assertOk();
    }

    public function test_avatar_rejects_non_images_fake_mime_and_oversized_files(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->create('fake.jpg', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->withToken($token)->post('/api/v1/profile/avatar', [
            'image' => UploadedFile::fake()->image('large.jpg')->size(2049),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_company_cover_is_independent_from_logo_and_company_permissions_are_reused(): void
    {
        [$owner, $company] = $this->employerCompany(CompanyRole::OWNER);
        $company->update(['logo_path' => 'company-logos/existing.png']);
        Storage::disk('public')->put('company-logos/existing.png', 'logo');

        $this->withToken($this->token($owner))->post('/api/v1/company/cover-image', [
            'image' => UploadedFile::fake()->image('cover.jpg', 1200, 400),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.logo_url', Storage::disk('public')->url('company-logos/existing.png'))
            ->assertJson(fn ($json) => $json->whereType('data.cover_image_url', 'string')->etc());

        $coverPath = $company->refresh()->cover_image_path;
        $this->assertSame('company-logos/existing.png', $company->logo_path);
        Storage::disk('public')->assertExists($coverPath);

        $this->withToken($this->token($owner))->putJson('/api/v1/company', ['description' => 'No image sent'])
            ->assertOk();
        $this->assertSame($coverPath, $company->refresh()->cover_image_path);

        [, $otherCompany] = $this->employerCompany(CompanyRole::OWNER);
        $interviewer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create([
            'user_id' => $interviewer->id,
            'company_id' => $otherCompany->id,
            'company_role' => CompanyRole::INTERVIEWER,
        ]);
        $this->resetAuth();
        $this->withToken($this->token($interviewer))->deleteJson('/api/v1/company/cover-image')->assertForbidden();

        $this->resetAuth();
        $this->withToken($this->token($owner))->deleteJson('/api/v1/company/cover-image')
            ->assertOk()
            ->assertJsonPath('data.cover_image_url', null);
        $this->assertSame('company-logos/existing.png', $company->refresh()->logo_path);
        Storage::disk('public')->assertExists('company-logos/existing.png');
        Storage::disk('public')->assertMissing($coverPath);
    }

    public function test_only_admin_can_manage_skill_icons_and_legacy_skills_return_null(): void
    {
        $skill = Skill::create(['name' => 'Backend', 'slug' => 'backend']);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $regular = User::factory()->create(['role' => UserRole::JOB_SEEKER]);

        $this->getJson('/api/v1/skills')->assertOk()->assertJsonPath('data.0.icon_url', null);
        $this->withToken($this->token($regular))->post('/api/v1/admin/skills/'.$skill->id.'/icon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->resetAuth();
        $this->withToken($this->token($admin))->post('/api/v1/admin/skills/'.$skill->id.'/icon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(fn ($json) => $json->whereType('data.icon_url', 'string')->etc());

        $iconPath = $skill->refresh()->icon_path;
        Storage::disk('public')->assertExists($iconPath);

        $this->withToken($this->token($admin))->deleteJson('/api/v1/admin/skills/'.$skill->id.'/icon')
            ->assertOk()
            ->assertJsonPath('data.icon_url', null);
        Storage::disk('public')->assertMissing($iconPath);
        $this->withToken($this->token($admin))->deleteJson('/api/v1/admin/skills/'.$skill->id.'/icon')->assertOk();
    }

    public function test_question_image_is_private_replaceable_and_company_scoped(): void
    {
        [$manager, $company] = $this->employerCompany(CompanyRole::COMPANY_ADMIN);
        [$test, $question] = $this->questionScenario($company);
        [$otherManager, $otherCompany] = $this->employerCompany(CompanyRole::COMPANY_ADMIN);
        [$otherTest] = $this->questionScenario($otherCompany);
        $token = $this->token($manager);

        $this->get("/api/v1/tests/{$test->id}/questions/{$question->id}/image", ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $this->withToken($token)->getJson("/api/v1/tests/{$test->id}/questions/{$question->id}")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        $this->withToken($token)->post("/api/v1/tests/{$test->id}/questions/{$question->id}/image", [
            'image' => UploadedFile::fake()->image('diagram.png', 600, 400),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(fn ($json) => $json->whereType('data.image_url', 'string')->etc());

        $firstPath = $question->refresh()->image_path;
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('public')->assertMissing($firstPath);

        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$question->id}", [
            'question_text' => 'Text update keeps image',
        ])->assertOk();
        $this->assertSame($firstPath, $question->refresh()->image_path);

        $this->withToken($token)->post("/api/v1/tests/{$test->id}/questions/{$question->id}/image", [
            'image' => UploadedFile::fake()->image('replacement.webp', 500, 300),
        ], ['Accept' => 'application/json'])->assertOk();
        $secondPath = $question->refresh()->image_path;
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        $this->resetAuth();
        $this->withToken($this->token($otherManager))
            ->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}/image")
            ->assertForbidden();
        $this->resetAuth();
        $this->withToken($token)
            ->get("/api/v1/tests/{$test->id}/questions/{$question->id}/image", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);
        Storage::disk('local')->assertMissing($secondPath);
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}/image")->assertOk();

        $this->assertNull($otherTest->questions()->first()?->image_path);
    }

    public function test_resource_specific_image_types_and_size_limits_are_enforced(): void
    {
        [$owner, $company] = $this->employerCompany(CompanyRole::OWNER);
        $this->withToken($this->token($owner))->post('/api/v1/company/cover-image', [
            'image' => UploadedFile::fake()->image('cover.jpg')->size(5121),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        [$test, $question] = $this->questionScenario($company);
        $this->withToken($this->token($owner))->post("/api/v1/tests/{$test->id}/questions/{$question->id}/image", [
            'image' => UploadedFile::fake()->create('fake.png', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $skill = Skill::create(['name' => 'Frontend', 'slug' => 'frontend']);
        $this->resetAuth();
        $this->withToken($this->token($admin))->post("/api/v1/admin/skills/{$skill->id}/icon", [
            'image' => UploadedFile::fake()->image('icon.webp')->size(2049),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_authorized_candidate_receives_attempt_scoped_question_image_only_after_start(): void
    {
        [$employer, $company] = $this->employerCompany(CompanyRole::OWNER);
        [$test, $question] = $this->questionScenario($company);
        $this->withToken($this->token($employer))->post("/api/v1/tests/{$test->id}/questions/{$question->id}/image", [
            'image' => UploadedFile::fake()->image('prompt.png', 300, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Engineer',
            'description' => 'APIs',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id'),
        ]);
        $assignmentId = $this->withToken($this->token($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", ['test_id' => $test->id])
            ->assertCreated()
            ->json('data.id');
        $this->resetAuth();
        $candidateToken = $this->token($candidate);
        $attemptId = $this->withToken($candidateToken)
            ->postJson("/api/v1/tests/{$assignmentId}/start")
            ->assertCreated()
            ->json('data.id');

        $response = $this->withToken($candidateToken)
            ->getJson("/api/v1/test-attempts/{$attemptId}/questions")
            ->assertOk()
            ->assertJsonMissingPath('data.0.points')
            ->assertJson(fn ($json) => $json->whereType('data.0.image_url', 'string')->etc());
        $imageUrl = (string) $response->json('data.0.image_url');

        $this->withToken($candidateToken)->get($imageUrl, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $otherCandidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $otherCandidate->id]);
        $this->resetAuth();
        $this->withToken($this->token($otherCandidate))->get($imageUrl, ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_database_failure_cleans_new_public_and_private_files(): void
    {
        $user = User::factory()->create();
        Event::listen('eloquent.updating: '.User::class, static function (): void {
            throw new RuntimeException('Forced public image database failure.');
        });

        try {
            app(OptionalImageService::class)->updateAvatar(
                $user,
                UploadedFile::fake()->image('avatar.png'),
            );
            $this->fail('Expected the public image update to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced public image database failure.', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('public')->allFiles());

        Event::forget('eloquent.updating: '.User::class);
        [$manager, $company] = $this->employerCompany(CompanyRole::OWNER);
        [$test, $question] = $this->questionScenario($company);
        Event::listen('eloquent.updating: '.TestQuestion::class, static function (): void {
            throw new RuntimeException('Forced private image database failure.');
        });

        try {
            app(OptionalImageService::class)->updateQuestionImage(
                $manager,
                $test,
                $question,
                UploadedFile::fake()->image('question.png'),
            );
            $this->fail('Expected the private image update to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced private image database failure.', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** @return array{User, Company} */
    private function employerCompany(CompanyRole $role): array
    {
        $company = Company::create([
            'name' => 'Image Company '.Str::random(5),
            'approval_status' => 'approved',
        ]);
        $user = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'company_role' => $role,
        ]);

        return [$user->load('employerProfile.company'), $company];
    }

    /** @return array{RecruitmentTest, TestQuestion} */
    private function questionScenario(Company $company): array
    {
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => 'Image Test '.Str::random(5),
            'duration_minutes' => 30,
            'max_score' => 5,
            'passing_score' => 3,
            'is_active' => true,
        ]);
        $question = TestQuestion::create([
            'test_id' => $test->id,
            'question_text' => 'Explain the diagram.',
            'question_type' => 'long_text',
            'order_index' => 1,
            'points' => 5,
            'is_required' => true,
        ]);

        return [$test->load('company'), $question];
    }

    private function token(User $user): string
    {
        return $user->createToken('optional-image-'.Str::random(5))->plainTextToken;
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
