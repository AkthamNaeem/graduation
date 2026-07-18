<?php

namespace App\Services;

use App\Exceptions\CVLifecycleException;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileSyncService
{
    private const SOURCE_CV_CONFIRMED = 'cv_confirmed';
    private const SOURCE_CV_MERGED = 'cv_merged';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return Collection<int, ProfileChangeSuggestion>
     */
    public function generateSuggestionsFromParsedCV(User $user, CVFile $cvFile): Collection
    {
        $cvFile = $this->ownedCVFile($user, $cvFile)->load('parsingResult');
        $this->assertCVMutable($cvFile);

        if (! $cvFile->parsingResult instanceof CVParsingResult) {
            abort(404);
        }

        $existing = $cvFile->profileChangeSuggestions()
            ->with('cvFile')
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $suggestions = DB::transaction(function () use ($user, $cvFile): Collection {
            $profile = $this->jobSeekerProfile($user)->load(['experiences', 'education', 'skills']);
            $parsed = $cvFile->parsingResult?->parsed_json ?? [];

            $this->suggestProfileScalars($user, $profile, $cvFile, $parsed);
            $this->suggestExperiences($user, $profile, $cvFile, $parsed['experience'] ?? []);
            $this->suggestEducation($user, $profile, $cvFile, $parsed['education'] ?? []);
            $this->suggestSkills($user, $profile, $cvFile, $parsed['skills'] ?? []);

            return $cvFile->profileChangeSuggestions()
                ->with('cvFile')
                ->where('user_id', $user->id)
                ->latest()
                ->get();
        });

        $this->auditLogService->record(
            'cv.suggestions.generated',
            $user,
            CVFile::class,
            $cvFile->id,
            null,
            ['suggestion_count' => $suggestions->count()],
        );

        return $suggestions;
    }

    /**
     * @return Collection<int, ProfileChangeSuggestion>
     */
    public function suggestionsForCV(User $user, CVFile $cvFile): Collection
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);

        return $cvFile->profileChangeSuggestions()
            ->with('cvFile')
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }

    public function accept(User $user, ProfileChangeSuggestion $suggestion, ?array $editedValue = null): ProfileChangeSuggestion
    {
        $suggestion = $this->ownedSuggestion($user, $suggestion);
        $this->assertSuggestionMutable($suggestion);

        if ($suggestion->status !== ProfileChangeSuggestion::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'suggestion' => ['Only pending suggestions can be accepted.'],
            ]);
        }

        return DB::transaction(function () use ($user, $suggestion, $editedValue): ProfileChangeSuggestion {
            $before = $suggestion->only(['status', 'user_edited_value', 'decided_at', 'applied_at']);

            $suggestion->forceFill([
                'status' => ProfileChangeSuggestion::STATUS_ACCEPTED,
                'user_edited_value' => $editedValue,
                'decided_at' => now(),
            ])->save();

            $applied = $this->applySuggestion($suggestion);

            $this->auditLogService->record(
                'cv.suggestion.accepted',
                $user,
                ProfileChangeSuggestion::class,
                $suggestion->id,
                $before,
                $applied->only(['status', 'user_edited_value', 'decided_at', 'applied_at']),
            );

            return $applied;
        });
    }

    public function reject(User $user, ProfileChangeSuggestion $suggestion, ?string $reason = null): ProfileChangeSuggestion
    {
        $suggestion = $this->ownedSuggestion($user, $suggestion);
        $this->assertSuggestionMutable($suggestion);

        if (! in_array($suggestion->status, [ProfileChangeSuggestion::STATUS_PENDING, ProfileChangeSuggestion::STATUS_ACCEPTED], true)) {
            throw ValidationException::withMessages([
                'suggestion' => ['Only pending or accepted suggestions can be rejected.'],
            ]);
        }

        $before = $suggestion->only(['status', 'reason', 'decided_at']);

        $suggestion->forceFill([
            'status' => ProfileChangeSuggestion::STATUS_REJECTED,
            'reason' => $reason ?? $suggestion->reason,
            'decided_at' => now(),
        ])->save();

        $this->markCVConfirmedIfReviewed($suggestion);
        $suggestion = $suggestion->refresh();

        $this->auditLogService->record(
            'cv.suggestion.rejected',
            $user,
            ProfileChangeSuggestion::class,
            $suggestion->id,
            $before,
            $suggestion->only(['status', 'reason', 'decided_at']),
        );

        return $suggestion;
    }

    /**
     * @param  array<int, int>  $suggestionIds
     * @return Collection<int, ProfileChangeSuggestion>
     */
    public function applyBulk(User $user, array $suggestionIds): Collection
    {
        $suggestions = ProfileChangeSuggestion::query()
            ->whereIn('id', $suggestionIds)
            ->get();

        if ($suggestions->count() !== count(array_unique($suggestionIds))) {
            abort(404);
        }

        foreach ($suggestions as $suggestion) {
            $this->ownedSuggestion($user, $suggestion);
            $this->assertSuggestionMutable($suggestion);
        }

        $appliedSuggestions = DB::transaction(function () use ($suggestions): Collection {
            foreach ($suggestions as $suggestion) {
                if ($suggestion->status !== ProfileChangeSuggestion::STATUS_ACCEPTED) {
                    throw ValidationException::withMessages([
                        'suggestion_ids' => ['Only accepted suggestions can be applied.'],
                    ]);
                }

                $this->applySuggestion($suggestion);
            }

            return $suggestions->fresh();
        });

        $this->auditLogService->record(
            'cv.suggestions.applied',
            $user,
            ProfileChangeSuggestion::class,
            null,
            null,
            ['suggestion_ids' => $appliedSuggestions->pluck('id')->all()],
        );

        return $appliedSuggestions;
    }

    private function applySuggestion(ProfileChangeSuggestion $suggestion): ProfileChangeSuggestion
    {
        if ($suggestion->status === ProfileChangeSuggestion::STATUS_APPLIED) {
            return $suggestion;
        }

        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE) {
            $suggestion->forceFill([
                'status' => ProfileChangeSuggestion::STATUS_APPLIED,
                'applied_at' => now(),
            ])->save();

            $this->markCVConfirmedIfReviewed($suggestion);

            return $suggestion->refresh();
        }

        $profile = $suggestion->jobSeekerProfile()->firstOrFail();
        $value = $suggestion->user_edited_value ?: $suggestion->new_value;

        match ($suggestion->entity_type) {
            ProfileChangeSuggestion::ENTITY_PROFILE => $this->applyProfileUpdate($profile, $value),
            ProfileChangeSuggestion::ENTITY_EXPERIENCE => $this->applyExperience($profile, $suggestion, $value),
            ProfileChangeSuggestion::ENTITY_EDUCATION => $this->applyEducation($profile, $suggestion, $value),
            ProfileChangeSuggestion::ENTITY_SKILL => $this->applySkill($profile, $suggestion, $value),
            default => null,
        };

        $suggestion->forceFill([
            'status' => ProfileChangeSuggestion::STATUS_APPLIED,
            'applied_at' => now(),
        ])->save();

        $this->markCVConfirmedIfReviewed($suggestion);

        return $suggestion->refresh();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function suggestProfileScalars(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $parsed): void
    {
        foreach (['phone'] as $field) {
            $parsedValue = $this->cleanString($parsed[$field] ?? null);

            if ($parsedValue === null) {
                continue;
            }

            $currentValue = $this->cleanString($profile->{$field});

            if ($currentValue === null) {
                $this->createSuggestion($user, $profile, $cvFile, [
                    'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
                    'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
                    'old_value' => [$field => null],
                    'new_value' => [$field => $parsedValue],
                    'confidence_score' => 0.80,
                    'reason' => "CV contains {$field} and the profile field is empty.",
                ]);

                continue;
            }

            if ($this->normalize($currentValue) !== $this->normalize($parsedValue)) {
                $this->createSuggestion($user, $profile, $cvFile, [
                    'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
                    'suggestion_type' => ProfileChangeSuggestion::TYPE_IGNORE,
                    'old_value' => [$field => $currentValue],
                    'new_value' => [$field => $parsedValue],
                    'confidence_score' => 0.60,
                    'reason' => "Existing manual {$field} was kept as the source of truth.",
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function suggestExperiences(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = array_filter([
                'title' => $this->cleanString($item['title'] ?? null),
                'company_name' => $this->cleanString($item['company_name'] ?? null),
                'location' => $this->cleanString($item['location'] ?? null),
                'start_date' => $this->cleanString($item['start_date'] ?? null),
                'end_date' => $this->cleanString($item['end_date'] ?? null),
                'description' => $this->cleanString($item['description'] ?? null),
            ], fn (mixed $value): bool => $value !== null);

            if (! isset($value['title'], $value['company_name'])) {
                continue;
            }

            $duplicate = $profile->experiences->first(fn (Experience $experience): bool => $this->experienceKey($experience->toArray()) === $this->experienceKey($value));

            $this->createSuggestion($user, $profile, $cvFile, [
                'entity_type' => ProfileChangeSuggestion::ENTITY_EXPERIENCE,
                'suggestion_type' => $duplicate instanceof Experience
                    ? ($this->hasMergeableDifference($duplicate->toArray(), $value) ? ProfileChangeSuggestion::TYPE_MERGE : ProfileChangeSuggestion::TYPE_IGNORE)
                    : ProfileChangeSuggestion::TYPE_ADD,
                'old_value' => $duplicate?->only(['id', 'title', 'company_name', 'location', 'start_date', 'end_date', 'is_current', 'description']),
                'new_value' => $value,
                'confidence_score' => $duplicate instanceof Experience ? 0.92 : 0.78,
                'reason' => $duplicate instanceof Experience
                    ? 'Matched by normalized title and company, so it will not be blindly duplicated.'
                    : 'New experience found in parsed CV.',
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function suggestEducation(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = array_filter([
                'institution' => $this->cleanString($item['institution'] ?? null),
                'degree' => $this->cleanString($item['degree'] ?? null),
                'field_of_study' => $this->cleanString($item['field_of_study'] ?? null),
                'start_date' => $this->cleanString($item['start_date'] ?? null),
                'end_date' => $this->cleanString($item['end_date'] ?? null),
                'description' => $this->cleanString($item['description'] ?? null),
            ], fn (mixed $value): bool => $value !== null);

            if (! isset($value['institution'])) {
                continue;
            }

            $duplicate = $profile->education->first(fn (Education $education): bool => $this->educationKey($education->toArray()) === $this->educationKey($value));

            $this->createSuggestion($user, $profile, $cvFile, [
                'entity_type' => ProfileChangeSuggestion::ENTITY_EDUCATION,
                'suggestion_type' => $duplicate instanceof Education
                    ? ($this->hasMergeableDifference($duplicate->toArray(), $value) ? ProfileChangeSuggestion::TYPE_MERGE : ProfileChangeSuggestion::TYPE_IGNORE)
                    : ProfileChangeSuggestion::TYPE_ADD,
                'old_value' => $duplicate?->only(['id', 'institution', 'degree', 'field_of_study', 'start_date', 'end_date', 'description']),
                'new_value' => $value,
                'confidence_score' => $duplicate instanceof Education ? 0.90 : 0.76,
                'reason' => $duplicate instanceof Education
                    ? 'Matched by normalized institution and degree, so it will not be blindly duplicated.'
                    : 'New education entry found in parsed CV.',
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $skillNames
     */
    private function suggestSkills(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $skillNames): void
    {
        collect($skillNames)
            ->filter(fn (mixed $skill): bool => is_string($skill) && trim($skill) !== '')
            ->map(fn (string $skill): array => ['name' => trim($skill), 'slug' => Str::slug($skill)])
            ->filter(fn (array $skill): bool => $skill['slug'] !== '')
            ->unique('slug')
            ->each(function (array $value) use ($user, $profile, $cvFile): void {
                $existingSkill = Skill::query()->where('slug', $value['slug'])->first();
                $alreadyAttached = $existingSkill instanceof Skill
                    && $profile->skills->contains('id', $existingSkill->id);

                $this->createSuggestion($user, $profile, $cvFile, [
                    'entity_type' => ProfileChangeSuggestion::ENTITY_SKILL,
                    'suggestion_type' => $alreadyAttached ? ProfileChangeSuggestion::TYPE_IGNORE : ProfileChangeSuggestion::TYPE_ADD,
                    'old_value' => $alreadyAttached ? $existingSkill->only(['id', 'name', 'slug']) : null,
                    'new_value' => array_filter([
                        'id' => $existingSkill?->id,
                        'name' => $existingSkill?->name ?? $value['name'],
                        'slug' => $value['slug'],
                    ]),
                    'confidence_score' => $alreadyAttached ? 0.95 : 0.82,
                    'reason' => $alreadyAttached
                        ? 'Skill already exists on the profile.'
                        : 'Skill found in parsed CV.',
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSuggestion(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $attributes): ProfileChangeSuggestion
    {
        return ProfileChangeSuggestion::query()->create(array_merge([
            'user_id' => $user->id,
            'cv_file_id' => $cvFile->id,
            'job_seeker_profile_id' => $profile->id,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'source' => ProfileChangeSuggestion::SOURCE_CV_PARSED,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function applyProfileUpdate(JobSeekerProfile $profile, array $value): void
    {
        $allowed = collect($value)
            ->only(['phone'])
            ->filter(fn (mixed $newValue, string $field): bool => $this->cleanString($profile->{$field}) === null && $this->cleanString($newValue) !== null)
            ->all();

        if ($allowed !== []) {
            $profile->update($allowed);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function applyExperience(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_ADD) {
            if (! $profile->experiences()->get()->contains(fn (Experience $experience): bool => $this->experienceKey($experience->toArray()) === $this->experienceKey($value))) {
                $profile->experiences()->create(array_merge(
                    $this->experiencePayload($value),
                    $this->cvSourcePayload($suggestion, self::SOURCE_CV_CONFIRMED),
                ));
            }

            return;
        }

        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE && isset($suggestion->old_value['id'])) {
            $experience = $profile->experiences()->whereKey($suggestion->old_value['id'])->first();

            if ($experience instanceof Experience) {
                $updates = $this->onlyEmptyFields($experience, $this->experiencePayload($value));

                if ($updates !== []) {
                    $experience->update(array_merge(
                        $updates,
                        $this->cvSourcePayload($suggestion, self::SOURCE_CV_MERGED),
                    ));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function applyEducation(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_ADD) {
            if (! $profile->education()->get()->contains(fn (Education $education): bool => $this->educationKey($education->toArray()) === $this->educationKey($value))) {
                $profile->education()->create(array_merge(
                    $this->educationPayload($value),
                    $this->cvSourcePayload($suggestion, self::SOURCE_CV_CONFIRMED),
                ));
            }

            return;
        }

        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE && isset($suggestion->old_value['id'])) {
            $education = $profile->education()->whereKey($suggestion->old_value['id'])->first();

            if ($education instanceof Education) {
                $updates = $this->onlyEmptyFields($education, $this->educationPayload($value));

                if ($updates !== []) {
                    $education->update(array_merge(
                        $updates,
                        $this->cvSourcePayload($suggestion, self::SOURCE_CV_MERGED),
                    ));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function applySkill(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        $name = $this->cleanString($value['name'] ?? null);
        $slug = $this->cleanString($value['slug'] ?? null);

        if ($name === null || $slug === null) {
            return;
        }

        $skill = Skill::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        $profile->skills()->syncWithoutDetaching([
            $skill->id => $this->cvSourcePayload($suggestion, self::SOURCE_CV_CONFIRMED),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function experiencePayload(array $value): array
    {
        return collect($value)
            ->only(['title', 'company_name', 'location', 'start_date', 'end_date', 'description'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function educationPayload(array $value): array
    {
        return collect($value)
            ->only(['institution', 'degree', 'field_of_study', 'start_date', 'end_date', 'description'])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function cvSourcePayload(ProfileChangeSuggestion $suggestion, string $sourceType): array
    {
        return [
            'source_type' => $sourceType,
            'source_cv_file_id' => $suggestion->cv_file_id,
            'user_verified_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function hasMergeableDifference(array $existing, array $incoming): bool
    {
        foreach ($incoming as $field => $value) {
            if ($this->cleanString($value) !== null && $this->cleanString($existing[$field] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function onlyEmptyFields(Experience|Education $model, array $incoming): array
    {
        return collect($incoming)
            ->filter(fn (mixed $value, string $field): bool => $this->cleanString($value) !== null && $this->cleanString($model->{$field}) === null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function experienceKey(array $value): string
    {
        return $this->normalize(($value['title'] ?? '').'|'.($value['company_name'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function educationKey(array $value): string
    {
        return $this->normalize(($value['institution'] ?? '').'|'.($value['degree'] ?? ''));
    }

    private function normalize(mixed $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function ownedCVFile(User $user, CVFile $cvFile): CVFile
    {
        abort_unless($cvFile->user_id === $user->id, 404);

        return $cvFile;
    }

    private function ownedSuggestion(User $user, ProfileChangeSuggestion $suggestion): ProfileChangeSuggestion
    {
        abort_unless($suggestion->user_id === $user->id, 404);

        return $suggestion;
    }

    private function assertSuggestionMutable(ProfileChangeSuggestion $suggestion): void
    {
        $cvFile = $suggestion->cvFile()->first();
        if ($cvFile instanceof CVFile) {
            $this->assertCVMutable($cvFile);
        }
    }

    private function assertCVMutable(CVFile $cvFile): void
    {
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException('Archived CV data is read-only.', 'CV_ARCHIVED_READ_ONLY');
        }
    }

    private function jobSeekerProfile(User $user): JobSeekerProfile
    {
        return $user->jobSeekerProfile()->firstOrFail();
    }

    private function markCVConfirmedIfReviewed(ProfileChangeSuggestion $suggestion): void
    {
        if (! $suggestion->cv_file_id) {
            return;
        }

        $hasPendingOrAccepted = ProfileChangeSuggestion::query()
            ->where('cv_file_id', $suggestion->cv_file_id)
            ->whereIn('status', [ProfileChangeSuggestion::STATUS_PENDING, ProfileChangeSuggestion::STATUS_ACCEPTED])
            ->exists();

        if (! $hasPendingOrAccepted && $suggestion->cvFile?->confirmed_at === null) {
            $suggestion->cvFile->forceFill(['confirmed_at' => now()])->save();
            $this->auditLogService->record('cv.parsed_data_confirmed', $suggestion->user, CVFile::class, $suggestion->cv_file_id, null, null, [
                'cv_file_id' => $suggestion->cv_file_id,
                'user_id' => $suggestion->user_id,
                'actor_id' => $suggestion->user_id,
                'parsing_status' => $suggestion->cvFile->status,
            ]);
        }
    }
}
