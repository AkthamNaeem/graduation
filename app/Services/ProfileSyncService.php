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

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** @return Collection<int, ProfileChangeSuggestion> */
    public function generateSuggestionsFromParsedCV(User $user, CVFile $cvFile): Collection
    {
        $cvFile = $this->ownedCVFile($user, $cvFile)->load('parsingResult');
        $this->assertCVMutable($cvFile);
        if (! $cvFile->parsingResult instanceof CVParsingResult) {
            abort(404);
        }
        if ($cvFile->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT) {
            throw new CVLifecycleException('Initial CV imports use the review draft flow.', 'CV_REVIEW_MODE_INVALID');
        }

        $suggestions = DB::transaction(function () use ($user, $cvFile): Collection {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->ownedCVFile($user, $lockedCV);
            $this->assertCVMutable($lockedCV);
            if ($lockedCV->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT) {
                throw new CVLifecycleException('Initial CV imports use the review draft flow.', 'CV_REVIEW_MODE_INVALID');
            }
            if ($lockedCV->review_mode === null) {
                $lockedCV->forceFill(['review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC])->save();
            }
            $existing = $lockedCV->profileChangeSuggestions()->with('cvFile')->where('user_id', $user->id)->latest()->get();
            if ($existing->isNotEmpty()) {
                $this->synchronizeReviewStatus($lockedCV);

                return $existing;
            }
            $result = CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->firstOrFail();
            $profile = $this->jobSeekerProfile($user)->load(['experiences', 'education', 'skills']);
            $parsed = $result->parsed_json ?? [];

            $this->suggestProfileScalars($user, $profile, $lockedCV, $parsed);
            $this->suggestExperiences($user, $profile, $lockedCV, $parsed['experience'] ?? []);
            $this->suggestEducation($user, $profile, $lockedCV, $parsed['education'] ?? []);
            $this->suggestSkills($user, $profile, $lockedCV, $parsed['skills'] ?? []);
            $this->synchronizeReviewStatus($lockedCV);

            return $lockedCV->profileChangeSuggestions()->with('cvFile')->where('user_id', $user->id)->latest()->get();
        });

        $counts = $suggestions->groupBy('entity_type')->map->count()->all();
        $this->auditLogService->record('cv.suggestions.generated', $user, CVFile::class, $cvFile->id, null, null, [
            'cv_file_id' => $cvFile->id,
            'actor_id' => $user->id,
            'suggestion_count' => $suggestions->count(),
            'entity_type_counts' => $counts,
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
        ]);

        return $suggestions;
    }

    /** @return Collection<int, ProfileChangeSuggestion> */
    public function suggestionsForCV(User $user, CVFile $cvFile): Collection
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);

        return $cvFile->profileChangeSuggestions()->with('cvFile')->where('user_id', $user->id)->latest()->get();
    }

    public function accept(User $user, ProfileChangeSuggestion $suggestion, ?array $editedValue = null): ProfileChangeSuggestion
    {
        $suggestion = $this->ownedSuggestion($user, $suggestion);
        $this->assertSuggestionMutable($suggestion);
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE) {
            throw ValidationException::withMessages(['suggestion' => ['Matched items do not require a decision.']]);
        }

        return DB::transaction(function () use ($user, $suggestion, $editedValue): ProfileChangeSuggestion {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($suggestion->cv_file_id);
            $this->ownedCVFile($user, $lockedCV);
            $this->assertCVMutable($lockedCV);
            $locked = ProfileChangeSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            $this->ownedSuggestion($user, $locked);
            $this->assertSuggestionMutable($locked);
            $locked->forceFill([
                'status' => ProfileChangeSuggestion::STATUS_ACCEPTED,
                'user_edited_value' => $editedValue,
                'decided_at' => now(),
            ])->save();
            $this->synchronizeReviewStatus($lockedCV);
            $this->auditDecision($user, $locked, 'accepted');

            return $locked->refresh()->load('cvFile');
        });
    }

    public function reject(User $user, ProfileChangeSuggestion $suggestion, ?string $reason = null): ProfileChangeSuggestion
    {
        $suggestion = $this->ownedSuggestion($user, $suggestion);
        $this->assertSuggestionMutable($suggestion);
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE) {
            throw ValidationException::withMessages(['suggestion' => ['Matched items do not require a decision.']]);
        }

        return DB::transaction(function () use ($user, $suggestion, $reason): ProfileChangeSuggestion {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($suggestion->cv_file_id);
            $this->ownedCVFile($user, $lockedCV);
            $this->assertCVMutable($lockedCV);
            $locked = ProfileChangeSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            $this->ownedSuggestion($user, $locked);
            $this->assertSuggestionMutable($locked);
            $locked->forceFill(['status' => ProfileChangeSuggestion::STATUS_REJECTED, 'reason' => $reason ?? $locked->reason, 'decided_at' => now()])->save();
            $this->synchronizeReviewStatus($lockedCV);
            $this->auditDecision($user, $locked, 'rejected');

            return $locked->refresh()->load('cvFile');
        });
    }

    /**
     * @return array{applied_count:int,rejected_count:int,ignored_count:int,already_applied:bool,profile:JobSeekerProfile}
     */
    public function applyCV(User $user, CVFile $cvFile): array
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);

        $result = DB::transaction(function () use ($user, $cvFile): array {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->ownedCVFile($user, $lockedCV);
            if ($lockedCV->archived_at !== null) {
                throw new CVLifecycleException('Archived CV data is read-only.', 'CV_ARCHIVED_READ_ONLY');
            }
            if ($lockedCV->review_status === CVFile::REVIEW_STATUS_APPLIED) {
                return $this->applicationResult($user, $lockedCV, true);
            }
            if (($lockedCV->review_mode ?? CVFile::REVIEW_MODE_PROFILE_SYNC) !== CVFile::REVIEW_MODE_PROFILE_SYNC) {
                throw new CVLifecycleException('This CV does not use profile synchronization.', 'CV_REVIEW_MODE_INVALID');
            }
            if ($lockedCV->review_mode === null) {
                $lockedCV->forceFill(['review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC])->save();
            }

            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $suggestions = ProfileChangeSuggestion::query()
                ->where('cv_file_id', $lockedCV->id)
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lockedCV->review_status !== CVFile::REVIEW_STATUS_READY_TO_APPLY
                && $suggestions->isNotEmpty()
                && in_array($lockedCV->review_status, [null, CVFile::REVIEW_STATUS_DECISIONS_PENDING], true)) {
                $this->synchronizeReviewStatus($lockedCV);
            }
            if ($lockedCV->review_status !== CVFile::REVIEW_STATUS_READY_TO_APPLY) {
                throw new CVLifecycleException('The CV suggestions are not ready to apply.', 'CV_SUGGESTIONS_NOT_READY');
            }
            if ($suggestions->contains(fn (ProfileChangeSuggestion $item): bool => $item->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE && $item->status === ProfileChangeSuggestion::STATUS_PENDING)) {
                throw new CVLifecycleException('All actionable suggestions must be decided before applying.', 'CV_SUGGESTIONS_PENDING');
            }
            foreach ($suggestions->where('status', ProfileChangeSuggestion::STATUS_ACCEPTED)
                ->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE) as $suggestion) {
                $this->applySuggestion($profile, $suggestion);
                $suggestion->forceFill(['status' => ProfileChangeSuggestion::STATUS_APPLIED, 'applied_at' => now()])->save();
            }
            foreach ($suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_IGNORE) as $suggestion) {
                $suggestion->forceFill(['status' => ProfileChangeSuggestion::STATUS_APPLIED, 'applied_at' => now()])->save();
            }

            $lockedCV->forceFill(['review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC, 'review_status' => CVFile::REVIEW_STATUS_APPLIED, 'confirmed_at' => now()])->save();

            return $this->applicationResult($user, $lockedCV, false);
        });

        $this->auditLogService->record('cv.suggestions.applied', $user, CVFile::class, $cvFile->id, null, null, [
            'cv_file_id' => $cvFile->id,
            'actor_id' => $user->id,
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'applied_count' => $result['applied_count'],
            'rejected_count' => $result['rejected_count'],
            'ignored_count' => $result['ignored_count'],
        ]);

        return $result;
    }

    /** @param array<int, int> $suggestionIds
     * @return Collection<int, ProfileChangeSuggestion>
     */
    public function applyBulk(User $user, array $suggestionIds): Collection
    {
        $suggestions = ProfileChangeSuggestion::query()->whereIn('id', $suggestionIds)->get();
        if ($suggestions->count() !== count(array_unique($suggestionIds))) {
            abort(404);
        }
        foreach ($suggestions as $suggestion) {
            $this->ownedSuggestion($user, $suggestion);
            if ($suggestion->status !== ProfileChangeSuggestion::STATUS_ACCEPTED) {
                throw ValidationException::withMessages(['suggestion_ids' => ['Only accepted suggestions can be applied.']]);
            }
        }
        $cvIds = $suggestions->pluck('cv_file_id')->filter()->unique();
        if ($cvIds->count() !== 1) {
            throw ValidationException::withMessages(['suggestion_ids' => ['All suggestions must belong to one CV.']]);
        }

        $cvFile = CVFile::query()->findOrFail($cvIds->first());
        $this->applyCV($user, $cvFile);

        return ProfileChangeSuggestion::query()->whereIn('id', $suggestionIds)->with('cvFile')->get();
    }

    /** @param array<string, mixed> $parsed */
    private function suggestProfileScalars(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $parsed): void
    {
        foreach (['phone', 'summary', 'location'] as $field) {
            $incoming = $this->cleanString($parsed[$field] ?? null);
            if ($incoming === null) {
                continue;
            }
            $current = $this->cleanString($profile->{$field});
            $type = $current === null
                ? ProfileChangeSuggestion::TYPE_ADD
                : ($this->equivalent($current, $incoming) ? ProfileChangeSuggestion::TYPE_IGNORE : ProfileChangeSuggestion::TYPE_UPDATE);
            $this->createSuggestion($user, $profile, $cvFile, [
                'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
                'suggestion_type' => $type,
                'old_value' => [$field => $current],
                'new_value' => [$field => $incoming],
                'confidence_score' => $type === ProfileChangeSuggestion::TYPE_IGNORE ? 0.95 : 0.80,
                'reason' => $type === ProfileChangeSuggestion::TYPE_IGNORE ? 'The profile value already matches.' : 'The CV contains a profile value for review.',
            ]);
        }
    }

    /** @param array<int, mixed> $items */
    private function suggestExperiences(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $value = $this->experiencePayload(array_merge($item, [
                'description' => $this->cleanString($item['description'] ?? null) ?? $this->responsibilitiesDescription($item['responsibilities'] ?? null),
            ]));
            if (! isset($value['title'], $value['company_name'])) {
                continue;
            }
            $match = $this->matchExperience($profile, $value);
            $existing = $match instanceof Experience ? $this->experienceApplyPayload($this->modelSnapshot($match)) : null;
            $type = $existing !== null ? $this->differenceType($existing, $value) : ProfileChangeSuggestion::TYPE_ADD;
            $this->createSuggestion($user, $profile, $cvFile, [
                'entity_type' => ProfileChangeSuggestion::ENTITY_EXPERIENCE,
                'suggestion_type' => $type,
                'old_value' => $match ? array_merge(['id' => $match->id], $existing) : null,
                'new_value' => $value,
                'confidence_score' => $this->boundedConfidence($item['confidence_score'] ?? null, $match ? 0.92 : 0.78),
                'reason' => $match ? 'Matched by title, company, and start period.' : 'New experience found in parsed CV.',
            ]);
        }
    }

    /** @param array<int, mixed> $items */
    private function suggestEducation(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $item['start_date'] = $item['start_date'] ?? $this->yearDate($item['start_year'] ?? null);
            $item['end_date'] = $item['end_date'] ?? $this->yearDate($item['graduation_year'] ?? null);
            $value = $this->educationPayload($item);
            if (! isset($value['institution'])) {
                continue;
            }
            $match = $this->matchEducation($profile, $value);
            $existing = $match instanceof Education ? $this->educationApplyPayload($this->modelSnapshot($match)) : null;
            $type = $existing !== null ? $this->differenceType($existing, $value) : ProfileChangeSuggestion::TYPE_ADD;
            $this->createSuggestion($user, $profile, $cvFile, [
                'entity_type' => ProfileChangeSuggestion::ENTITY_EDUCATION,
                'suggestion_type' => $type,
                'old_value' => $match ? array_merge(['id' => $match->id], $existing) : null,
                'new_value' => $value,
                'confidence_score' => $this->boundedConfidence($item['confidence_score'] ?? null, $match ? 0.90 : 0.76),
                'reason' => $match ? 'Matched by institution, degree, and start or graduation period.' : 'New education entry found in parsed CV.',
            ]);
        }
    }

    /** @param array<int, mixed> $skillNames */
    private function suggestSkills(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $skillNames): void
    {
        collect($skillNames)->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): array => ['name' => trim($name), 'slug' => Str::slug($name)])
            ->filter(fn (array $skill): bool => $skill['slug'] !== '')->unique('slug')
            ->each(function (array $value) use ($user, $profile, $cvFile): void {
                $existing = Skill::query()->where('slug', $value['slug'])->first();
                $attached = $existing instanceof Skill && $profile->skills->contains('id', $existing->id);
                $this->createSuggestion($user, $profile, $cvFile, [
                    'entity_type' => ProfileChangeSuggestion::ENTITY_SKILL,
                    'suggestion_type' => $attached ? ProfileChangeSuggestion::TYPE_IGNORE : ProfileChangeSuggestion::TYPE_ADD,
                    'old_value' => $attached ? ['name' => $existing->name] : null,
                    'new_value' => ['name' => $existing?->name ?? $value['name']],
                    'confidence_score' => $attached ? 0.95 : 0.82,
                    'reason' => $attached ? 'Skill already exists on the profile.' : 'New skill found in parsed CV.',
                ]);
            });
    }

    private function applySuggestion(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion): void
    {
        $value = $suggestion->user_edited_value ?: $suggestion->new_value;
        $this->assertNotStale($profile, $suggestion);
        match ($suggestion->entity_type) {
            ProfileChangeSuggestion::ENTITY_PROFILE => $this->applyProfile($profile, $value),
            ProfileChangeSuggestion::ENTITY_EXPERIENCE => $this->applyExperience($profile, $suggestion, $value),
            ProfileChangeSuggestion::ENTITY_EDUCATION => $this->applyEducation($profile, $suggestion, $value),
            ProfileChangeSuggestion::ENTITY_SKILL => $this->applySkill($profile, $suggestion, $value),
            default => throw ValidationException::withMessages(['suggestion' => ['Unsupported suggestion entity.']]),
        };
    }

    private function assertNotStale(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion): void
    {
        $old = $suggestion->old_value;
        if ($suggestion->entity_type === ProfileChangeSuggestion::ENTITY_PROFILE) {
            $field = array_key_first($old ?? []);
            if (! is_string($field) || ! $this->equivalent($profile->{$field}, $old[$field] ?? null)) {
                $this->throwStale($suggestion);
            }

            return;
        }
        if ($suggestion->entity_type === ProfileChangeSuggestion::ENTITY_SKILL) {
            $value = $suggestion->user_edited_value ?: $suggestion->new_value;
            $name = $this->cleanString($value['name'] ?? null);
            $skill = $name === null ? null : Skill::query()->where('slug', Str::slug($name))->lockForUpdate()->first();
            if ($skill instanceof Skill && DB::table('job_seeker_skills')
                ->where('job_seeker_profile_id', $profile->id)
                ->where('skill_id', $skill->id)
                ->lockForUpdate()
                ->exists()) {
                $this->throwStale($suggestion);
            }

            return;
        }
        if (! in_array($suggestion->entity_type, [ProfileChangeSuggestion::ENTITY_EXPERIENCE, ProfileChangeSuggestion::ENTITY_EDUCATION], true)
            || ! isset($old['id'])) {
            return;
        }
        $query = $suggestion->entity_type === ProfileChangeSuggestion::ENTITY_EXPERIENCE ? $profile->experiences() : $profile->education();
        $model = $query->whereKey($old['id'])->lockForUpdate()->first();
        if (! $model) {
            $this->throwStale($suggestion);
        }
        foreach (collect($old)->except('id') as $field => $expected) {
            if (! $this->equivalent($this->scalar($model->{$field}), $expected)) {
                $this->throwStale($suggestion);
            }
        }
    }

    private function throwStale(ProfileChangeSuggestion $suggestion): never
    {
        throw new CVLifecycleException('A reviewed profile value changed before apply.', 'SUGGESTION_STALE', 409, [
            'suggestion_id' => $suggestion->id,
            'entity_type' => $suggestion->entity_type,
        ]);
    }

    private function applyProfile(JobSeekerProfile $profile, array $value): void
    {
        $profile->forceFill(collect($value)->only(['phone', 'summary', 'location'])->all())->save();
    }

    private function applyExperience(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        $payload = $this->experienceApplyPayload($value);
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_ADD) {
            if (! $profile->experiences()->get()->contains(fn (Experience $item): bool => $this->experienceIdentity($item->toArray()) === $this->experienceIdentity($payload))) {
                $profile->experiences()->create(array_merge($payload, $this->sourcePayload($suggestion, self::SOURCE_CV_CONFIRMED)));
            }

            return;
        }
        $model = $profile->experiences()->whereKey($suggestion->old_value['id'] ?? null)->lockForUpdate()->firstOrFail();
        $updates = $suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE ? $this->onlyEmpty($model, $payload) : $payload;
        if ($updates !== []) {
            $model->forceFill(array_merge($updates, $this->sourcePayload($suggestion, $suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE ? self::SOURCE_CV_MERGED : self::SOURCE_CV_CONFIRMED)))->save();
        }
    }

    private function applyEducation(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        $payload = $this->educationApplyPayload($value);
        if ($suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_ADD) {
            if (! $profile->education()->get()->contains(fn (Education $item): bool => $this->educationIdentity($item->toArray()) === $this->educationIdentity($payload))) {
                $profile->education()->create(array_merge($payload, $this->sourcePayload($suggestion, self::SOURCE_CV_CONFIRMED)));
            }

            return;
        }
        $model = $profile->education()->whereKey($suggestion->old_value['id'] ?? null)->lockForUpdate()->firstOrFail();
        $updates = $suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE ? $this->onlyEmpty($model, $payload) : $payload;
        if ($updates !== []) {
            $model->forceFill(array_merge($updates, $this->sourcePayload($suggestion, $suggestion->suggestion_type === ProfileChangeSuggestion::TYPE_MERGE ? self::SOURCE_CV_MERGED : self::SOURCE_CV_CONFIRMED)))->save();
        }
    }

    private function applySkill(JobSeekerProfile $profile, ProfileChangeSuggestion $suggestion, array $value): void
    {
        $name = $this->cleanString($value['name'] ?? null);
        $slug = $name === null ? '' : Str::slug($name);
        if ($slug === '') {
            throw ValidationException::withMessages(['suggestion' => ['The skill name is invalid.']]);
        }
        $skill = Skill::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        $profile->skills()->syncWithoutDetaching([$skill->id => $this->sourcePayload($suggestion, self::SOURCE_CV_CONFIRMED)]);
    }

    private function matchExperience(JobSeekerProfile $profile, array $value): ?Experience
    {
        $candidates = $profile->experiences->filter(fn (Experience $item): bool => $this->experienceBase($item->toArray()) === $this->experienceBase($value));

        return $this->uniquePeriodMatch($candidates, $value['start_date'] ?? null);
    }

    private function matchEducation(JobSeekerProfile $profile, array $value): ?Education
    {
        $candidates = $profile->education->filter(fn (Education $item): bool => $this->educationBase($item->toArray()) === $this->educationBase($value));
        $period = $this->educationPeriod($value);
        if ($period !== null) {
            $candidates = $candidates->filter(fn (Education $item): bool => $this->educationPeriod($item->toArray()) === $period);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function uniquePeriodMatch(Collection $candidates, mixed $startDate): Experience|Education|null
    {
        $period = $this->period($startDate);
        if ($period !== null) {
            $candidates = $candidates->filter(fn (Experience|Education $item): bool => $this->period($item->start_date) === $period);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function differenceType(array $existing, array $incoming): string
    {
        $merge = false;
        foreach ($incoming as $field => $value) {
            $old = $existing[$field] ?? null;
            if ($this->emptyValue($old) && ! $this->emptyValue($value)) {
                $merge = true;
            } elseif (! $this->emptyValue($old) && ! $this->emptyValue($value) && ! $this->equivalent($old, $value)) {
                return ProfileChangeSuggestion::TYPE_UPDATE;
            }
        }

        return $merge ? ProfileChangeSuggestion::TYPE_MERGE : ProfileChangeSuggestion::TYPE_IGNORE;
    }

    private function synchronizeReviewStatus(CVFile $cvFile): void
    {
        if ($cvFile->review_status === CVFile::REVIEW_STATUS_APPLIED) {
            return;
        }
        $pending = ProfileChangeSuggestion::query()->where('cv_file_id', $cvFile->id)
            ->where('status', ProfileChangeSuggestion::STATUS_PENDING)
            ->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)->exists();
        $cvFile->forceFill(['review_status' => $pending ? CVFile::REVIEW_STATUS_DECISIONS_PENDING : CVFile::REVIEW_STATUS_READY_TO_APPLY])->save();
    }

    private function createSuggestion(User $user, JobSeekerProfile $profile, CVFile $cvFile, array $attributes): ProfileChangeSuggestion
    {
        return ProfileChangeSuggestion::query()->create(array_merge([
            'user_id' => $user->id, 'cv_file_id' => $cvFile->id, 'job_seeker_profile_id' => $profile->id,
            'status' => ProfileChangeSuggestion::STATUS_PENDING, 'source' => ProfileChangeSuggestion::SOURCE_CV_PARSED,
        ], $attributes));
    }

    private function experiencePayload(array $value): array
    {
        $payload = collect($value)->only(['title', 'company_name', 'location', 'start_date', 'end_date', 'is_current', 'description'])->map(fn (mixed $item): mixed => $this->scalar($item))->all();

        return array_filter($payload, fn (mixed $item): bool => $item !== null);
    }

    private function educationPayload(array $value): array
    {
        $payload = collect($value)->only(['institution', 'degree', 'field_of_study', 'start_date', 'end_date', 'description'])->map(fn (mixed $item): mixed => $this->scalar($item))->all();

        return array_filter($payload, fn (mixed $item): bool => $item !== null);
    }

    private function experienceApplyPayload(array $value): array
    {
        return collect($value)->only(['title', 'company_name', 'location', 'start_date', 'end_date', 'is_current', 'description'])
            ->map(fn (mixed $item): mixed => $this->scalar($item))->all();
    }

    private function educationApplyPayload(array $value): array
    {
        return collect($value)->only(['institution', 'degree', 'field_of_study', 'start_date', 'end_date', 'description'])
            ->map(fn (mixed $item): mixed => $this->scalar($item))->all();
    }

    private function modelSnapshot(Experience|Education $model): array
    {
        return collect($model->getAttributes())->map(fn (mixed $value, string $field): mixed => in_array($field, ['start_date', 'end_date'], true) ? $this->scalar($model->{$field}) : $value)->all();
    }

    private function onlyEmpty(Experience|Education $model, array $incoming): array
    {
        return collect($incoming)->filter(fn (mixed $value, string $field): bool => $this->emptyValue($model->{$field}) && ! $this->emptyValue($value))->all();
    }

    private function sourcePayload(ProfileChangeSuggestion $suggestion, string $type): array
    {
        return ['source_type' => $type, 'source_cv_file_id' => $suggestion->cv_file_id, 'user_verified_at' => now()];
    }

    private function applicationResult(User $user, CVFile $cvFile, bool $already): array
    {
        $suggestions = $cvFile->profileChangeSuggestions()->get();

        return [
            'applied_count' => $suggestions->where('status', ProfileChangeSuggestion::STATUS_APPLIED)->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)->count(),
            'rejected_count' => $suggestions->where('status', ProfileChangeSuggestion::STATUS_REJECTED)->count(),
            'ignored_count' => $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_IGNORE)->count(),
            'already_applied' => $already,
            'profile' => $this->jobSeekerProfile($user)->load(['user', 'experiences', 'education', 'skills']),
        ];
    }

    private function experienceBase(array $value): string
    {
        return $this->normalize(($value['title'] ?? '').'|'.($value['company_name'] ?? ''));
    }

    private function educationBase(array $value): string
    {
        return $this->normalize(($value['institution'] ?? '').'|'.($value['degree'] ?? ''));
    }

    private function experienceIdentity(array $value): string
    {
        return $this->experienceBase($value).'|'.($this->period($value['start_date'] ?? null) ?? '');
    }

    private function educationIdentity(array $value): string
    {
        return $this->educationBase($value).'|'.($this->educationPeriod($value) ?? '');
    }

    private function educationPeriod(array $value): ?string
    {
        return $this->period($value['start_date'] ?? null) ?? $this->period($value['end_date'] ?? null);
    }

    private function period(mixed $value): ?string
    {
        $value = $this->scalar($value);

        return is_string($value) && preg_match('/^(\d{4})/', $value, $m) ? $m[1] : null;
    }

    private function yearDate(mixed $year): ?string
    {
        return is_int($year) && $year >= 1900 && $year <= 2200 ? $year.'-01-01' : null;
    }

    private function scalar(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_string($value) ? $this->cleanString($value) : $value);
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparable($left) === $this->normalizeComparable($right);
    }

    private function normalizeComparable(mixed $value): mixed
    {
        $value = $this->scalar($value);
        if (is_bool($value)) {
            return (int) $value;
        }

        return is_string($value) ? $this->normalize($value) : $value;
    }

    private function normalize(mixed $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^\pL\pN]+/u', ' ')->squish()->toString();
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boundedConfidence(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : $fallback;
    }

    private function responsibilitiesDescription(mixed $items): ?string
    {
        if (! is_array($items)) {
            return null;
        }
        $lines = collect($items)->filter(fn (mixed $line): bool => is_string($line))->map(fn (string $line): string => trim($line))->filter()->map(fn (string $line): string => '- '.$line)->all();

        return $lines === [] ? null : implode(PHP_EOL, $lines);
    }

    private function auditDecision(User $user, ProfileChangeSuggestion $suggestion, string $decision): void
    {
        $this->auditLogService->record('cv.suggestion.'.$decision, $user, ProfileChangeSuggestion::class, $suggestion->id, null, null, [
            'cv_file_id' => $suggestion->cv_file_id, 'actor_id' => $user->id, 'entity_type' => $suggestion->entity_type, 'decision' => $decision,
        ]);
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

    private function jobSeekerProfile(User $user): JobSeekerProfile
    {
        return $user->jobSeekerProfile()->firstOrFail();
    }

    private function assertSuggestionMutable(ProfileChangeSuggestion $suggestion): void
    {
        if ($suggestion->status === ProfileChangeSuggestion::STATUS_APPLIED) {
            throw new CVLifecycleException('Applied suggestions are immutable.', 'SUGGESTION_APPLIED_IMMUTABLE');
        }
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
        if ($cvFile->review_status === CVFile::REVIEW_STATUS_APPLIED || $cvFile->confirmed_at !== null) {
            throw new CVLifecycleException('Applied CV reviews are immutable.', 'CV_REVIEW_APPLIED_IMMUTABLE');
        }
    }
}
