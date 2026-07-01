<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\ParseCVFileJob;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\EmployerProfile;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\CVParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CVTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_can_upload_cv_and_queue_parsing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->jobSeeker();

        $response = $this->withToken($this->tokenFor($user))
            ->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->create('resume.pdf', 256, 'application/pdf'),
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.original_name', 'resume.pdf')
            ->assertJsonPath('data.status', 'uploaded');

        $cvId = $response->json('data.id');
        $cvFile = CVFile::query()->findOrFail($cvId);

        Storage::disk('local')->assertExists($cvFile->stored_path);
        Queue::assertPushed(ParseCVFileJob::class);
    }

    public function test_cv_upload_validates_file_type_and_size(): void
    {
        Storage::fake('local');
        $user = $this->jobSeeker();

        $this->withToken($this->tokenFor($user))
            ->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->create('resume.txt', 100, 'text/plain'),
            ], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['file'],
            ]);
    }

    public function test_employer_cannot_access_cv_endpoints(): void
    {
        $user = $this->employer();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/cv')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_job_seeker_cv_list_is_paginated(): void
    {
        $user = $this->jobSeeker();
        $this->cvFileFor($user, ['original_name' => 'first.pdf']);
        $this->cvFileFor($user, ['original_name' => 'second.pdf']);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/cv?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_user_cannot_access_another_users_cv(): void
    {
        $user = $this->jobSeeker('owner@example.com');
        $otherUser = $this->jobSeeker('other@example.com');
        $otherCV = $this->cvFileFor($otherUser);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/cv/{$otherCV->id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_parsing_job_saves_result_and_updates_status(): void
    {
        Storage::fake('local');
        $user = $this->jobSeeker();
        $cvFile = $this->cvFileFor($user, [
            'stored_path' => 'cv-files/'.$user->id.'/resume.pdf',
        ]);

        Storage::disk('local')->put($cvFile->stored_path, 'fake pdf content');

        $parser = Mockery::mock(CVParsingService::class);
        $parser->shouldReceive('extractText')
            ->once()
            ->andReturn("Jane Applicant\njane@example.com\nLaravel");
        $parser->shouldReceive('parseText')
            ->once()
            ->andReturn([
                'email' => 'jane@example.com',
                'phone' => null,
                'skills' => ['Laravel'],
                'experience' => [],
                'education' => [],
            ]);

        (new ParseCVFileJob($cvFile))->handle($parser);

        $this->assertDatabaseHas('cv_files', [
            'id' => $cvFile->id,
            'status' => 'parsed',
            'error_message' => null,
        ]);
        $this->assertDatabaseHas('cv_parsing_results', [
            'cv_file_id' => $cvFile->id,
            'raw_text' => "Jane Applicant\njane@example.com\nLaravel",
        ]);
    }

    public function test_parsed_endpoint_returns_404_before_result_exists(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->cvFileFor($user);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/cv/{$cvFile->id}/parsed")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_confirm_generates_review_suggestions_without_applying_profile_data(): void
    {
        $user = $this->jobSeeker();
        $user->jobSeekerProfile->update(['phone' => '+1 555 EXISTING']);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $cvFile = $this->cvFileFor($user, ['status' => 'parsed']);

        CVParsingResult::create([
            'cv_file_id' => $cvFile->id,
            'raw_text' => 'Parsed CV text',
            'parsed_json' => [
                'email' => 'parsed@example.com',
                'phone' => '+1 555 PARSED',
                'skills' => ['Laravel'],
                'experience' => [
                    [
                        'title' => 'Backend Developer',
                        'company_name' => 'Northwind Software',
                        'description' => 'Backend Developer at Northwind Software',
                    ],
                ],
                'education' => [
                    [
                        'institution' => 'State University',
                        'degree' => 'Bachelor of Science',
                        'field_of_study' => 'Computer Science',
                        'description' => 'Bachelor of Science in Computer Science, State University',
                    ],
                ],
            ],
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/confirm")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.phone', '+1 555 EXISTING')
            ->assertJsonFragment([
                'entity_type' => 'experience',
                'suggestion_type' => 'add',
                'status' => 'pending',
            ])
            ->assertJsonFragment([
                'entity_type' => 'skill',
                'suggestion_type' => 'add',
                'new_value' => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'slug' => $skill->slug,
                ],
            ]);

        $this->assertDatabaseHas('cv_files', [
            'id' => $cvFile->id,
        ]);
        $this->assertNull($cvFile->refresh()->confirmed_at);
        $this->assertDatabaseCount('experiences', 0);
        $this->assertDatabaseCount('education', 0);
        $this->assertDatabaseCount('job_seeker_skills', 0);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/confirm")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('profile_change_suggestions', 4);
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

    private function employer(): User
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $user = User::factory()->create([
            'role' => UserRole::EMPLOYER,
        ]);

        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cvFileFor(User $user, array $overrides = []): CVFile
    {
        return CVFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cv-files/'.$user->id.'/resume.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'uploaded',
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
