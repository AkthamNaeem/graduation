<?php

namespace App\Services\CV;

use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use Illuminate\Support\Collection;

class CVFinalDraftService
{
    public function __construct(
        private readonly CVProfileSnapshotService $snapshotService,
        private readonly CVReviewDraftService $reviewDraftService,
    ) {}

    /**
     * @param  Collection<int, ProfileChangeSuggestion>  $suggestions
     * @return array<string, mixed>
     */
    public function build(JobSeekerProfile $profile, Collection $suggestions): array
    {
        $draft = $this->snapshotService->snapshot($profile);

        foreach ($suggestions as $suggestion) {
            if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE
                || $suggestion->status !== ProfileChangeSuggestion::STATUS_ACCEPTED) {
                continue;
            }

            $value = $suggestion->user_edited_value ?: $suggestion->new_value;
            if (! is_array($value)) {
                continue;
            }

            match ($suggestion->entity_type) {
                ProfileChangeSuggestion::ENTITY_PROFILE => $draft['profile'] = array_replace($draft['profile'], $value),
                ProfileChangeSuggestion::ENTITY_EXPERIENCE => $draft['experience'] = $this->mergeEntity(
                    $draft['experience'],
                    $suggestion,
                    $value,
                ),
                ProfileChangeSuggestion::ENTITY_EDUCATION => $draft['education'] = $this->mergeEntity(
                    $draft['education'],
                    $suggestion,
                    $value,
                ),
                ProfileChangeSuggestion::ENTITY_SKILL => $draft['skills'] = $this->mergeSkill($draft['skills'], $value),
                default => null,
            };
        }

        return $this->reviewDraftService->normalize($draft);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function mergeEntity(array $items, ProfileChangeSuggestion $suggestion, array $value): array
    {
        $id = $suggestion->old_value['id'] ?? null;
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_ADD || ! is_numeric($id)) {
            $items[] = $value;

            return array_values($items);
        }

        foreach ($items as $index => $item) {
            if (($item['id'] ?? null) !== (int) $id) {
                continue;
            }
            if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE) {
                foreach ($value as $field => $incoming) {
                    if (($item[$field] ?? null) === null || ($item[$field] ?? null) === '') {
                        $items[$index][$field] = $incoming;
                    }
                }
            } else {
                $items[$index] = array_replace($item, $value, ['id' => (int) $id]);
            }

            break;
        }

        return array_values($items);
    }

    /** @param list<string> $skills @param array<string, mixed> $value @return list<string> */
    private function mergeSkill(array $skills, array $value): array
    {
        $name = is_string($value['name'] ?? null) ? trim($value['name']) : '';
        if ($name !== '' && ! collect($skills)->contains(fn (string $item): bool => mb_strtolower($item) === mb_strtolower($name))) {
            $skills[] = $name;
        }

        return array_values($skills);
    }
}
