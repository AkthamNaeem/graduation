<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_can_generate_suggestions_from_parsed_cv(): void
    {
        $user = $this->jobSeeker();
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'entity_type' => 'experience',
                'suggestion_type' => 'add',
                'status' => 'pending',
            ])
            ->assertJsonFragment([
                'entity_type' => 'skill',
                'suggestion_type' => 'add',
            ]);

        $this->assertDatabaseCount('profile_change_suggestions', 4);
    }

    public function test_suggestions_are_not_applied_automatically(): void
    {
        $user = $this->jobSeeker();
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated();

        $this->assertDatabaseCount('experiences', 0);
        $this->assertDatabaseCount('education', 0);
        $this->assertDatabaseCount('job_seeker_skills', 0);
        $this->assertNull($cvFile->refresh()->confirmed_at);
    }

    public function test_accepting_one_suggestion_applies_only_that_suggestion(): void
    {
        $user = $this->jobSeeker();
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated();

        $experienceSuggestion = ProfileChangeSuggestion::query()
            ->where('entity_type', 'experience')
            ->firstOrFail();

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/profile/suggestions/{$experienceSuggestion->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $this->assertDatabaseHas('experiences', [
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Northwind Software',
        ]);
        $this->assertDatabaseCount('education', 0);
        $this->assertDatabaseCount('job_seeker_skills', 0);
    }

    public function test_rejecting_a_suggestion_does_not_modify_profile(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated();

        $suggestion = ProfileChangeSuggestion::query()
            ->where('entity_type', 'experience')
            ->firstOrFail();

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject", [
                'reason' => 'Not relevant.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Not relevant.');

        $this->assertDatabaseCount('experiences', 0);
    }

    public function test_duplicate_experience_is_not_blindly_duplicated(): void
    {
        $user = $this->jobSeeker();
        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Northwind Software',
            'description' => 'Existing manual description.',
        ]);
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated()
            ->assertJsonFragment([
                'entity_type' => 'experience',
                'suggestion_type' => 'ignore',
            ]);

        $suggestion = ProfileChangeSuggestion::query()
            ->where('entity_type', 'experience')
            ->firstOrFail();

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")
            ->assertOk();

        $this->assertDatabaseCount('experiences', 1);
    }

    public function test_manual_profile_data_is_not_overwritten_automatically(): void
    {
        $user = $this->jobSeeker();
        $user->jobSeekerProfile->update(['phone' => '+1 555 EXISTING']);
        $cvFile = $this->parsedCVFor($user);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")
            ->assertCreated()
            ->assertJsonFragment([
                'entity_type' => 'profile',
                'suggestion_type' => 'ignore',
            ]);

        $this->assertDatabaseHas('job_seeker_profiles', [
            'id' => $user->jobSeekerProfile->id,
            'phone' => '+1 555 EXISTING',
        ]);
    }

    public function test_user_cannot_access_another_users_cv_suggestions(): void
    {
        $owner = $this->jobSeeker('owner@example.com');
        $other = $this->jobSeeker('other@example.com');
        $cvFile = $this->parsedCVFor($owner);

        $this->withToken($this->tokenFor($other))
            ->getJson("/api/v1/cv/{$cvFile->id}/suggestions")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_user_cannot_apply_another_users_suggestion(): void
    {
        $owner = $this->jobSeeker('owner@example.com');
        $other = $this->jobSeeker('other@example.com');
        $cvFile = $this->parsedCVFor($owner);

        $suggestion = ProfileChangeSuggestion::create([
            'user_id' => $owner->id,
            'cv_file_id' => $cvFile->id,
            'job_seeker_profile_id' => $owner->jobSeekerProfile->id,
            'entity_type' => 'experience',
            'suggestion_type' => 'add',
            'status' => 'pending',
            'source' => 'cv_parsed',
            'new_value' => [
                'title' => 'Backend Developer',
                'company_name' => 'Northwind Software',
            ],
        ]);

        $this->withToken($this->tokenFor($other))
            ->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
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

    private function parsedCVFor(User $user): CVFile
    {
        $cvFile = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cv-files/'.$user->id.'/resume.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'parsed',
        ]);

        CVParsingResult::create([
            'cv_file_id' => $cvFile->id,
            'raw_text' => 'Parsed CV text',
            'parsed_json' => [
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

        return $cvFile;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
