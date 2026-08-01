<?php

namespace Tests\Unit;

use App\Enums\ProfileAttentionAction;
use App\Enums\ProfileAttentionType;
use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\ProfileAttentionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAttentionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_profile_without_a_pending_cv_has_no_attention_items(): void
    {
        $user = User::factory()->create();
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);

        $this->assertSame([], $this->resolve($profile, $this->complete()));
    }

    public function test_each_cv_state_resolves_to_one_most_specific_typed_attention_item(): void
    {
        $cases = [
            [['status' => 'processing'], ProfileAttentionType::CV_PROCESSING, null],
            [['status' => 'failed'], ProfileAttentionType::CV_PROCESSING_FAILED, ProfileAttentionAction::UPLOAD_CV],
            [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
                'review_status' => CVFile::REVIEW_STATUS_DRAFT,
            ], ProfileAttentionType::CV_FIRST_REVIEW_REQUIRED, ProfileAttentionAction::REVIEW_EXTRACTED_CV],
            [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
                'review_status' => CVFile::REVIEW_STATUS_COMPARISON_PENDING,
            ], ProfileAttentionType::CV_DIFFERENCES_REVIEW_REQUIRED, ProfileAttentionAction::REVIEW_CV_CHANGES],
            [[
                'status' => 'parsed',
                'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
                'review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY,
            ], ProfileAttentionType::CV_FINAL_CONFIRMATION_REQUIRED, ProfileAttentionAction::CONFIRM_CV_REVIEW],
        ];

        foreach ($cases as [$attributes, $expectedType, $expectedAction]) {
            [$profile, $cv] = $this->profileWithCV($attributes);
            $items = $this->resolve($profile, $this->complete());

            $this->assertCount(1, $items);
            $this->assertSame($expectedType->value, $items[0]['type']);
            $this->assertSame($expectedType->priority(), $items[0]['priority']);
            $this->assertSame($expectedAction?->value, $items[0]['action']['type'] ?? null);
            if ($expectedAction !== null && $expectedAction !== ProfileAttentionAction::UPLOAD_CV) {
                $this->assertSame($cv->id, $items[0]['action']['target']['id'] ?? null);
            }
        }
    }

    public function test_pending_non_ignore_suggestions_create_one_differences_item_with_count(): void
    {
        [$profile, $cv] = $this->profileWithCV([
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        ProfileChangeSuggestion::create([
            'user_id' => $profile->user_id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'new_value' => ['headline' => 'New title'],
        ]);
        ProfileChangeSuggestion::create([
            'user_id' => $profile->user_id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_IGNORE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'new_value' => ['summary' => 'Ignored'],
        ]);

        $items = $this->resolve($profile, $this->complete());

        $this->assertCount(1, $items);
        $this->assertSame('cv_differences_review_required', $items[0]['type']);
        $this->assertSame(1, $items[0]['meta']['changes_count']);
    }

    public function test_profile_incomplete_uses_next_item_target_and_sorts_after_cv_attention(): void
    {
        [$profile] = $this->profileWithCV(['status' => 'failed']);
        $items = $this->resolve($profile, [
            'percentage' => 35,
            'is_complete' => false,
            'missing_items_count' => 3,
            'next_item' => [
                'target' => ['type' => 'profile_section', 'value' => 'experience'],
            ],
        ]);

        $this->assertSame([
            'cv_processing_failed',
            'profile_incomplete',
        ], array_column($items, 'type'));
        $this->assertSame('experience', $items[1]['action']['target']['value']);
        $this->assertSame(35, $items[1]['meta']['percentage']);
    }

    public function test_only_latest_owned_unconfirmed_unarchived_cv_is_considered(): void
    {
        [$profile, $older] = $this->profileWithCV(['status' => 'failed']);
        $newer = CVFile::create($this->cvAttributes($profile->user, [
            'status' => 'processing',
        ]));
        CVFile::create($this->cvAttributes($profile->user, [
            'status' => 'failed',
            'archived_at' => now(),
        ]));
        $otherUser = User::factory()->create();
        CVFile::create($this->cvAttributes($otherUser, ['status' => 'failed']));

        $items = $this->resolve($profile, $this->complete());

        $this->assertCount(1, $items);
        $this->assertSame('cv_processing', $items[0]['type']);
        $this->assertSame($newer->id, $items[0]['target']['id']);
        $this->assertNotSame($older->id, $items[0]['target']['id']);
    }

    /** @param array<string, mixed> $cvAttributes */
    private function profileWithCV(array $cvAttributes): array
    {
        $user = User::factory()->create();
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);
        $cv = CVFile::create($this->cvAttributes($user, $cvAttributes));

        return [$profile, $cv];
    }

    /** @param array<string, mixed> $overrides */
    private function cvAttributes(User $user, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cvs/resume.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'processing',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function complete(): array
    {
        return [
            'percentage' => 100,
            'is_complete' => true,
            'missing_items_count' => 0,
            'next_item' => null,
        ];
    }

    /** @param array<string, mixed> $completeness */
    private function resolve(JobSeekerProfile $profile, array $completeness): array
    {
        $loaded = $profile->fresh()->load([
            'user',
            'latestUnconfirmedCVFile' => fn ($query) => $query
                ->withCount(['profileChangeSuggestions as pending_suggestions_count' => fn ($suggestions) => $suggestions
                    ->where('status', ProfileChangeSuggestion::STATUS_PENDING)
                    ->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)]),
        ]);

        return app(ProfileAttentionResolver::class)->resolve($loaded, $completeness);
    }
}
