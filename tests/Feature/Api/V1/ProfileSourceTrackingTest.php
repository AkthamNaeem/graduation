<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use App\Services\ProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSourceTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_profile_entries_are_marked_as_manual_and_verified(): void
    {
        $jobSeeker = $this->jobSeeker();

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson('/api/v1/profile/experiences', [
                'title' => 'Backend Developer',
                'company_name' => 'Acme',
                'start_date' => '2024-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'manual')
            ->assertJsonPath('data.source_cv_file_id', null);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson('/api/v1/profile/education', [
                'institution' => 'Damascus University',
                'degree' => 'Bachelor',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'manual')
            ->assertJsonPath('data.source_cv_file_id', null);

        $this->assertDatabaseHas('experiences', [
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'title' => 'Backend Developer',
            'source_type' => 'manual',
            'source_cv_file_id' => null,
        ]);

        $this->assertDatabaseHas('education', [
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'institution' => 'Damascus University',
            'source_type' => 'manual',
            'source_cv_file_id' => null,
        ]);
    }

    public function test_accepted_cv_suggestions_are_marked_as_cv_confirmed(): void
    {
        $jobSeeker = $this->jobSeeker();
        $cvFile = $this->cvWithParsedData($jobSeeker);

        /** @var ProfileSyncService $service */
        $service = app(ProfileSyncService::class);
        $suggestions = $service->generateSuggestionsFromParsedCV($jobSeeker, $cvFile);

        $suggestions->each(fn (ProfileChangeSuggestion $suggestion) => $service->accept($jobSeeker, $suggestion));

        $this->assertDatabaseHas('experiences', [
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'title' => 'Laravel Developer',
            'source_type' => 'cv_confirmed',
            'source_cv_file_id' => $cvFile->id,
        ]);

        $this->assertDatabaseHas('education', [
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'institution' => 'Tishreen University',
            'source_type' => 'cv_confirmed',
            'source_cv_file_id' => $cvFile->id,
        ]);

        $skill = Skill::query()->where('slug', 'php')->firstOrFail();

        $this->assertDatabaseHas('job_seeker_skills', [
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'skill_id' => $skill->id,
            'source_type' => 'cv_confirmed',
            'source_cv_file_id' => $cvFile->id,
        ]);
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

    private function cvWithParsedData(User $jobSeeker): CVFile
    {
        $cvFile = CVFile::create([
            'user_id' => $jobSeeker->id,
            'original_name' => 'backend-cv.pdf',
            'stored_path' => 'cv-files/backend-cv.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 128000,
            'status' => 'parsed',
        ]);

        CVParsingResult::create([
            'cv_file_id' => $cvFile->id,
            'raw_text' => 'Laravel Developer PHP Tishreen University',
            'parsed_json' => [
                'experience' => [
                    [
                        'title' => 'Laravel Developer',
                        'company_name' => 'Acme Software',
                        'start_date' => '2024-01-01',
                    ],
                ],
                'education' => [
                    [
                        'institution' => 'Tishreen University',
                        'degree' => 'Bachelor',
                    ],
                ],
                'skills' => ['PHP'],
            ],
        ]);

        return $cvFile->load('parsingResult');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
