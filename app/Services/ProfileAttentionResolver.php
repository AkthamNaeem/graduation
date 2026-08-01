<?php

namespace App\Services;

use App\Enums\CandidateCVStage;
use App\Enums\ProfileAttentionAction;
use App\Enums\ProfileAttentionType;
use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Services\CV\CVStageResolver;

class ProfileAttentionResolver
{
    public function __construct(
        private readonly CVStageResolver $stageResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $completeness
     * @return list<array<string, mixed>>
     */
    public function resolve(JobSeekerProfile $profile, array $completeness): array
    {
        $items = [];
        $cv = $profile->relationLoaded('latestUnconfirmedCVFile')
            ? $profile->latestUnconfirmedCVFile
            : null;

        if ($cv instanceof CVFile) {
            $type = $this->cvType($this->stageResolver->resolve($cv));
            if ($type instanceof ProfileAttentionType) {
                $items[] = $this->cvItem($cv, $type);
            }
        }

        if (! $completeness['is_complete']) {
            $items[] = $this->profileItem($completeness);
        }

        usort($items, static fn (array $left, array $right): int => [
            $right['priority'],
            $right['_sort_timestamp'],
            $right['_sort_id'],
        ] <=> [
            $left['priority'],
            $left['_sort_timestamp'],
            $left['_sort_id'],
        ]);

        $deduplicated = [];
        foreach ($items as $item) {
            unset($item['_sort_timestamp'], $item['_sort_id']);
            $deduplicated[$item['attention_key']] = $item;
        }

        return array_values($deduplicated);
    }

    private function cvType(CandidateCVStage $stage): ?ProfileAttentionType
    {
        return match ($stage) {
            CandidateCVStage::FAILED => ProfileAttentionType::CV_PROCESSING_FAILED,
            CandidateCVStage::PROCESSING => ProfileAttentionType::CV_PROCESSING,
            CandidateCVStage::FIRST_REVIEW => ProfileAttentionType::CV_FIRST_REVIEW_REQUIRED,
            CandidateCVStage::DIFFERENCES_REVIEW => ProfileAttentionType::CV_DIFFERENCES_REVIEW_REQUIRED,
            CandidateCVStage::FINAL_CONFIRMATION => ProfileAttentionType::CV_FINAL_CONFIRMATION_REQUIRED,
            CandidateCVStage::CONFIRMED => null,
        };
    }

    /** @return array<string, mixed> */
    private function cvItem(CVFile $cv, ProfileAttentionType $type): array
    {
        $item = [
            'attention_key' => 'cv:'.$cv->id.':'.$type->value,
            'type' => $type->value,
            'priority' => $type->priority(),
            'severity' => $type->severity()->value,
            'action' => $this->cvAction($cv, $type),
            '_sort_timestamp' => $cv->updated_at?->getTimestamp() ?? 0,
            '_sort_id' => $cv->id,
        ];

        if ($type === ProfileAttentionType::CV_PROCESSING) {
            $item['target'] = ['type' => 'cv', 'id' => $cv->id];
        }

        if ($type === ProfileAttentionType::CV_DIFFERENCES_REVIEW_REQUIRED) {
            $item['meta'] = [
                'changes_count' => (int) ($cv->pending_suggestions_count ?? 0),
            ];
        }

        return $item;
    }

    /** @return array<string, mixed>|null */
    private function cvAction(CVFile $cv, ProfileAttentionType $type): ?array
    {
        return match ($type) {
            ProfileAttentionType::CV_PROCESSING => null,
            ProfileAttentionType::CV_PROCESSING_FAILED => [
                'type' => ProfileAttentionAction::UPLOAD_CV->value,
                'target' => ['type' => 'cv_upload', 'id' => null],
            ],
            ProfileAttentionType::CV_FIRST_REVIEW_REQUIRED => [
                'type' => ProfileAttentionAction::REVIEW_EXTRACTED_CV->value,
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
            ],
            ProfileAttentionType::CV_DIFFERENCES_REVIEW_REQUIRED => [
                'type' => ProfileAttentionAction::REVIEW_CV_CHANGES->value,
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
            ],
            ProfileAttentionType::CV_FINAL_CONFIRMATION_REQUIRED => [
                'type' => ProfileAttentionAction::CONFIRM_CV_REVIEW->value,
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
            ],
            ProfileAttentionType::PROFILE_INCOMPLETE => null,
        };
    }

    /**
     * @param  array<string, mixed>  $completeness
     * @return array<string, mixed>
     */
    private function profileItem(array $completeness): array
    {
        $type = ProfileAttentionType::PROFILE_INCOMPLETE;

        return [
            'attention_key' => 'profile:incomplete',
            'type' => $type->value,
            'priority' => $type->priority(),
            'severity' => $type->severity()->value,
            'action' => [
                'type' => ProfileAttentionAction::COMPLETE_PROFILE->value,
                'target' => $completeness['next_item']['target'] ?? [
                    'type' => 'profile_section',
                    'value' => 'basic_information',
                ],
            ],
            'meta' => [
                'percentage' => $completeness['percentage'],
                'missing_items_count' => $completeness['missing_items_count'],
            ],
            '_sort_timestamp' => 0,
            '_sort_id' => 0,
        ];
    }
}
