<?php

namespace App\Services\CV;

use App\Models\CVFile;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CVApprovedProfileApplier
{
    /**
     * @param  array<string, mixed>  $draft
     * @return array{added:int,updated:int,merged:int,removed:int,unchanged:int}
     */
    public function apply(
        JobSeekerProfile $profile,
        CVFile $cvFile,
        array $draft,
        bool $deleteMissing,
        ?array $systemGeneratedDraft = null,
    ): array {
        $counts = ['added' => 0, 'updated' => 0, 'merged' => 0, 'removed' => 0, 'unchanged' => 0];
        $profileFields = collect($draft['profile'])->only([
            'headline', 'summary', 'phone', 'location', 'city_id', 'portfolio_url', 'linkedin_url', 'github_url',
        ])->all();
        $profile->forceFill($profileFields);
        if ($profile->isDirty()) {
            $counts['updated'] += count($profile->getDirty());
            $profile->save();
        } else {
            $counts['unchanged'] += count($profileFields);
        }

        $this->syncExperiences($profile, $cvFile, $draft['experience'], $deleteMissing, $counts, $systemGeneratedDraft);
        $this->syncEducation($profile, $cvFile, $draft['education'], $deleteMissing, $counts, $systemGeneratedDraft);
        $this->syncSkills($profile, $cvFile, $draft['skills'], $deleteMissing, $counts, $systemGeneratedDraft);

        return $counts;
    }

    /** @param list<array<string, mixed>> $items @param array<string, int> $counts */
    private function syncExperiences(JobSeekerProfile $profile, CVFile $cvFile, array $items, bool $deleteMissing, array &$counts, ?array $systemGeneratedDraft): void
    {
        $existing = $profile->experiences()->lockForUpdate()->get()->keyBy('id');
        $retained = [];
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            $payload = collect($item)->only([
                'title', 'company_name', 'location', 'start_date', 'end_date', 'is_current', 'description',
            ])->all();
            if ($id !== null) {
                $model = $existing->get($id);
                if (! $model instanceof Experience) {
                    $this->invalidRelationship('experience', $id);
                }
                $retained[] = $id;
                $model->forceFill($payload);
                if ($model->isDirty()) {
                    $model->forceFill($this->source(
                        $cvFile,
                        $this->wasUserEdited('experience', $item, $systemGeneratedDraft),
                    ))->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }

                continue;
            }
            $created = $profile->experiences()->create(array_merge($payload, $this->source(
                $cvFile,
                $this->wasUserEdited('experience', $item, $systemGeneratedDraft),
            )));
            $retained[] = $created->id;
            $counts['added']++;
        }

        if ($deleteMissing) {
            $removed = $existing->keys()->diff($retained);
            if ($removed->isNotEmpty()) {
                $profile->experiences()->whereKey($removed)->delete();
                $counts['removed'] += $removed->count();
            }
        }
    }

    /** @param list<array<string, mixed>> $items @param array<string, int> $counts */
    private function syncEducation(JobSeekerProfile $profile, CVFile $cvFile, array $items, bool $deleteMissing, array &$counts, ?array $systemGeneratedDraft): void
    {
        $existing = $profile->education()->lockForUpdate()->get()->keyBy('id');
        $retained = [];
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            $payload = collect($item)->only([
                'institution', 'degree', 'field_of_study', 'start_date', 'end_date', 'description',
            ])->all();
            if ($id !== null) {
                $model = $existing->get($id);
                if (! $model instanceof Education) {
                    $this->invalidRelationship('education', $id);
                }
                $retained[] = $id;
                $model->forceFill($payload);
                if ($model->isDirty()) {
                    $model->forceFill($this->source(
                        $cvFile,
                        $this->wasUserEdited('education', $item, $systemGeneratedDraft),
                    ))->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }

                continue;
            }
            $created = $profile->education()->create(array_merge($payload, $this->source(
                $cvFile,
                $this->wasUserEdited('education', $item, $systemGeneratedDraft),
            )));
            $retained[] = $created->id;
            $counts['added']++;
        }

        if ($deleteMissing) {
            $removed = $existing->keys()->diff($retained);
            if ($removed->isNotEmpty()) {
                $profile->education()->whereKey($removed)->delete();
                $counts['removed'] += $removed->count();
            }
        }
    }

    /** @param list<string> $skillNames @param array<string, int> $counts */
    private function syncSkills(JobSeekerProfile $profile, CVFile $cvFile, array $skillNames, bool $deleteMissing, array &$counts, ?array $systemGeneratedDraft): void
    {
        $desired = collect($skillNames)
            ->map(fn (string $name): array => ['name' => trim($name), 'slug' => Str::slug($name)])
            ->filter(fn (array $item): bool => $item['name'] !== '' && $item['slug'] !== '')
            ->unique('slug')
            ->values();
        $now = now();
        DB::table('skills')->insertOrIgnore($desired->map(fn (array $item): array => [
            ...$item,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
        $skills = Skill::query()->whereIn('slug', $desired->pluck('slug'))->get()->keyBy('slug');
        $attached = $profile->skills()->get()->keyBy('id');
        $sync = [];
        foreach ($desired as $item) {
            $skill = $skills->get($item['slug']);
            if (! $skill instanceof Skill) {
                continue;
            }
            $current = $attached->get($skill->id);
            if ($current instanceof Skill) {
                $sync[$skill->id] = [
                    'source_type' => $current->pivot->source_type,
                    'source_cv_file_id' => $current->pivot->source_cv_file_id,
                    'user_verified_at' => $current->pivot->user_verified_at,
                ];
                $counts['unchanged']++;
            } else {
                $systemSkills = collect($systemGeneratedDraft['skills'] ?? [])
                    ->filter(fn (mixed $name): bool => is_string($name))
                    ->map(fn (string $name): string => mb_strtolower(trim($name)));
                $sync[$skill->id] = $this->source(
                    $cvFile,
                    $systemGeneratedDraft !== null && ! $systemSkills->contains(mb_strtolower($item['name'])),
                );
                $counts['added']++;
            }
        }

        if ($deleteMissing) {
            $counts['removed'] += $attached->keys()->diff(array_keys($sync))->count();
            $profile->skills()->sync($sync);
        } else {
            $profile->skills()->syncWithoutDetaching($sync);
        }
    }

    /** @return array{source_type:string,source_cv_file_id:int,user_verified_at:Carbon} */
    private function source(CVFile $cvFile, bool $userEdited = false): array
    {
        return [
            'source_type' => $userEdited ? 'manual' : 'cv_confirmed',
            'source_cv_file_id' => $userEdited ? null : $cvFile->id,
            'user_verified_at' => now(),
        ];
    }

    /** @param array<string, mixed> $item @param array<string, mixed>|null $systemGeneratedDraft */
    private function wasUserEdited(string $section, array $item, ?array $systemGeneratedDraft): bool
    {
        if ($systemGeneratedDraft === null) {
            return false;
        }

        $systemItems = collect($systemGeneratedDraft[$section] ?? [])->filter(fn (mixed $value): bool => is_array($value));
        $id = isset($item['id']) ? (int) $item['id'] : null;
        if ($id !== null) {
            $generated = $systemItems->first(fn (array $value): bool => (int) ($value['id'] ?? 0) === $id);

            return ! is_array($generated) || $this->canonical($generated) !== $this->canonical($item);
        }

        return ! $systemItems->contains(fn (array $value): bool => ! isset($value['id'])
            && $this->canonical($value) === $this->canonical($item));
    }

    /** @param array<string, mixed> $value */
    private function canonical(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function invalidRelationship(string $section, int $id): never
    {
        throw ValidationException::withMessages([
            $section => [__('validation.custom_messages.invalid_draft_relationship', ['id' => $id])],
        ]);
    }
}
