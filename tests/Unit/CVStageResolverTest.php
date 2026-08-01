<?php

namespace Tests\Unit;

use App\Enums\CandidateCVStage;
use App\Models\CVFile;
use App\Services\CV\CVStageResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CVStageResolverTest extends TestCase
{
    #[DataProvider('stageCases')]
    public function test_internal_state_maps_to_one_candidate_stage(array $attributes, CandidateCVStage $expected): void
    {
        $cv = new CVFile(array_merge($this->baseAttributes(), $attributes));

        $this->assertSame($expected, app(CVStageResolver::class)->resolve($cv));
    }

    public function test_confirmed_stage_wins_over_stale_review_fields(): void
    {
        $cv = new CVFile(array_merge($this->baseAttributes(), [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_COMPARISON_PENDING,
            'confirmed_at' => now(),
        ]));

        $this->assertSame(CandidateCVStage::CONFIRMED, app(CVStageResolver::class)->resolve($cv));
    }

    public function test_final_confirmation_wins_over_stale_pending_suggestion_count(): void
    {
        $cv = new CVFile(array_merge($this->baseAttributes(), [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY,
        ]));
        $cv->setAttribute('pending_suggestions_count', 3);

        $this->assertSame(CandidateCVStage::FINAL_CONFIRMATION, app(CVStageResolver::class)->resolve($cv));
    }

    /** @return iterable<string, array{array<string, mixed>, CandidateCVStage}> */
    public static function stageCases(): iterable
    {
        yield 'processing' => [
            ['status' => 'processing'],
            CandidateCVStage::PROCESSING,
        ];
        yield 'failed' => [
            ['status' => 'failed'],
            CandidateCVStage::FAILED,
        ];
        yield 'first review' => [[
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
        ], CandidateCVStage::FIRST_REVIEW];
        yield 'differences review' => [[
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ], CandidateCVStage::DIFFERENCES_REVIEW];
        yield 'final confirmation' => [[
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY,
        ], CandidateCVStage::FINAL_CONFIRMATION];
        yield 'confirmed' => [[
            'status' => 'parsed',
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now(),
        ], CandidateCVStage::CONFIRMED];
    }

    /** @return array<string, mixed> */
    private function baseAttributes(): array
    {
        return [
            'user_id' => 1,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cv-files/resume.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'uploaded',
        ];
    }
}
