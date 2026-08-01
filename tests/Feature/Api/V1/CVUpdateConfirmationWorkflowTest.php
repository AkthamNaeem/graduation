<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\ParseCVFileJob;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use App\Services\CV\CVProfileSnapshotService;
use App\Services\CV\CVReviewDraftService;
use App\Services\CVParsingService;
use App\Services\ProfileSyncService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CVUpdateConfirmationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_preserves_current_pointer_blocks_a_second_pending_and_allows_upload_after_cancel(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->candidate();

        $first = $this->withToken($this->tokenFor($user))->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            'make_primary' => true,
        ], ['Accept' => 'application/json'])->assertCreated();
        $cvId = $first->json('data.id');
        $this->assertNull($user->jobSeekerProfile->refresh()->primary_cv_file_id);

        $this->withToken($this->tokenFor($user))->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('code', 'CV_PENDING_UPDATE_EXISTS')
            ->assertJsonPath('statusCode', 409)
            ->assertJsonPath('data.pending_cv_id', $cvId);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cvId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.pending_cv_update', null);
        $this->withToken($this->tokenFor($user))->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, CVFile::query()->whereNull('cancelled_at')->whereNull('confirmed_at')->count());
    }

    public function test_initial_review_requires_final_preview_then_applies_atomically_and_is_idempotent(): void
    {
        $availableFrom = now()->addMonth()->toDateString();
        $user = $this->candidate([
            'availability_status' => 'available_from_date',
            'available_from' => $availableFrom,
        ]);
        $cv = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT, [
            'phone' => '+963900000000',
            'summary' => 'Backend engineer',
            'location' => 'Damascus',
            'experience' => [[
                'title' => 'API Engineer', 'company_name' => 'Acme', 'location' => null,
                'start_date' => '2024-01-01', 'end_date' => null, 'is_current' => true, 'description' => null,
            ]],
            'education' => [],
            'skills' => ['Laravel'],
        ]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertConflict()->assertJsonPath('code', 'CV_REVIEW_HAS_UNRESOLVED_CHANGES');
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")
            ->assertOk()->assertJsonPath('data.stage.key', 'final_confirmation');
        $this->withToken($this->tokenFor($user))->getJson("/api/v1/cv/{$cv->id}/final-preview")
            ->assertOk()
            ->assertJsonPath('data.can_confirm', true)
            ->assertJsonMissingPath('data.parsed_json');

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $cv->id)
            ->assertJsonPath('data.pending_cv_update', null)
            ->assertJsonPath('data.already_confirmed', false);
        $this->assertSame($cv->id, $user->jobSeekerProfile->refresh()->primary_cv_file_id);
        $this->assertSame('available_from_date', $user->jobSeekerProfile->availability_status->value);
        $this->assertSame($availableFrom, $user->jobSeekerProfile->available_from->format('Y-m-d'));
        $this->assertDatabaseHas('experiences', ['title' => 'API Engineer', 'source_cv_file_id' => $cv->id]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertOk()->assertJsonPath('data.already_confirmed', true);
        $this->assertDatabaseCount('experiences', 1);
    }

    public function test_update_decisions_do_not_change_profile_until_confirm_and_profile_change_conflicts(): void
    {
        $user = $this->candidate(['phone' => '+963111', 'headline' => 'Manual headline']);
        $cv = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_COMPARISON_PENDING, [
            'phone' => '+963222',
            'summary' => 'From CV',
            'location' => null,
            'experience' => [],
            'education' => [],
            'skills' => ['PHP'],
        ], false);
        $suggestions = app(ProfileSyncService::class)->generateSuggestionsFromParsedCV($user, $cv);
        $this->assertSame(CVFile::REVIEW_STATUS_DECISIONS_PENDING, $cv->refresh()->review_status);
        foreach ($suggestions->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE) as $suggestion) {
            $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/accept")
                ->assertOk();
        }
        $this->assertSame('+963111', $user->jobSeekerProfile->refresh()->phone);
        $this->assertSame(CVFile::REVIEW_STATUS_READY_TO_APPLY, $cv->refresh()->review_status);

        $user->jobSeekerProfile->update(['headline' => 'Changed elsewhere']);
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertConflict()->assertJsonPath('code', 'CV_PROFILE_CHANGED_SINCE_COMPARISON');
        $this->assertSame('+963111', $user->jobSeekerProfile->refresh()->phone);
        $this->assertNull($cv->refresh()->confirmed_at);

        app(ProfileSyncService::class)->generateSuggestionsFromParsedCV($user, $cv);
        foreach ($cv->profileChangeSuggestions()->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)->get() as $suggestion) {
            $this->withToken($this->tokenFor($user))->postJson("/api/v1/profile/suggestions/{$suggestion->id}/reject")
                ->assertOk();
        }
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/confirm")
            ->assertOk()->assertJsonPath('data.current_cv.id', $cv->id);
        $this->assertSame('+963111', $user->jobSeekerProfile->refresh()->phone);
    }

    public function test_pending_or_cancelled_cv_cannot_be_primary_or_used_and_cancelled_parse_job_is_a_no_op(): void
    {
        Storage::fake('local');
        $user = $this->candidate(['availability_status' => 'not_available']);
        $cv = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT, [], false);
        Storage::disk('local')->put($cv->stored_path, 'pdf');

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/make-primary")
            ->assertConflict()->assertJsonPath('code', 'CV_NOT_USABLE_FOR_APPLICATION');
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/cancel")->assertOk();
        $this->assertSame('not_available', $user->jobSeekerProfile->refresh()->availability_status->value);

        $parser = Mockery::mock(CVParsingService::class);
        $parser->shouldNotReceive('extractText');
        (new ParseCVFileJob($cv->refresh()))->handle($parser);
        $this->assertSame(CVFile::REVIEW_STATUS_CANCELLED, $cv->refresh()->review_status);
    }

    public function test_final_update_draft_supports_explicit_removal_and_preserves_source_provenance(): void
    {
        $user = $this->candidate([
            'headline' => 'Backend Engineer',
            'phone' => '+963111',
            'summary' => 'Manual summary',
            'location' => 'Damascus',
        ]);
        $profile = $user->jobSeekerProfile;
        $current = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_APPLIED, []);
        $current->forceFill(['confirmed_at' => now()->subDay()])->save();
        $profile->update(['primary_cv_file_id' => $current->id]);
        $experience = Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Existing Engineer',
            'company_name' => 'Manual Co',
            'location' => null,
            'start_date' => '2022-01-01',
            'end_date' => null,
            'is_current' => true,
            'description' => null,
            'source_type' => 'manual',
        ]);
        $education = Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Manual University',
            'degree' => null,
            'field_of_study' => null,
            'start_date' => null,
            'end_date' => null,
            'description' => null,
            'source_type' => 'manual',
        ]);
        $php = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $profile->skills()->attach($php->id, ['source_type' => 'manual']);

        $pending = $this->reviewCV($user, CVFile::REVIEW_MODE_PROFILE_SYNC, CVFile::REVIEW_STATUS_READY_TO_APPLY, [], false);
        $snapshotService = app(CVProfileSnapshotService::class);
        $base = $snapshotService->snapshot($profile->refresh());
        $generated = app(CVReviewDraftService::class)->normalize($base);
        $generated['experience'][] = [
            'title' => 'Extracted Engineer',
            'company_name' => 'CV Co',
            'location' => null,
            'start_date' => '2024-01-01',
            'end_date' => null,
            'is_current' => true,
            'description' => null,
        ];
        $generated['skills'][] = 'Go';
        $pending->parsingResult->forceFill([
            'comparison_base_json' => $base,
            'reviewed_json' => $generated,
            'system_generated_review_json' => $generated,
            'reviewed_at' => now(),
        ])->save();
        $pending->forceFill([
            'comparison_profile_hash' => $snapshotService->hash($base),
            'comparison_profile_updated_at' => $profile->updated_at,
        ])->save();

        $final = $generated;
        $final['education'] = [];
        $final['experience'][] = [
            'title' => 'User Added Engineer',
            'company_name' => 'Personal Co',
            'location' => null,
            'start_date' => null,
            'end_date' => null,
            'is_current' => false,
            'description' => null,
        ];
        $final['skills'][] = 'Rust';

        $token = $this->tokenFor($user);
        $this->withToken($token)->patchJson("/api/v1/cv/{$pending->id}/review", $final)
            ->assertOk()
            ->assertJsonPath('data.change_summary.removed', 1)
            ->assertJsonPath('data.change_summary.added', 4);
        $this->assertDatabaseHas('education', ['id' => $education->id]);

        $this->withToken($token)->postJson("/api/v1/cv/{$pending->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $pending->id)
            ->assertJsonPath('data.applied_changes.removed', 1);

        $this->assertDatabaseHas('experiences', ['id' => $experience->id, 'source_type' => 'manual']);
        $this->assertDatabaseHas('experiences', [
            'title' => 'Extracted Engineer',
            'source_type' => 'cv_confirmed',
            'source_cv_file_id' => $pending->id,
        ]);
        $this->assertDatabaseHas('experiences', [
            'title' => 'User Added Engineer',
            'source_type' => 'manual',
            'source_cv_file_id' => null,
        ]);
        $this->assertDatabaseMissing('education', ['id' => $education->id]);
        $this->assertDatabaseHas('job_seeker_skills', [
            'job_seeker_profile_id' => $profile->id,
            'skill_id' => Skill::where('slug', 'go')->value('id'),
            'source_type' => 'cv_confirmed',
        ]);
        $this->assertDatabaseHas('job_seeker_skills', [
            'job_seeker_profile_id' => $profile->id,
            'skill_id' => Skill::where('slug', 'rust')->value('id'),
            'source_type' => 'manual',
        ]);
        $this->assertDatabaseHas('cv_files', ['id' => $current->id]);
    }

    public function test_new_workflow_endpoints_enforce_role_and_ownership_and_cancel_is_idempotent(): void
    {
        $owner = $this->candidate();
        $otherCandidate = $this->candidate();
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER->value]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN->value]);
        $cv = $this->reviewCV($owner, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT, []);

        $this->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")->assertUnauthorized();
        Sanctum::actingAs($employer);
        $this->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")->assertForbidden();
        Sanctum::actingAs($otherCandidate);
        $this->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")->assertForbidden();
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/cv/{$cv->id}/ready-for-confirmation")->assertOk();
        $this->postJson("/api/v1/cv/{$cv->id}/cancel")
            ->assertOk()->assertJsonPath('data.already_cancelled', false);
        $this->postJson("/api/v1/cv/{$cv->id}/cancel")
            ->assertOk()->assertJsonPath('data.already_cancelled', true);

        $current = $this->reviewCV($owner, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_APPLIED, []);
        $current->forceFill(['confirmed_at' => now()])->save();
        $owner->jobSeekerProfile->update(['primary_cv_file_id' => $current->id]);
        $this->postJson("/api/v1/cv/{$current->id}/cancel")
            ->assertConflict()->assertJsonPath('code', 'CV_CANNOT_CANCEL_CURRENT');
    }

    public function test_cancelled_cv_is_rejected_by_real_application_validation(): void
    {
        $this->seed(ApplicationStatusSeeder::class);
        Storage::fake('local');
        $user = $this->candidate();
        $cv = $this->reviewCV($user, CVFile::REVIEW_MODE_INITIAL_IMPORT, CVFile::REVIEW_STATUS_DRAFT, []);
        Storage::disk('local')->put($cv->stored_path, 'private cv');
        $this->withToken($this->tokenFor($user))->postJson("/api/v1/cv/{$cv->id}/cancel")->assertOk();
        $company = Company::create(['name' => 'Cancelled CV Guard', 'approval_status' => 'approved']);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Engineer',
            'description' => 'Build APIs',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now(),
        ]);

        $this->withToken($this->tokenFor($user))->postJson("/api/v1/applications/{$job->id}", [
            'selected_cv_file_id' => $cv->id,
            'consent_to_share_profile' => true,
        ])->assertConflict()->assertJsonPath('code', 'CV_NOT_USABLE_FOR_APPLICATION');
    }

    private function candidate(array $profile = []): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER->value]);
        JobSeekerProfile::create(array_merge(['user_id' => $user->id], $profile));

        return $user->refresh()->load('jobSeekerProfile');
    }

    /** @param array<string, mixed> $parsed */
    private function reviewCV(User $user, string $mode, string $status, array $parsed, bool $withDraft = true): CVFile
    {
        $parsed = array_merge([
            'phone' => null, 'summary' => null, 'location' => null,
            'experience' => [], 'education' => [], 'skills' => [],
        ], $parsed);
        $cv = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'pending.pdf',
            'stored_path' => 'cv-files/pending.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'review_mode' => $mode,
            'review_status' => $status,
        ]);
        CVParsingResult::create([
            'cv_file_id' => $cv->id,
            'raw_text' => 'private',
            'parsed_json' => $parsed,
            'reviewed_json' => $withDraft ? app(CVReviewDraftService::class)->build($parsed) : null,
            'reviewed_at' => $withDraft ? now() : null,
        ]);

        return $cv->refresh()->load('parsingResult');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
