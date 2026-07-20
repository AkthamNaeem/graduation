<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\CVLifecycleException;
use App\Models\AuditLog;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use App\Services\CVService;
use App\Services\ProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CVReviewContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_action_covers_every_review_state_and_review_resource_is_complete(): void
    {
        $cases = [
            [['status' => 'uploaded'], 'wait_for_parsing'],
            [['status' => 'processing'], 'wait_for_parsing'],
            [['status' => 'failed'], 'retry_upload'],
            [['status' => 'parsed', 'review_mode' => 'initial_import', 'review_status' => 'draft'], 'review_draft'],
            [['status' => 'parsed', 'review_mode' => 'profile_sync', 'review_status' => 'comparison_pending'], 'generate_suggestions'],
            [['status' => 'parsed', 'review_mode' => 'profile_sync', 'review_status' => 'decisions_pending'], 'review_suggestions'],
            [['status' => 'parsed', 'review_mode' => 'profile_sync', 'review_status' => 'ready_to_apply'], 'apply_suggestions'],
            [['status' => 'parsed', 'review_mode' => 'profile_sync', 'review_status' => 'applied'], 'completed'],
        ];
        foreach ($cases as [$attributes, $expected]) {
            $this->assertSame($expected, (new CVFile($attributes))->nextAction());
        }

        $user = $this->jobSeeker();
        $cvFile = $this->parsedCV($user, ['review_mode' => 'initial_import', 'review_status' => 'draft']);
        $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cvFile->id}/review")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'parsing_status', 'review_mode', 'review_status', 'next_action', 'can_edit_draft',
                'can_generate_suggestions', 'can_apply_suggestions', 'editable_sections', 'read_only_sections',
                'parsed_json', 'reviewed_json',
            ]]);
        $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cvFile->id}")
            ->assertOk()->assertJsonPath('data.next_action', 'review_draft');
    }

    public function test_generation_is_idempotent_and_never_suggests_deleting_absent_profile_items(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Keep', 'company_name' => 'Legacy']);
        Education::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'institution' => 'Keep University']);
        $skill = Skill::create(['name' => 'Keep Skill', 'slug' => 'keep-skill']);
        $user->jobSeekerProfile->skills()->attach($skill->id);
        $cvFile = $this->parsedCV($user, parsed: ['phone' => 'New', 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []]);

        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $count = ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->count();
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();

        $this->assertSame(1, $count);
        $this->assertSame($count, ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->count());
        $this->assertFalse(ProfileChangeSuggestion::where('suggestion_type', 'delete')->exists());
        $this->assertDatabaseHas('experiences', ['title' => 'Keep']);
        $this->assertDatabaseHas('education', ['institution' => 'Keep University']);
        $this->assertDatabaseHas('job_seeker_skills', ['skill_id' => $skill->id]);
    }

    public function test_ambiguous_same_period_matches_are_conservative_adds(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        foreach (['First', 'Second'] as $description) {
            Experience::create([
                'job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Engineer',
                'company_name' => 'Acme', 'start_date' => '2020-01-01', 'description' => $description,
            ]);
            Education::create([
                'job_seeker_profile_id' => $user->jobSeekerProfile->id, 'institution' => 'University',
                'degree' => 'BSc', 'start_date' => '2015-01-01', 'description' => $description,
            ]);
        }
        $cvFile = $this->parsedCV($user, parsed: [
            'phone' => null, 'summary' => null, 'location' => null,
            'experience' => [['title' => 'Engineer', 'company_name' => 'Acme', 'start_date' => '2020-06-01', 'description' => 'CV']],
            'education' => [['institution' => 'University', 'degree' => 'BSc', 'start_date' => '2015-09-01', 'description' => 'CV']],
            'skills' => [],
        ]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $this->assertSame('add', ProfileChangeSuggestion::where('entity_type', 'experience')->value('suggestion_type'));
        $this->assertSame('add', ProfileChangeSuggestion::where('entity_type', 'education')->value('suggestion_type'));
    }

    public function test_decisions_are_reversible_until_apply_and_applied_is_immutable(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'A']);
        $cvFile = $this->parsedCV($user, parsed: ['phone' => 'B', 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []]);
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        $suggestion = ProfileChangeSuggestion::firstOrFail();

        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", ['edited_value' => ['phone' => 'B2']])->assertOk();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", ['edited_value' => ['phone' => 'B3']])->assertOk()->assertJsonPath('data.user_edited_value.phone', 'B3');
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertOk();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept", ['edited_value' => ['phone' => 'B4']])->assertOk();
        $this->assertSame('A', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame(CVFile::REVIEW_STATUS_READY_TO_APPLY, $cvFile->refresh()->review_status);

        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertOk();
        $this->assertSame('B4', $user->jobSeekerProfile->refresh()->phone);
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertStatus(409);
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertStatus(409);
    }

    public function test_suggestion_resource_capabilities_cover_every_status_and_ignore_is_never_applicable(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $cvFile = $this->parsedCV($user, ['review_status' => 'ready_to_apply']);
        $base = [
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'entity_type' => 'profile', 'source' => 'cv_parsed', 'old_value' => ['phone' => 'A'], 'new_value' => ['phone' => 'B'],
        ];
        foreach (['pending', 'accepted', 'rejected', 'applied'] as $status) {
            ProfileChangeSuggestion::create(array_merge($base, ['suggestion_type' => 'update', 'status' => $status]));
        }
        ProfileChangeSuggestion::create(array_merge($base, ['suggestion_type' => 'ignore', 'status' => 'accepted']));
        foreach (['add', 'merge'] as $type) {
            ProfileChangeSuggestion::create(array_merge($base, ['suggestion_type' => $type, 'status' => 'pending']));
        }

        $data = $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cvFile->id}/suggestions")
            ->assertOk()->json('data');
        $byStatus = collect($data)->where('suggestion_type', 'update')->keyBy('status');

        foreach (['pending', 'accepted', 'rejected'] as $status) {
            $this->assertTrue($byStatus[$status]['can_accept']);
            $this->assertTrue($byStatus[$status]['can_reject']);
            $this->assertTrue($byStatus[$status]['can_edit']);
        }
        $this->assertFalse($byStatus['pending']['can_apply']);
        $this->assertTrue($byStatus['accepted']['can_apply']);
        $this->assertFalse($byStatus['rejected']['can_apply']);
        foreach (['can_accept', 'can_reject', 'can_edit', 'can_apply'] as $capability) {
            $this->assertFalse($byStatus['applied'][$capability]);
        }

        $ignore = collect($data)->firstWhere('suggestion_type', 'ignore');
        $this->assertFalse($ignore['is_actionable']);
        $this->assertSame('matched_items', $ignore['display_group']);
        foreach (['can_accept', 'can_reject', 'can_edit', 'can_apply'] as $capability) {
            $this->assertFalse($ignore[$capability]);
        }
        foreach (['add', 'merge', 'update'] as $type) {
            $actionable = collect($data)->first(fn (array $item): bool => $item['suggestion_type'] === $type && $item['status'] === 'pending');
            $this->assertTrue($actionable['is_actionable']);
            $this->assertSame('profile', $actionable['display_group']);
        }
    }

    public function test_accepted_ignore_is_still_a_no_op_for_legacy_or_inconsistent_rows(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'Manual']);
        $cvFile = $this->parsedCV($user, ['review_status' => 'ready_to_apply']);
        $suggestion = ProfileChangeSuggestion::create([
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'entity_type' => 'profile', 'suggestion_type' => 'ignore', 'status' => 'accepted', 'source' => 'cv_parsed',
            'old_value' => ['phone' => 'Manual'], 'new_value' => ['phone' => 'Must not apply'],
        ]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_count', 0)
            ->assertJsonPath('data.ignored_count', 1);

        $this->assertSame('Manual', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame('applied', $suggestion->refresh()->status);
    }

    public function test_each_supported_profile_scalar_conflict_generates_update(): void
    {
        foreach (['phone', 'summary', 'location'] as $field) {
            $user = $this->jobSeeker([$field => 'manual value']);
            $parsed = ['phone' => null, 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []];
            $parsed[$field] = 'different CV value';
            $cvFile = $this->parsedCV($user, parsed: $parsed);

            app(ProfileSyncService::class)->generateSuggestionsFromParsedCV($user, $cvFile);
            $suggestion = ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->sole();
            $this->assertSame('profile', $suggestion->entity_type);
            $this->assertSame('update', $suggestion->suggestion_type, $field);
            $this->assertSame([$field => 'manual value'], $suggestion->old_value);
            $this->assertSame([$field => 'different CV value'], $suggestion->new_value);
        }
    }

    public function test_edited_value_validation_rejects_protected_fields_dates_and_client_skill_slug(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $cvFile = $this->parsedCV($user);
        $base = ['user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id, 'suggestion_type' => 'add', 'status' => 'pending', 'source' => 'cv_parsed'];
        $profile = ProfileChangeSuggestion::create(array_merge($base, ['entity_type' => 'profile', 'old_value' => ['phone' => null], 'new_value' => ['phone' => 'B']]));
        $experience = ProfileChangeSuggestion::create(array_merge($base, ['entity_type' => 'experience', 'new_value' => ['title' => 'Engineer', 'company_name' => 'Acme']]));
        $education = ProfileChangeSuggestion::create(array_merge($base, ['entity_type' => 'education', 'new_value' => ['institution' => 'University']]));
        $skill = ProfileChangeSuggestion::create(array_merge($base, ['entity_type' => 'skill', 'new_value' => ['name' => 'PHP']]));
        $token = $this->tokenFor($user);

        foreach (['id', 'user_id', 'job_seeker_profile_id', 'email', 'role', 'source_type', 'source_cv_file_id', 'applied_at', 'unexpected'] as $field) {
            $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$profile->id}/accept", ['edited_value' => ['phone' => 'B', $field => 'blocked']])->assertUnprocessable();
        }
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$profile->id}/accept", ['edited_value' => ['summary' => 'wrong target']])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$experience->id}/accept", ['edited_value' => [
            'title' => 'Engineer', 'company_name' => 'Acme', 'start_date' => '2025-01-01', 'end_date' => '2024-01-01',
        ]])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$experience->id}/accept", ['edited_value' => [
            'title' => 'Engineer', 'company_name' => 'Acme', 'is_current' => true, 'end_date' => '2025-01-01',
        ]])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$education->id}/accept", ['edited_value' => [
            'institution' => 'University', 'start_date' => '2025-01-01', 'end_date' => '2024-01-01',
        ]])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$skill->id}/accept", ['edited_value' => ['name' => 'PHP', 'slug' => 'client-slug']])->assertUnprocessable();
    }

    public function test_apply_is_idempotent_for_adds_update_and_merge_without_timestamp_changes(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'A']);
        $existing = Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Existing', 'company_name' => 'Acme', 'start_date' => '2020-01-01']);
        $cvFile = $this->parsedCV($user, parsed: [
            'phone' => 'B', 'summary' => null, 'location' => null,
            'experience' => [
                ['title' => 'Existing', 'company_name' => 'Acme', 'start_date' => '2020-01-01', 'description' => 'Merged'],
                ['title' => 'New', 'company_name' => 'Acme', 'start_date' => '2024-01-01'],
            ],
            'education' => [['institution' => 'University', 'degree' => 'BSc', 'start_date' => '2015-01-01']],
            'skills' => ['Redis'],
        ]);
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        foreach (ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->where('suggestion_type', '!=', 'ignore')->get() as $suggestion) {
            $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertOk();
        }
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertOk()->assertJsonPath('data.already_applied', false);
        $confirmedAt = $cvFile->refresh()->confirmed_at?->toISOString();
        $appliedTimes = ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->pluck('applied_at', 'id')->map->toISOString()->all();

        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertOk()->assertJsonPath('data.already_applied', true);
        $this->assertSame($confirmedAt, $cvFile->refresh()->confirmed_at?->toISOString());
        $this->assertSame($appliedTimes, ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->pluck('applied_at', 'id')->map->toISOString()->all());
        $this->assertSame(2, Experience::where('job_seeker_profile_id', $user->jobSeekerProfile->id)->count());
        $this->assertSame('Merged', $existing->refresh()->description);
        $this->assertSame(1, Education::where('job_seeker_profile_id', $user->jobSeekerProfile->id)->count());
        $this->assertSame(1, $user->jobSeekerProfile->skills()->where('slug', 'redis')->count());
    }

    public function test_apply_bulk_rejects_duplicate_mixed_cv_and_foreign_ids(): void
    {
        $owner = $this->jobSeeker(['headline' => 'Owner']);
        $other = $this->jobSeeker(['headline' => 'Other']);
        $firstCV = $this->parsedCV($owner, ['review_status' => 'ready_to_apply']);
        $secondCV = $this->parsedCV($owner, ['review_status' => 'ready_to_apply']);
        $first = $this->suggestion($owner, $firstCV, 'One');
        $second = $this->suggestion($owner, $secondCV, 'Two');
        $token = $this->tokenFor($owner);

        $this->withToken($token)->postJson('/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$first->id, $first->id]])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$first->id, $second->id]])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($other))->postJson('/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$first->id]])->assertNotFound();
    }

    public function test_apply_bulk_cannot_mutate_archived_cv_review_state_before_failing(): void
    {
        $user = $this->jobSeeker(['headline' => 'Owner']);
        $cvFile = $this->parsedCV($user, ['review_status' => 'comparison_pending', 'archived_at' => now()]);
        $suggestion = $this->suggestion($user, $cvFile, 'Redis');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$suggestion->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CV_ARCHIVED_READ_ONLY');

        $this->assertSame('comparison_pending', $cvFile->refresh()->review_status);
        $this->assertNull($cvFile->confirmed_at);
        $this->assertSame('accepted', $suggestion->refresh()->status);
    }

    public function test_candidate_review_endpoints_are_owner_scoped_and_forbid_employer_and_admin(): void
    {
        $owner = $this->jobSeeker(['headline' => 'Owner']);
        $other = $this->jobSeeker(['headline' => 'Other']);
        $cvFile = $this->parsedCV($owner, ['review_status' => 'ready_to_apply']);
        $suggestion = $this->suggestion($owner, $cvFile, 'Redis');
        $draft = ['profile' => ['phone' => null, 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []];
        $otherToken = $this->tokenFor($other);

        $this->withToken($otherToken)->getJson("/api/v1/cv/{$cvFile->id}/review")->assertNotFound();
        $this->withToken($otherToken)->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/cv/{$cvFile->id}/confirm")->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertNotFound();
        $this->withToken($otherToken)->getJson("/api/v1/cv/{$cvFile->id}/suggestions")->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertNotFound();

        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $token = $this->tokenFor($actor);
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson("/api/v1/cv/{$cvFile->id}/review")->assertForbidden();
            $this->withToken($token)->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertForbidden();
            $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/confirm")->assertForbidden();
            $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertForbidden();
            $this->withToken($token)->getJson("/api/v1/cv/{$cvFile->id}/suggestions")->assertForbidden();
            $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")->assertForbidden();
            $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertForbidden();
            $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$suggestion->id]])->assertForbidden();
        }
    }

    public function test_archived_reviews_are_readable_but_every_review_mutation_is_blocked(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $cvFile = $this->parsedCV($user, ['archived_at' => now()]);
        $suggestion = ProfileChangeSuggestion::create([
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'entity_type' => 'profile', 'suggestion_type' => 'update', 'status' => 'accepted', 'source' => 'cv_parsed',
            'old_value' => ['phone' => null], 'new_value' => ['phone' => 'B'],
        ]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson("/api/v1/cv/{$cvFile->id}/review")->assertOk();
        $this->withToken($token)->getJson("/api/v1/cv/{$cvFile->id}/suggestions")->assertOk();
        foreach ([
            ["/api/v1/cv/{$cvFile->id}/confirm", []],
            ["/api/v1/cv/{$cvFile->id}/suggestions/generate", []],
            ["/api/v1/cv/{$cvFile->id}/suggestions/apply", []],
            ["/api/v1/profile/suggestions/{$suggestion->id}/accept", []],
            ["/api/v1/profile/suggestions/{$suggestion->id}/reject", []],
            ['/api/v1/profile/suggestions/apply-bulk', ['suggestion_ids' => [$suggestion->id]]],
        ] as [$uri, $payload]) {
            $this->withToken($token)->postJson($uri, $payload)->assertStatus(409)->assertJsonPath('code', 'CV_ARCHIVED_READ_ONLY');
        }

        $draftCV = $this->parsedCV($user, ['review_mode' => 'initial_import', 'review_status' => 'draft', 'archived_at' => now()]);
        $draft = ['profile' => ['phone' => null, 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []];
        $this->withToken($token)->putJson("/api/v1/cv/{$draftCV->id}/review-draft", $draft)
            ->assertStatus(409)->assertJsonPath('code', 'CV_ARCHIVED_READ_ONLY');
    }

    public function test_draft_validation_handles_empty_sections_malformed_items_and_corrupt_stored_values(): void
    {
        $user = $this->jobSeeker();
        $cvFile = $this->parsedCV($user, ['review_mode' => 'initial_import', 'review_status' => 'draft']);
        $token = $this->tokenFor($user);
        $emptyDraft = ['profile' => ['phone' => null, 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []];

        $this->withToken($token)->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $emptyDraft)
            ->assertOk()
            ->assertJsonPath('data.reviewed_json.experience', [])
            ->assertJsonPath('data.reviewed_json.education', [])
            ->assertJsonPath('data.reviewed_json.skills', []);

        $malformed = $emptyDraft;
        $malformed['experience'] = ['not-an-object'];
        $this->withToken($token)->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $malformed)->assertUnprocessable();

        $corrupt = $emptyDraft;
        $corrupt['profile']['phone'] = ['not-a-string'];
        $cvFile->parsingResult()->update(['reviewed_json' => $corrupt]);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/confirm")->assertUnprocessable();
        $this->assertSame('draft', $cvFile->refresh()->review_status);
        $this->assertNull($user->jobSeekerProfile->refresh()->phone);
    }

    public function test_mutations_recheck_locked_cv_state_instead_of_trusting_a_stale_model(): void
    {
        $user = $this->jobSeeker();
        $draftCV = $this->parsedCV($user, ['review_mode' => 'initial_import', 'review_status' => 'draft']);
        $staleDraftCV = $draftCV->fresh();
        $draftCV->forceFill(['review_status' => 'applied', 'confirmed_at' => now()])->save();
        $draft = ['profile' => ['phone' => 'should-not-save', 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []];

        try {
            app(CVService::class)->updateReviewDraft($user, $staleDraftCV, $draft);
            $this->fail('A stale CV model must not allow a finalized draft to be overwritten.');
        } catch (CVLifecycleException $exception) {
            $this->assertSame('CV_REVIEW_DRAFT_NOT_EDITABLE', $exception->errorCode);
        }
        $this->assertNull($draftCV->parsingResult->refresh()->reviewed_json['profile']['phone']);

        $syncCV = $this->parsedCV($user);
        $staleSyncCV = $syncCV->fresh();
        $syncCV->forceFill(['archived_at' => now()])->save();

        try {
            app(ProfileSyncService::class)->generateSuggestionsFromParsedCV($user, $staleSyncCV);
            $this->fail('A stale CV model must not allow suggestions to be generated for an archived CV.');
        } catch (CVLifecycleException $exception) {
            $this->assertSame('CV_ARCHIVED_READ_ONLY', $exception->errorCode);
        }
        $this->assertDatabaseCount('profile_change_suggestions', 0);
    }

    public function test_stale_deleted_experience_changed_education_and_archived_cv_are_blocked(): void
    {
        $user = $this->jobSeeker(['headline' => 'Engineer']);
        $experience = Experience::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'title' => 'Engineer', 'company_name' => 'Acme', 'location' => 'Old']);
        $education = Education::create(['job_seeker_profile_id' => $user->jobSeekerProfile->id, 'institution' => 'University', 'degree' => 'BSc', 'description' => 'Old']);
        $cvFile = $this->parsedCV($user, parsed: [
            'phone' => null, 'summary' => null, 'location' => null,
            'experience' => [['title' => 'Engineer', 'company_name' => 'Acme', 'location' => 'New']],
            'education' => [['institution' => 'University', 'degree' => 'BSc', 'description' => 'New']], 'skills' => [],
        ]);
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/generate")->assertCreated();
        foreach (ProfileChangeSuggestion::where('cv_file_id', $cvFile->id)->get() as $suggestion) {
            $this->withToken($token)->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")->assertOk();
        }
        $experience->delete();
        $education->update(['description' => 'Manual']);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_STALE');

        $cvFile->update(['archived_at' => now()]);
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/suggestions/apply")
            ->assertStatus(409)->assertJsonPath('code', 'CV_ARCHIVED_READ_ONLY');
    }

    public function test_cv_review_audits_do_not_store_synthetic_private_markers(): void
    {
        $marker = 'PRIVATE-MARKER-9f6b';
        $user = $this->jobSeeker();
        $cvFile = $this->parsedCV($user, ['review_mode' => 'initial_import', 'review_status' => 'draft'], ['phone' => $marker, 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []]);
        $draft = ['profile' => ['phone' => $marker, 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []];
        $token = $this->tokenFor($user);
        $this->withToken($token)->putJson("/api/v1/cv/{$cvFile->id}/review-draft", $draft)->assertOk();
        $this->withToken($token)->postJson("/api/v1/cv/{$cvFile->id}/confirm")->assertOk();

        $syncUser = $this->jobSeeker(['headline' => 'Engineer', 'phone' => 'Old']);
        $syncCV = $this->parsedCV($syncUser, parsed: ['phone' => $marker, 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []]);
        app(ProfileSyncService::class)->generateSuggestionsFromParsedCV($syncUser, $syncCV);
        $suggestion = ProfileChangeSuggestion::where('cv_file_id', $syncCV->id)->sole();
        app(ProfileSyncService::class)->accept($syncUser, $suggestion);
        app(ProfileSyncService::class)->applyCV($syncUser, $syncCV);

        $audits = AuditLog::where('action', 'like', 'cv.%')->get();
        $this->assertGreaterThanOrEqual(5, $audits->count());
        foreach ($audits as $audit) {
            $this->assertStringNotContainsString($marker, $audit->toJson());
            $this->assertNull($audit->before_values);
            $this->assertNull($audit->after_values);
        }
    }

    private function jobSeeker(array $profile = []): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(array_merge(['user_id' => $user->id], $profile));

        return $user->load('jobSeekerProfile');
    }

    private function parsedCV(User $user, array $attributes = [], ?array $parsed = null): CVFile
    {
        $cvFile = CVFile::create(array_merge([
            'user_id' => $user->id, 'original_name' => 'review.pdf', 'stored_path' => 'cv-files/review.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 100,
            'status' => 'parsed', 'review_mode' => 'profile_sync', 'review_status' => 'comparison_pending',
        ], $attributes));
        CVParsingResult::create([
            'cv_file_id' => $cvFile->id, 'raw_text' => 'synthetic',
            'parsed_json' => $parsed ?? ['phone' => null, 'summary' => null, 'location' => null, 'experience' => [], 'education' => [], 'skills' => []],
            'reviewed_json' => ($attributes['review_mode'] ?? null) === 'initial_import'
                ? ['profile' => ['phone' => null, 'summary' => null, 'location' => null], 'experience' => [], 'education' => [], 'skills' => []]
                : null,
        ]);

        return $cvFile->load('parsingResult');
    }

    private function suggestion(User $user, CVFile $cvFile, string $name): ProfileChangeSuggestion
    {
        return ProfileChangeSuggestion::create([
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'entity_type' => 'skill', 'suggestion_type' => 'add', 'status' => 'accepted', 'source' => 'cv_parsed',
            'new_value' => ['name' => $name], 'decided_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
