<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use App\Services\CV\CVReviewDraftService;
use App\Services\CV\ProfileDataStateService;
use App\Services\CVParsingService;
use App\Services\CVService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CVReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_meaningful_profile_state_ignores_primary_cv_pointer_but_detects_profile_relations(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->cv($user, null, []);
        $user->jobSeekerProfile->update(['primary_cv_file_id' => $cvFile->id]);

        $service = app(ProfileDataStateService::class);
        $this->assertFalse($service->hasMeaningfulData($user->jobSeekerProfile->refresh()));

        Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Engineer', 'company_name' => 'Acme']);
        $this->assertTrue($service->hasMeaningfulData($user->jobSeekerProfile->refresh()));
    }

    public function test_parse_job_selects_initial_import_once_and_builds_supported_review_draft(): void
    {
        Storage::fake('local');
        $user = $this->jobSeeker();
        $cvFile = $this->cv($user);
        Storage::disk('local')->put($cvFile->stored_path, 'fake pdf');
        $parsed = $this->parsedData();
        $parser = Mockery::mock(CVParsingService::class);
        $parser->shouldReceive('extractText')->once()->andReturn('safe parsed text');
        $parser->shouldReceive('parseText')->once()->andReturn($parsed);

        (new ParseCVFileJob($cvFile))->handle($parser);

        $cvFile->refresh();
        $this->assertSame(CVFile::REVIEW_MODE_INITIAL_IMPORT, $cvFile->review_mode);
        $this->assertSame(CVFile::REVIEW_STATUS_DRAFT, $cvFile->review_status);
        $reviewed = $cvFile->parsingResult->reviewed_json;
        $this->assertSame(['profile', 'experience', 'education', 'skills'], array_keys($reviewed));
        $this->assertArrayNotHasKey('email', $reviewed['profile']);
        $this->assertSame($parsed, $cvFile->parsingResult->parsed_json);

        $cvFile->forceFill(['review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC])->save();
        $secondParser = Mockery::mock(CVParsingService::class);
        $secondParser->shouldReceive('extractText')->once()->andReturn('changed text');
        $secondParser->shouldReceive('parseText')->once()->andReturn(array_merge($parsed, ['phone' => 'changed']));
        (new ParseCVFileJob($cvFile))->handle($secondParser);
        $this->assertSame(CVFile::REVIEW_MODE_PROFILE_SYNC, $cvFile->refresh()->review_mode);
        $this->assertSame($parsed, $cvFile->parsingResult->refresh()->parsed_json);
    }

    public function test_initial_import_draft_is_replaceable_and_confirmed_atomically_without_suggestions(): void
    {
        $user = $this->jobSeeker();
        $originalEmail = $user->email;
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT);
        $originalParsed = $cvFile->parsingResult->parsed_json;
        $draft = $this->draftPayload();

        $this->withToken($this->tokenFor($user))->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)
            ->assertOk()->assertJsonPath('data.reviewed_json.profile.location', 'Damascus');

        $this->assertSame($originalParsed, $cvFile->parsingResult->refresh()->parsed_json);
        $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cvFile->id}/review")
            ->assertOk()->assertJsonPath('data.next_action', 'review_draft')->assertJsonPath('data.can_edit_draft', true);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/confirm")
            ->assertOk()->assertJsonPath('data.profile.location', 'Damascus');

        $this->assertDatabaseHas('experiences', ['title' => 'API Engineer', 'source_type' => 'cv_confirmed', 'source_cv_file_id' => $cvFile->id]);
        $this->assertDatabaseHas('education', ['institution' => 'Damascus University', 'source_type' => 'cv_confirmed']);
        $this->assertDatabaseHas('job_seeker_skills', ['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'source_type' => 'cv_confirmed']);
        $this->assertDatabaseCount('profile_change_suggestions', 0);
        $this->assertSame(CVFile::REVIEW_STATUS_APPLIED, $cvFile->refresh()->review_status);
        $this->assertNotNull($cvFile->confirmed_at);
        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertNotSame('Read Only', $user->name);

        $counts = [Experience::count(), Education::count(), Skill::count()];
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/confirm")->assertStatus(409);
        $this->assertSame($counts, [Experience::count(), Education::count(), Skill::count()]);
    }

    public function test_initial_import_rejects_invalid_dates_and_stale_profile(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT);
        $draft = $this->draftPayload();
        $draft['experience'][0]['start_date'] = '2025-01-01';
        $draft['experience'][0]['end_date'] = '2024-01-01';
        $this->withToken($this->tokenFor($user))->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertUnprocessable();

        $draft = $this->draftPayload();
        $draft['experience'][0]['is_current'] = true;
        $this->withToken($this->tokenFor($user))->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertUnprocessable();

        $user->jobSeekerProfile->update(['phone' => '+963999']);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/confirm")
            ->assertStatus(409)->assertJsonPath('code', 'CV_REVIEW_MODE_STALE');
        $this->assertNull($cvFile->refresh()->confirmed_at);
    }

    public function test_profile_sync_uses_add_update_merge_ignore_then_applies_only_final_decisions(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => '+963111']);
        $profile = $user->jobSeekerProfile;
        $experience = Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Backend Engineer',
            'company_name' => 'Acme',
            'start_date' => '2022-01-01',
        ]);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $profile->skills()->attach($laravel->id);
        $parsed = $this->parsedData();
        $parsed['phone'] = '+963222';
        $parsed['location'] = 'Damascus';
        $parsed['summary'] = 'Reviewed summary';
        $parsed['experience'] = [[
            'title' => 'Backend Engineer', 'company_name' => 'Acme', 'start_date' => '2022-01-01',
            'description' => 'Built APIs.', 'is_current' => false,
        ]];
        $parsed['education'] = [];
        $parsed['skills'] = ['Laravel', 'Redis'];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);

        $response = $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $response->assertJsonFragment(['entity_type' => 'profile', 'suggestion_type' => 'update'])
            ->assertJsonFragment(['entity_type' => 'profile', 'suggestion_type' => 'add'])
            ->assertJsonFragment(['entity_type' => 'experience', 'suggestion_type' => 'merge'])
            ->assertJsonFragment(['entity_type' => 'skill', 'suggestion_type' => 'ignore']);

        $suggestions = ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->get();
        foreach ($suggestions->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE) as $suggestion) {
            $uri = $suggestion->new_value === ['summary' => 'Reviewed summary'] ? 'reject' : 'accept';
            $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/{$uri}")->assertOk();
        }
        $this->assertSame('+963111', $profile->refresh()->phone);
        $this->assertNull($experience->refresh()->description);
        $this->assertSame(CVFile::REVIEW_STATUS_READY_TO_APPLY, $cvFile->refresh()->review_status);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertOk()->assertJsonPath('data.already_applied', false);
        $this->assertSame('+963222', $profile->refresh()->phone);
        $this->assertSame('Damascus', $profile->location);
        $this->assertNull($profile->summary);
        $this->assertSame('Built APIs.', $experience->refresh()->description);
        $this->assertDatabaseHas('skills', ['slug' => 'redis']);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertOk()->assertJsonPath('data.already_applied', true);
    }

    public function test_decisions_are_reversible_edited_values_are_validated_and_stale_apply_rolls_back(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'A']);
        $parsed = $this->parsedData();
        $parsed['phone'] = 'B';
        $parsed['summary'] = null;
        $parsed['location'] = null;
        $parsed['experience'] = [];
        $parsed['education'] = [];
        $parsed['skills'] = [];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $suggestion = ProfileChangeSuggestion::firstOrFail();

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", [
            'edited_value' => ['phone' => 'B2', 'email' => 'blocked@example.test'],
        ])->assertUnprocessable();
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", ['edited_value' => ['phone' => 'B2']])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", ['edited_value' => ['phone' => 'B2']])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $user->jobSeekerProfile->update(['phone' => 'C']);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_STALE')
            ->assertJsonPath('suggestion_id', $suggestion->id)
            ->assertJsonPath('entity_type', 'profile');
        $this->assertSame('C', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame(ProfileChangeSuggestion::STATUS_ACCEPTED, $suggestion->refresh()->status);
        $this->assertNull($cvFile->refresh()->confirmed_at);
    }

    public function test_profile_state_detects_every_supported_scalar_and_relation_independently(): void
    {
        $user = $this->jobSeeker();
        $profile = $user->jobSeekerProfile;
        $service = app(ProfileDataStateService::class);

        foreach (['headline', 'summary', 'phone', 'location', 'portfolio_url', 'linkedin_url', 'github_url'] as $field) {
            $profile->forceFill([$field => 'meaningful'])->save();
            $this->assertTrue($service->hasMeaningfulData($profile->refresh()), $field);
            $profile->forceFill([$field => null])->save();
        }

        $experience = Experience::create(['job_seeker_profile_id' => $profile->id, 'title' => 'Engineer', 'company_name' => 'Acme']);
        $this->assertTrue($service->hasMeaningfulData($profile->refresh()), 'experience');
        $experience->delete();
        $education = Education::create(['job_seeker_profile_id' => $profile->id, 'institution' => 'University']);
        $this->assertTrue($service->hasMeaningfulData($profile->refresh()), 'education');
        $education->delete();
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $profile->skills()->attach($skill->id);
        $this->assertTrue($service->hasMeaningfulData($profile->refresh()), 'skill');
    }

    public function test_replacing_draft_removes_omitted_items_and_deduplicates_skills_case_insensitively(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT);
        $draft = $this->draftPayload();
        $draft['experience'][] = array_merge($draft['experience'][0], ['title' => 'Remove Me']);
        $draft['education'][] = array_merge($draft['education'][0], ['institution' => 'Remove Me']);
        $draft['skills'] = ['PHP', 'php', ' Laravel '];

        $this->withToken($this->tokenFor($user))->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertOk();
        $replacement = $draft;
        array_pop($replacement['experience']);
        array_pop($replacement['education']);

        $response = $this->withToken($this->tokenFor($user))->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $replacement)->assertOk();
        $response->assertJsonCount(1, 'data.reviewed_json.experience')
            ->assertJsonCount(1, 'data.reviewed_json.education')
            ->assertJsonPath('data.reviewed_json.skills', ['PHP', 'Laravel']);
    }

    public function test_initial_import_rolls_back_all_sections_when_a_late_skill_write_fails(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT);
        $cvFile->parsingResult->update(['reviewed_json' => $this->draftPayload()]);
        Skill::creating(function (): void {
            throw new \RuntimeException('synthetic late failure');
        });

        try {
            app(CVService::class)->confirm($user, $cvFile);
            $this->fail('The synthetic late failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic late failure', $exception->getMessage());
        } finally {
            Skill::flushEventListeners();
        }

        $profile = $user->jobSeekerProfile->refresh();
        $this->assertNull($profile->phone);
        $this->assertNull($profile->summary);
        $this->assertNull($profile->location);
        $this->assertDatabaseCount('experiences', 0);
        $this->assertDatabaseCount('education', 0);
        $this->assertDatabaseCount('job_seeker_skills', 0);
        $this->assertSame(CVFile::REVIEW_STATUS_DRAFT, $cvFile->refresh()->review_status);
        $this->assertNull($cvFile->confirmed_at);
    }

    public function test_no_change_sync_becomes_ready_and_applies_as_clear_no_op(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'Same']);
        $profile = $user->jobSeekerProfile;
        Experience::create([
            'job_seeker_profile_id' => $profile->id, 'title' => 'Engineer', 'company_name' => 'Acme',
            'start_date' => '2020-01-01', 'is_current' => false,
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id, 'institution' => 'University', 'degree' => 'BSc',
            'start_date' => '2015-01-01', 'end_date' => '2019-01-01',
        ]);
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $profile->skills()->attach($skill->id);
        $parsed = $this->parsedData();
        $parsed['phone'] = 'Same';
        $parsed['summary'] = null;
        $parsed['location'] = null;
        $parsed['experience'] = [['title' => 'Engineer', 'company_name' => 'Acme', 'start_date' => '2020-01-01', 'is_current' => false]];
        $parsed['education'] = [['institution' => 'University', 'degree' => 'BSc', 'start_date' => '2015-01-01', 'end_date' => '2019-01-01']];
        $parsed['skills'] = ['PHP'];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $this->assertSame(CVFile::REVIEW_STATUS_READY_TO_APPLY, $cvFile->refresh()->review_status);
        $this->assertDatabaseCount('profile_change_suggestions', 4);
        $this->assertSame(4, ProfileChangeSuggestion::where('suggestion_type', ProfileChangeSuggestion::TYPE_IGNORE)->count());

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_count', 0)
            ->assertJsonPath('data.rejected_count', 0)
            ->assertJsonPath('data.ignored_count', 4)
            ->assertJsonPath('data.already_applied', false);
    }

    public function test_final_apply_cannot_skip_suggestion_generation(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'Old']);
        $parsed = $this->parsedData();
        $parsed['phone'] = 'New';
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)
            ->assertJsonPath('code', 'CV_SUGGESTIONS_NOT_READY');
        $this->assertSame('Old', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame(CVFile::REVIEW_STATUS_COMPARISON_PENDING, $cvFile->refresh()->review_status);
        $this->assertNull($cvFile->confirmed_at);
    }

    public function test_skill_added_manually_after_generation_is_stale_and_keeps_manual_source(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $parsed = $this->parsedData();
        $parsed['phone'] = $parsed['summary'] = $parsed['location'] = null;
        $parsed['experience'] = $parsed['education'] = [];
        $parsed['skills'] = ['Redis'];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $suggestion = ProfileChangeSuggestion::where('entity_type', ProfileChangeSuggestion::ENTITY_SKILL)->firstOrFail();
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertOk();
        $skill = Skill::create(['name' => 'Redis', 'slug' => 'redis']);
        $user->jobSeekerProfile->skills()->attach($skill->id, ['source_type' => 'manual', 'user_verified_at' => now()]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_STALE')->assertJsonPath('entity_type', 'skill');
        $this->assertDatabaseHas('job_seeker_skills', [
            'job_seeker_profile_id' => $user->jobSeekerProfile->id, 'skill_id' => $skill->id, 'source_type' => 'manual',
        ]);
    }

    public function test_manual_fill_of_merge_target_is_detected_as_stale(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $experience = Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Engineer', 'company_name' => 'Acme', 'start_date' => '2020-01-01']);
        $parsed = $this->parsedData();
        $parsed['phone'] = $parsed['summary'] = $parsed['location'] = null;
        $parsed['education'] = $parsed['skills'] = [];
        $parsed['experience'] = [['title' => 'Engineer', 'company_name' => 'Acme', 'start_date' => '2020-01-01', 'description' => 'From CV']];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $suggestion = ProfileChangeSuggestion::where('entity_type', ProfileChangeSuggestion::ENTITY_EXPERIENCE)->firstOrFail();
        $this->assertSame(ProfileChangeSuggestion::TYPE_MERGE, $suggestion->suggestion_type);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertOk();
        $experience->update(['description' => 'Manual newer value']);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_STALE');
        $this->assertSame('Manual newer value', $experience->refresh()->description);
    }

    public function test_education_matching_uses_graduation_year_when_start_year_is_missing(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        Education::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'institution' => 'University', 'degree' => 'BSc', 'end_date' => '2019-01-01']);
        $target = Education::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'institution' => 'University', 'degree' => 'BSc', 'end_date' => '2022-01-01']);
        $parsed = $this->parsedData();
        $parsed['phone'] = $parsed['summary'] = $parsed['location'] = null;
        $parsed['experience'] = $parsed['skills'] = [];
        $parsed['education'] = [['institution' => 'University', 'degree' => 'BSc', 'graduation_year' => 2022, 'description' => 'Expected target']];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $suggestion = ProfileChangeSuggestion::where('entity_type', ProfileChangeSuggestion::ENTITY_EDUCATION)->firstOrFail();
        $this->assertSame(ProfileChangeSuggestion::TYPE_MERGE, $suggestion->suggestion_type);
        $this->assertSame($target->id, $suggestion->old_value['id']);
    }

    public function test_final_apply_rolls_back_an_earlier_profile_update_when_later_entity_is_stale(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'A']);
        $experience = Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Engineer', 'company_name' => 'Acme', 'location' => 'Old']);
        $parsed = $this->parsedData();
        $parsed['phone'] = 'B';
        $parsed['summary'] = $parsed['location'] = null;
        $parsed['education'] = $parsed['skills'] = [];
        $parsed['experience'] = [['title' => 'Engineer', 'company_name' => 'Acme', 'location' => 'New']];
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, $parsed);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        foreach (ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->get() as $suggestion) {
            $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertOk();
        }
        $experience->update(['location' => 'Manual']);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertStatus(409);
        $this->assertSame('A', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame('Manual', $experience->refresh()->location);
        $this->assertSame(2, ProfileChangeSuggestion::where('status', ProfileChangeSuggestion::STATUS_ACCEPTED)->count());
        $this->assertNull($cvFile->refresh()->confirmed_at);
    }

    public function test_suggestion_capabilities_are_false_after_the_cv_is_applied(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $cvFile = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_APPLIED);
        $cvFile->update(['confirmed_at' => now()]);
        $suggestion = ProfileChangeSuggestion::create([
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'entity_type' => 'profile', 'suggestion_type' => 'update', 'status' => 'rejected', 'source' => 'cv_parsed',
            'old_value' => ['phone' => 'A'], 'new_value' => ['phone' => 'B'],
        ]);

        $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cvFile->id}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $suggestion->id)
            ->assertJsonPath('data.0.can_accept', false)
            ->assertJsonPath('data.0.can_reject', false)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_apply', false);
    }

    private function jobSeeker(array $profile = []): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(array_merge(['user_id' => $user->id], $profile));

        return $user->load('jobSeekerProfile');
    }

    private function cv(User $user, ?string $mode = null, array $attributes = []): CVFile
    {
        return CVFile::create(array_merge([
            'user_id' => $user->id, 'original_name' => 'resume.pdf', 'stored_path' => 'cv-files/resume.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 100,
            'status' => 'uploaded', 'review_mode' => $mode,
        ], $attributes));
    }

    private function reviewCV(User $user, string $mode, string $status, ?array $parsed = null): CVFile
    {
        $cvFile = $this->cv($user, $mode, ['status' => 'parsed', 'review_status' => $status]);
        $parsed ??= $this->parsedData();
        CVParsingResult::create([
            'cv_file_id' => $cvFile->id,
            'raw_text' => 'safe parsed text',
            'parsed_json' => $parsed,
            'reviewed_json' => $mode === CVFile::REVIEW_MODE_INITIAL_IMPORT ? app(CVReviewDraftService::class)->build($parsed) : null,
            'reviewed_at' => $mode === CVFile::REVIEW_MODE_INITIAL_IMPORT ? now() : null,
        ]);

        return $cvFile->load('parsingResult');
    }

    private function parsedData(): array
    {
        return [
            'full_name' => 'Read Only', 'email' => 'readonly@example.test', 'phone' => '+963123',
            'location' => 'Remote', 'summary' => 'Summary', 'birth_date' => '1990-01-01',
            'nationality' => 'Read Only', 'marital_status' => 'Read Only', 'languages' => ['Arabic'],
            'certifications' => [['name' => 'Read Only']],
            'experience' => [['title' => 'Backend Engineer', 'company_name' => 'Acme', 'start_date' => '2022-01-01', 'end_date' => null, 'is_current' => true, 'description' => null]],
            'education' => [['institution' => 'Damascus University', 'degree' => 'BSc', 'start_date' => '2015-01-01', 'end_date' => '2019-01-01']],
            'skills' => ['Laravel'],
        ];
    }

    private function draftPayload(): array
    {
        return [
            'profile' => ['phone' => '+963555', 'summary' => 'Reviewed', 'location' => 'Damascus'],
            'experience' => [[
                'title' => 'API Engineer', 'company_name' => 'Acme', 'location' => null,
                'start_date' => '2020-01-01', 'end_date' => '2024-01-01', 'is_current' => false, 'description' => 'Built APIs',
            ]],
            'education' => [[
                'institution' => 'Damascus University', 'degree' => 'BSc', 'field_of_study' => 'CS',
                'start_date' => '2015-01-01', 'end_date' => '2019-01-01', 'description' => null,
            ]],
            'skills' => ['PHP', 'Laravel'],
        ];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
