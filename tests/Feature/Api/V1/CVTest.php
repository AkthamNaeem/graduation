<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\CVParserException;
use App\Jobs\ParseCVFileJob;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\SyntheticDocx;
use Tests\Support\SyntheticPdf;
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

    public function test_sync_parser_failure_preserves_committed_cv_file_and_primary_pointer(): void
    {
        Storage::fake('local');
        config([
            'filesystems.private_disk' => 'local',
            'queue.default' => 'sync',
        ]);
        $user = $this->jobSeeker();
        $parser = Mockery::mock(CVParsingService::class);
        $parser->shouldReceive('extractText')->once()->andReturn('Candidate CV');
        $parser->shouldReceive('parseText')->once()->andThrow(new CVParserException('OPENAI_AUTHENTICATION_FAILED'));
        $this->app->instance(CVParsingService::class, $parser);

        $this->withToken($this->tokenFor($user))
            ->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->createWithContent('resume.pdf', 'fake pdf content'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $cvFile = CVFile::query()->sole();
        $profile = $user->jobSeekerProfile()->firstOrFail();

        Storage::disk('local')->assertExists($cvFile->stored_path);
        $this->assertSame('failed', $cvFile->status);
        $this->assertSame('OPENAI_AUTHENTICATION_FAILED', $cvFile->error_message);
        $this->assertSame($cvFile->id, $profile->primary_cv_file_id);
        $this->assertDatabaseCount('cv_parsing_results', 0);
        $this->assertDatabaseHas('cv_files', ['id' => $cvFile->id]);
    }

    public function test_synthetic_pdf_upload_recovers_email_and_stores_complete_normalized_groq_draft(): void
    {
        Storage::fake('local');
        config([
            'filesystems.private_disk' => 'local',
            'queue.default' => 'sync',
            'cv.parser.driver' => 'groq',
            'cv.parser.fallback_to_rules' => false,
            'cv.groq.api_key' => 'fake-test-key',
        ]);
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($this->syntheticGroqResult(), JSON_THROW_ON_ERROR)]]],
        ], 200)]);
        $user = $this->jobSeeker('synthetic.owner@example.com');
        $pdf = SyntheticPdf::make($this->syntheticCVLines(), 'mailto:linked.candidate@example.com');

        $response = $this->withToken($this->tokenFor($user))
            ->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->createWithContent('synthetic-resume.pdf', $pdf),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'parsed');

        $cvFile = CVFile::query()->findOrFail($response->json('data.id'));
        $result = $cvFile->parsingResult()->firstOrFail();
        $parsed = $result->parsed_json;

        $this->assertStringContainsString('Email: linked.candidate@example.com', $result->raw_text);
        $this->assertStringContainsString("Bachelor's degree", $result->raw_text);
        $this->assertStringContainsString('CMS customization & plugin development', $result->raw_text);
        $this->assertSame('linked.candidate@example.com', $parsed['email']);
        $this->assertCount(3, $parsed['experience']);
        $this->assertSame('Freelance', $parsed['experience'][2]['company_name']);
        $this->assertCount(1, $parsed['education']);
        $this->assertCount(2, $parsed['languages']);
        $this->assertSame('2002-04-21', $parsed['birth_date']);
        $this->assertSame(['React', 'React Native', 'Expo'], $parsed['skills']);
        $this->assertSame(3, $parsed['_meta']['normalization']['output_counts']['experience']);
        $this->assertSame(1, $parsed['_meta']['normalization']['output_counts']['education']);
        $auditMetadata = AuditLog::query()->where('action', 'cv.parsing_completed')->firstOrFail()->metadata;
        $this->assertSame(3, $auditMetadata['normalization']['output_counts']['experience']);
        $this->assertStringNotContainsString('linked.candidate@example.com', json_encode($auditMetadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Nova Systems', json_encode($auditMetadata, JSON_THROW_ON_ERROR));
        Http::assertSent(fn ($request): bool => str_contains($request['messages'][1]['content'], 'linked.candidate@example.com')
            && str_contains($request['messages'][1]['content'], "Bachelor's degree")
            && ! str_contains($request['messages'][1]['content'], '&#039;'));
    }

    public function test_synthetic_docx_upload_extracts_a_word_email_link_and_stores_the_groq_draft(): void
    {
        Storage::fake('local');
        config([
            'filesystems.private_disk' => 'local',
            'queue.default' => 'sync',
            'cv.parser.driver' => 'groq',
            'cv.parser.fallback_to_rules' => false,
            'cv.groq.api_key' => 'fake-test-key',
        ]);
        $providerResult = $this->syntheticGroqResult();
        $providerResult['email'] = 'synthetic@example.com';
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($providerResult, JSON_THROW_ON_ERROR)]]],
        ], 200)]);
        $user = $this->jobSeeker('synthetic.docx.owner@example.com');

        $response = $this->withToken($this->tokenFor($user))
            ->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->createWithContent('synthetic-resume.docx', SyntheticDocx::make()),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'parsed');

        $cvFile = CVFile::query()->findOrFail($response->json('data.id'));
        $result = $cvFile->parsingResult()->firstOrFail();

        $this->assertStringContainsString('Email: synthetic@example.com', $result->raw_text);
        $this->assertSame('synthetic@example.com', $result->parsed_json['email']);
        Http::assertSent(fn ($request): bool => str_contains($request['messages'][1]['content'], 'Email: synthetic@example.com'));
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

    /** @return array<int, string> */
    private function syntheticCVLines(): array
    {
        return [
            'Synthetic Candidate', 'Email:', 'Birth Date: 21 April 2002',
            'EXPERIENCE', 'January 2026 - Present', 'Laravel Developer', 'Nova Systems', '- Build APIs',
            'October 2024 - December 2025', 'Software Developer', 'Orbit Labs', '- Maintain services',
            'January 2025 - Present', 'Web Developer', 'Freelance', '- CMS customization &amp; plugin development',
            'EDUCATION', 'Bachelor&#039;s degree', 'Information Technology', 'Riverside University', '2020-2026 Expected',
            'SKILLS', 'React, React Native, Expo', 'react',
            'LANGUAGES', 'Arabic: Native', 'English: Intermediate',
        ];
    }

    /** @return array<string, mixed> */
    private function syntheticGroqResult(): array
    {
        $experience = fn (string $title, string $company, string $start, ?string $end, bool $current, string $responsibility): array => [
            'title' => $title, 'company_name' => $company, 'location' => null, 'work_mode' => null,
            'start_date' => $start, 'end_date' => $end, 'is_current' => $current,
            'description' => null, 'responsibilities' => [$responsibility],
            'evidence' => "{$title}\n{$company}\n{$responsibility}", 'confidence_score' => 1,
        ];

        return [
            'full_name' => 'Synthetic Candidate', 'email' => 'linked.candidate@example.com',
            'phone' => null, 'location' => null, 'birth_date' => '21 April 2002', 'summary' => null,
            'experience' => [
                $experience('Laravel Developer', 'Nova Systems', '2026-01', null, true, 'Build APIs'),
                $experience('Software Developer', 'Orbit Labs', '2024-10', '2025-12', false, 'Maintain services'),
                $experience('Web Developer', 'Freelance', '2025-01', null, true, 'CMS customization & plugin development'),
            ],
            'education' => [[
                'degree' => "Bachelor's degree", 'field_of_study' => 'Information Technology',
                'institution' => 'Riverside University', 'start_year' => 2020, 'graduation_year' => 2026,
                'is_expected' => true, 'description' => null,
                'evidence' => "Bachelor's degree Information Technology Riverside University 2020-2026 Expected",
                'confidence_score' => 1,
            ]],
            'skills' => ['React, React Native, Expo', 'react'],
            'languages' => [['name' => 'Arabic', 'level' => 'Native'], ['name' => 'English', 'level' => 'Intermediate']],
        ];
    }
}
