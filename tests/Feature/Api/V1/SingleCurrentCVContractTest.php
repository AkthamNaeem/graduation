<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
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
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingleCurrentCVContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_no_cv_contract_and_existing_profile_authorization_are_preserved(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();

        [$user] = $this->profile();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.cv.status.key', 'no_cv')
            ->assertJsonPath('data.cv.is_ready', false)
            ->assertJsonPath('data.cv.allowed_actions', ['upload_cv'])
            ->assertJsonPath('data.current_cv', null)
            ->assertJsonPath('data.pending_cv_update', null);

        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            $this->app['auth']->forgetGuards();
            $this->withToken($this->tokenFor($viewer))->getJson('/api/v1/profile')->assertForbidden();
        }
    }

    public function test_only_valid_primary_cv_is_exposed_as_current_without_legacy_version_fields(): void
    {
        [$user, $profile] = $this->profile();
        $current = $this->cv($user, [
            'original_name' => 'Backend Developer CV.pdf',
            'status' => 'parsed',
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now(),
        ]);
        $profile->update(['primary_cv_file_id' => $current->id]);

        $response = $this->withHeader('Accept-Language', 'en')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $current->id)
            ->assertJsonPath('data.current_cv.original_name', 'Backend Developer CV.pdf')
            ->assertJsonPath('data.current_cv.stage.key', 'confirmed')
            ->assertJsonPath('data.current_cv.stage.label', 'Ready for applications')
            ->assertJsonPath('data.current_cv.can_use_for_application', true)
            ->assertJsonPath('data.current_cv.allowed_actions', ['preview', 'download', 'update'])
            ->assertJsonPath('data.pending_cv_update', null);

        foreach ([
            'version_label', 'is_primary', 'can_set_primary', 'can_archive', 'can_restore',
            'archived_at', 'stored_path', 'disk', 'parsing_result',
        ] as $field) {
            $response->assertJsonMissingPath("data.current_cv.{$field}");
        }
    }

    public function test_invalid_primary_returns_null_and_does_not_fallback_to_another_confirmed_cv(): void
    {
        [$user, $profile] = $this->profile();
        $invalidPrimary = $this->cv($user, [
            'status' => 'parsed',
            'confirmed_at' => now()->subDay(),
            'archived_at' => now(),
        ]);
        $this->cv($user, [
            'status' => 'parsed',
            'confirmed_at' => now(),
        ]);
        $profile->update(['primary_cv_file_id' => $invalidPrimary->id]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv', null)
            ->assertJsonPath('data.profile_completeness.missing_items.0.key', 'basic_information');
    }

    public function test_primary_cv_owned_by_another_user_is_never_exposed(): void
    {
        [$user, $profile] = $this->profile();
        [$otherUser] = $this->profile();
        $otherCV = $this->cv($otherUser, [
            'status' => 'parsed',
            'confirmed_at' => now(),
        ]);
        $profile->update(['primary_cv_file_id' => $otherCV->id]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv', null)
            ->assertJsonPath('data.pending_cv_update', null);
    }

    public function test_pending_stage_progress_next_action_and_initial_operation_contracts(): void
    {
        $cases = [
            'processing' => [
                ['status' => 'processing'],
                'processing', 'wait_for_processing', false, false, 'processing',
            ],
            'failed' => [
                ['status' => 'failed', 'error_message' => 'INTERNAL_SECRET'],
                'failed', 'upload_cv', false, false, 'failed',
            ],
            'first review' => [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
                'review_status' => CVFile::REVIEW_STATUS_DRAFT,
            ], 'first_review', 'review_extracted_cv', true, false, 'review_required'],
            'differences review' => [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
                'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
            ], 'differences_review', 'review_cv_changes', true, false, 'suggestions_review_required'],
            'final confirmation' => [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
                'review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY,
            ], 'final_confirmation', 'confirm_cv_review', true, true, 'ready_for_confirmation'],
        ];

        foreach ($cases as [$attributes, $stage, $nextAction, $hasParsingResult, $reviewCompleted, $logicalStatus]) {
            [$user, $profile] = $this->profile();
            $pending = $this->cv($user, $attributes);
            $profile->update(['primary_cv_file_id' => $pending->id]);
            if ($hasParsingResult) {
                $this->parsingResult($pending);
            }

            $this->app['auth']->forgetGuards();
            $response = $this->withHeader('Accept-Language', 'en')
                ->withToken($this->tokenFor($user))
                ->getJson('/api/v1/profile')
                ->assertOk()
                ->assertJsonPath('data.identity.id', $user->id)
                ->assertJsonPath('data.cv.status.key', $logicalStatus)
                ->assertJsonPath('data.cv.is_ready', false)
                ->assertJsonPath('data.cv.allowed_actions', ['continue_cv_review'])
                ->assertJsonPath('data.current_cv', null)
                ->assertJsonPath('data.pending_cv_update.id', $pending->id)
                ->assertJsonPath(
                    'data.pending_cv_update.operation.key',
                    ($attributes['review_mode'] ?? null) === CVFile::REVIEW_MODE_PROFILE_SYNC ? 'update' : 'initial_upload',
                )
                ->assertJsonPath('data.pending_cv_update.stage.key', $stage)
                ->assertJsonPath('data.pending_cv_update.next_action.type.key', $nextAction)
                ->assertJsonPath('data.pending_cv_update.can_use_for_application', false)
                ->assertJsonPath('data.pending_cv_update.progress.upload_completed', true)
                ->assertJsonPath('data.pending_cv_update.progress.text_extracted', $hasParsingResult)
                ->assertJsonPath('data.pending_cv_update.progress.parsing_completed', $attributes['status'] === 'parsed')
                ->assertJsonPath('data.pending_cv_update.progress.review_completed', $reviewCompleted);

            $payload = $response->getContent();
            $this->assertStringNotContainsString('INTERNAL_SECRET', $payload);
            $this->assertStringNotContainsString('raw_text', $payload);
            $this->assertStringNotContainsString('parsed_json', $payload);
            $this->assertStringNotContainsString('stored_path', $payload);
            $this->assertStringNotContainsString('disk', $payload);
        }
    }

    public function test_current_and_pending_update_stay_consistent_with_completeness_attention_and_arabic(): void
    {
        [$user, $profile] = $this->completeProfile();
        $current = $this->cv($user, [
            'original_name' => 'Current.pdf',
            'status' => 'parsed',
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now()->subDay(),
        ]);
        $profile->update(['primary_cv_file_id' => $current->id]);
        $pending = $this->cv($user, [
            'original_name' => 'Update.pdf',
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        $this->parsingResult($pending);
        $this->suggestion($user, $profile, $pending, 1);

        $this->withHeader('Accept-Language', 'ar')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $current->id)
            ->assertJsonPath('data.current_cv.stage.label', 'جاهزة للتقديم')
            ->assertJsonPath('data.current_cv.can_use_for_application', true)
            ->assertJsonPath('data.pending_cv_update.id', $pending->id)
            ->assertJsonPath('data.pending_cv_update.operation.key', 'update')
            ->assertJsonPath('data.cv.status.key', 'suggestions_review_required')
            ->assertJsonPath('data.cv.is_ready', true)
            ->assertJsonPath('data.cv.allowed_actions', ['preview_cv', 'download_cv', 'continue_cv_review'])
            ->assertJsonPath('data.pending_cv_update.operation.label', 'تحديث السيرة الذاتية')
            ->assertJsonPath('data.pending_cv_update.stage.key', 'differences_review')
            ->assertJsonPath('data.pending_cv_update.stage.label', 'مراجعة التغييرات')
            ->assertJsonPath('data.pending_cv_update.next_action.type.key', 'review_cv_changes')
            ->assertJsonPath('data.pending_cv_update.next_action.type.label', 'مراجعة التغييرات')
            ->assertJsonPath('data.current_cv.allowed_actions', ['preview', 'download'])
            ->assertJsonPath('data.pending_cv_update.allowed_actions', ['preview', 'download', 'review', 'cancel'])
            ->assertJsonPath('data.profile_completeness.percentage', 100)
            ->assertJsonPath('data.attention_items.0.type.key', 'cv_differences_review_required')
            ->assertJsonPath('data.attention_items.0.meta.changes_count', 1);
    }

    public function test_only_latest_owned_unconfirmed_unarchived_cv_is_exposed(): void
    {
        [$user] = $this->profile();
        $older = $this->cv($user, ['status' => 'processing']);
        $newer = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
        ]);
        $this->parsingResult($newer);
        $this->cv($user, ['status' => 'failed', 'archived_at' => now()]);
        [$otherUser] = $this->profile();
        $this->cv($otherUser, ['status' => 'failed']);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.pending_cv_update.id', $newer->id)
            ->assertJsonPath('data.pending_cv_update.stage.key', 'first_review')
            ->assertJsonMissingPath('data.pending_cv_update.error_message');

        $this->assertNotSame($older->id, $newer->id);
    }

    public function test_profile_query_count_does_not_grow_with_old_cvs_and_suggestions(): void
    {
        [$user, $profile] = $this->completeProfile();
        $current = $this->cv($user, ['status' => 'parsed', 'confirmed_at' => now()->subDay()]);
        $profile->update(['primary_cv_file_id' => $current->id]);
        $pending = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        $this->parsingResult($pending);
        $this->suggestion($user, $profile, $pending, 1);
        $token = $this->tokenFor($user);

        $singleCount = $this->profileQueryCount($token);

        foreach (range(2, 20) as $number) {
            $this->cv($user, [
                'original_name' => "legacy-{$number}.pdf",
                'status' => 'parsed',
                'confirmed_at' => now()->subDays($number),
                'archived_at' => now()->subDays($number - 1),
            ]);
            $this->suggestion($user, $profile, $pending, $number);
        }

        $manyCount = $this->profileQueryCount($token);

        $this->assertLessThanOrEqual(16, $singleCount);
        $this->assertLessThanOrEqual($singleCount + 1, $manyCount);
    }

    public function test_real_pending_cv_cannot_be_selected_for_an_application(): void
    {
        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
        [$user] = $this->profile();
        $pending = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        Storage::disk('local')->put($pending->stored_path, 'pending cv');
        $company = Company::create(['name' => 'Pending Guard Co', 'approval_status' => 'approved']);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Engineer',
            'description' => 'Build APIs',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now(),
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/applications/{$job->id}", [
                'selected_cv_file_id' => $pending->id,
                'consent_to_share_profile' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CV_NOT_USABLE_FOR_APPLICATION');

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_confirming_an_update_promotes_it_to_current_only_after_confirmation(): void
    {
        [$user, $profile] = $this->profile(['headline' => 'Backend Developer']);
        $current = $this->cv($user, [
            'status' => 'parsed',
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now()->subDay(),
        ]);
        $profile->update(['primary_cv_file_id' => $current->id]);
        $pending = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY,
        ]);
        CVParsingResult::create([
            'cv_file_id' => $pending->id,
            'raw_text' => '',
            'parsed_json' => [],
            'reviewed_json' => app(CVReviewDraftService::class)->normalize(
                app(CVProfileSnapshotService::class)->snapshot($profile),
            ),
            'reviewed_at' => now(),
        ]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $current->id)
            ->assertJsonPath('data.pending_cv_update.id', $pending->id)
            ->assertJsonPath('data.pending_cv_update.stage.key', 'final_confirmation');

        $this->withToken($token)
            ->postJson("/api/v1/cv/{$pending->id}/confirm")
            ->assertOk();

        $this->assertSame($pending->id, $profile->refresh()->primary_cv_file_id);
        $this->withToken($token)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.current_cv.id', $pending->id)
            ->assertJsonPath('data.pending_cv_update', null);
    }

    /** @return array{User, JobSeekerProfile} */
    private function profile(array $attributes = []): array
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(array_merge(['user_id' => $user->id], $attributes));

        return [$user, $profile];
    }

    /** @return array{User, JobSeekerProfile} */
    private function completeProfile(): array
    {
        [$user, $profile] = $this->profile([
            'phone' => '+963111',
            'headline' => 'Backend Developer',
            'summary' => 'Laravel developer',
            'location' => 'Damascus',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Developer',
            'company_name' => 'Workey',
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
        ]);
        foreach (['PHP', 'Laravel', 'SQL'] as $name) {
            $skill = Skill::create([
                'name' => "{$name} {$user->id}",
                'slug' => strtolower($name)."-{$user->id}",
            ]);
            $profile->skills()->attach($skill->id);
        }

        return [$user, $profile];
    }

    /** @param array<string, mixed> $overrides */
    private function cv(User $user, array $overrides = []): CVFile
    {
        $cv = CVFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => "cv-files/{$user->id}/".Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'uploaded',
        ], $overrides));
        Storage::disk($cv->disk)->put($cv->stored_path, str_repeat('x', max(1, (int) $cv->size_bytes)));

        return $cv;
    }

    private function parsingResult(CVFile $cv): void
    {
        CVParsingResult::create([
            'cv_file_id' => $cv->id,
            'raw_text' => 'PRIVATE RAW TEXT',
            'parsed_json' => ['summary' => 'PRIVATE PARSED VALUE'],
        ]);
    }

    private function suggestion(User $user, JobSeekerProfile $profile, CVFile $cv, int $number): void
    {
        ProfileChangeSuggestion::create([
            'user_id' => $user->id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'new_value' => ['headline' => "PRIVATE {$number}"],
            'confidence_score' => 0.99,
        ]);
    }

    private function profileQueryCount(string $token): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
