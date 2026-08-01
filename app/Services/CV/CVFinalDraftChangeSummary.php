<?php

namespace App\Services\CV;

use Illuminate\Support\Collection;

class CVFinalDraftChangeSummary
{
    public function __construct(private readonly CVReviewDraftService $draftService) {}

    /**
     * @param  array<string, mixed>|null  $comparisonBase
     * @param  array<string, mixed>|null  $finalDraft
     * @return array{added:int,updated:int,merged:int,removed:int,unchanged:int}
     */
    public function summarize(?array $comparisonBase, ?array $finalDraft, int $merged = 0): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'merged' => $merged, 'removed' => 0, 'unchanged' => 0];
        if ($finalDraft === null) {
            return $counts;
        }

        $base = $this->draftService->normalize($comparisonBase ?? []);
        $final = $this->draftService->normalize($finalDraft);

        foreach (array_keys($final['profile']) as $field) {
            $this->compareValue($base['profile'][$field] ?? null, $final['profile'][$field] ?? null, $counts);
        }

        $this->compareRelationships($base['experience'], $final['experience'], $counts);
        $this->compareRelationships($base['education'], $final['education'], $counts);
        $this->compareSkills($base['skills'], $final['skills'], $counts);

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function compareValue(mixed $old, mixed $new, array &$counts): void
    {
        if ($old === $new) {
            if ($old !== null && $old !== '') {
                $counts['unchanged']++;
            }

            return;
        }
        if ($old === null || $old === '') {
            $counts['added']++;

            return;
        }
        if ($new === null || $new === '') {
            $counts['removed']++;

            return;
        }
        $counts['updated']++;
    }

    /** @param list<array<string, mixed>> $base @param list<array<string, mixed>> $final @param array<string, int> $counts */
    private function compareRelationships(array $base, array $final, array &$counts): void
    {
        $baseById = collect($base)->filter(fn (array $item): bool => isset($item['id']))->keyBy('id');
        $finalById = collect($final)->filter(fn (array $item): bool => isset($item['id']))->keyBy('id');

        foreach ($baseById as $id => $item) {
            $candidate = $finalById->get($id);
            if (! is_array($candidate)) {
                $counts['removed']++;
            } elseif ($this->canonical($item) === $this->canonical($candidate)) {
                $counts['unchanged']++;
            } else {
                $counts['updated']++;
            }
        }

        $counts['added'] += $finalById->keys()->diff($baseById->keys())->count();
        $this->compareUnidentified(
            collect($base)->reject(fn (array $item): bool => isset($item['id'])),
            collect($final)->reject(fn (array $item): bool => isset($item['id'])),
            $counts,
        );
    }

    /** @param Collection<int, array<string, mixed>> $base @param Collection<int, array<string, mixed>> $final @param array<string, int> $counts */
    private function compareUnidentified(Collection $base, Collection $final, array &$counts): void
    {
        $remaining = $final->map(fn (array $item): string => $this->canonical($item))->values();
        foreach ($base as $item) {
            $fingerprint = $this->canonical($item);
            $index = $remaining->search($fingerprint, true);
            if ($index === false) {
                $counts['removed']++;

                continue;
            }
            $counts['unchanged']++;
            $remaining->forget($index);
        }
        $counts['added'] += $remaining->count();
    }

    /** @param list<string> $base @param list<string> $final @param array<string, int> $counts */
    private function compareSkills(array $base, array $final, array &$counts): void
    {
        $old = collect($base)->map(fn (string $value): string => mb_strtolower(trim($value)))->unique();
        $new = collect($final)->map(fn (string $value): string => mb_strtolower(trim($value)))->unique();
        $counts['added'] += $new->diff($old)->count();
        $counts['removed'] += $old->diff($new)->count();
        $counts['unchanged'] += $old->intersect($new)->count();
    }

    /** @param array<string, mixed> $value */
    private function canonical(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
