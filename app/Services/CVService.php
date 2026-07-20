<?php

namespace App\Services;

use App\Exceptions\CVLifecycleException;
use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\CV\CVReviewDraftService;
use App\Services\CV\ProfileDataStateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CVService
{
    public function __construct(
        private readonly ProfileSyncService $profileSyncService,
        private readonly AuditLogService $auditLogService,
        private readonly PrivateFileStorageService $privateStorage,
        private readonly ProfileDataStateService $profileDataStateService,
        private readonly CVReviewDraftService $reviewDraftService,
    ) {}

    public function upload(User $user, UploadedFile $file, ?string $versionLabel = null, bool $makePrimary = false): CVFile
    {
        $stored = $this->privateStorage->storeUploadedFile($file, 'cv-files');

        try {
            $cvFile = DB::transaction(function () use ($user, $file, $versionLabel, $makePrimary, $stored): CVFile {
                $profile = JobSeekerProfile::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $cvFile = CVFile::query()->create([
                    'user_id' => $user->id,
                    'original_name' => basename($file->getClientOriginalName()),
                    'version_label' => $this->cleanLabel($versionLabel),
                    'stored_path' => $stored->path,
                    'disk' => $stored->disk,
                    'mime_type' => $stored->mimeType,
                    'extension' => $stored->extension,
                    'size_bytes' => $stored->sizeBytes,
                    'status' => 'uploaded',
                ]);

                $previousPrimary = $profile->primary_cv_file_id;
                if ($previousPrimary === null || $makePrimary) {
                    $profile->forceFill(['primary_cv_file_id' => $cvFile->id])->save();
                }

                $this->auditLogService->record('cv.uploaded', $user, CVFile::class, $cvFile->id, null, null, [
                    'cv_file_id' => $cvFile->id,
                    'user_id' => $user->id,
                    'parsing_status' => 'uploaded',
                    'actor_id' => $user->id,
                ]);

                if ($profile->primary_cv_file_id !== $previousPrimary) {
                    $this->recordPrimaryChange($user, $cvFile, $previousPrimary, $profile->primary_cv_file_id);
                }

                return $cvFile;
            });
        } catch (Throwable $exception) {
            try {
                $this->privateStorage->delete($stored->disk, $stored->path);
            } catch (Throwable $cleanupException) {
                $this->privateStorage->logCleanupFailure('cv_upload_compensation', $stored->disk, $stored->path, $cleanupException, CVFile::class);
            }
            throw $exception;
        }

        ParseCVFileJob::dispatch($cvFile);

        return $cvFile->refresh();
    }

    /**
     * @return LengthAwarePaginator<int, CVFile>
     */
    public function list(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->cvFiles()
            ->when(! request()->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->when(request()->filled('status'), fn ($query) => $query->where('status', request()->string('status')->toString()))
            ->latest()
            ->paginate($perPage);
    }

    public function get(User $user, CVFile $cvFile): CVFile
    {
        return $this->ownedCVFile($user, $cvFile)->load('parsingResult');
    }

    public function getParsedResult(User $user, CVFile $cvFile): CVParsingResult
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);
        $result = $cvFile->parsingResult;

        abort_unless($result instanceof CVParsingResult, 404);

        return $result;
    }

    public function getReview(User $user, CVFile $cvFile): CVFile
    {
        return $this->ownedCVFile($user, $cvFile)->load('parsingResult');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function updateReviewDraft(User $user, CVFile $cvFile, array $draft): CVFile
    {
        $normalized = $this->reviewDraftService->normalize($draft);

        return DB::transaction(function () use ($user, $cvFile, $normalized): CVFile {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->ownedCVFile($user, $lockedCV);
            $this->assertMutable($lockedCV);
            if ($lockedCV->review_mode !== CVFile::REVIEW_MODE_INITIAL_IMPORT
                || $lockedCV->review_status !== CVFile::REVIEW_STATUS_DRAFT
                || $lockedCV->confirmed_at !== null) {
                throw new CVLifecycleException('This CV review draft cannot be edited.', 'CV_REVIEW_DRAFT_NOT_EDITABLE');
            }

            $result = CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->lockForUpdate()->first();
            if (! $result instanceof CVParsingResult) {
                throw new CVLifecycleException('This CV review draft cannot be edited.', 'CV_REVIEW_DRAFT_NOT_EDITABLE');
            }
            $result->forceFill(['reviewed_json' => $normalized, 'reviewed_at' => now()])->save();
            $this->auditLogService->record('cv.review_draft_updated', $user, CVFile::class, $lockedCV->id, null, null, [
                'cv_file_id' => $lockedCV->id,
                'actor_id' => $user->id,
                'review_mode' => $lockedCV->review_mode,
                'review_status' => $lockedCV->review_status,
                'experience_count' => count($normalized['experience']),
                'education_count' => count($normalized['education']),
                'skill_count' => count($normalized['skills']),
            ]);

            return $lockedCV->refresh()->load('parsingResult');
        });
    }

    /**
     * @return array{profile: JobSeekerProfile, suggestions: Collection<int, ProfileChangeSuggestion>}
     */
    public function confirm(User $user, CVFile $cvFile): array
    {
        $cvFile = $this->ownedCVFile($user, $cvFile)->load('parsingResult');
        $this->assertMutable($cvFile);

        if (! $cvFile->parsingResult instanceof CVParsingResult) {
            abort(404);
        }

        if ($cvFile->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT) {
            return $this->confirmInitialImport($user, $cvFile);
        }

        if ($cvFile->confirmed_at !== null || $cvFile->review_status === CVFile::REVIEW_STATUS_APPLIED) {
            throw ValidationException::withMessages(['cv' => ['This CV has already been confirmed.']]);
        }

        return DB::transaction(function () use ($user, $cvFile): array {
            $profile = $user->jobSeekerProfile()->firstOrFail();
            $suggestions = $this->profileSyncService->generateSuggestionsFromParsedCV($user, $cvFile);

            return [
                'profile' => $profile->load(['user', 'experiences', 'education', 'skills']),
                'suggestions' => $suggestions,
            ];
        });
    }

    /**
     * @return array{profile: JobSeekerProfile, suggestions: Collection<int, ProfileChangeSuggestion>}
     */
    private function confirmInitialImport(User $user, CVFile $cvFile): array
    {
        return DB::transaction(function () use ($user, $cvFile): array {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $lockedCV);
            $this->assertMutable($lockedCV);
            if ($lockedCV->review_mode !== CVFile::REVIEW_MODE_INITIAL_IMPORT
                || $lockedCV->review_status !== CVFile::REVIEW_STATUS_DRAFT
                || $lockedCV->confirmed_at !== null) {
                throw new CVLifecycleException('This initial CV review cannot be confirmed.', 'CV_REVIEW_NOT_CONFIRMABLE');
            }

            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($this->profileDataStateService->hasMeaningfulData($profile)) {
                throw new CVLifecycleException('The profile changed after this review was created.', 'CV_REVIEW_MODE_STALE');
            }

            $result = CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->lockForUpdate()->firstOrFail();
            $draft = $result->reviewed_json;
            if (! is_array($draft)) {
                throw new CVLifecycleException('The CV review draft is unavailable.', 'CV_REVIEW_DRAFT_INVALID', 422);
            }
            $this->validateStoredDraft($draft);
            $this->reviewDraftService->apply($profile, $lockedCV, $draft);

            $lockedCV->forceFill(['review_status' => CVFile::REVIEW_STATUS_APPLIED, 'confirmed_at' => now()])->save();
            $this->auditLogService->record('cv.initial_import_applied', $user, CVFile::class, $lockedCV->id, null, null, [
                'cv_file_id' => $lockedCV->id,
                'actor_id' => $user->id,
                'review_mode' => $lockedCV->review_mode,
                'review_status' => CVFile::REVIEW_STATUS_APPLIED,
                'experience_count' => count($draft['experience']),
                'education_count' => count($draft['education']),
                'skill_count' => count($draft['skills']),
            ]);

            return [
                'profile' => $profile->refresh()->load(['user', 'experiences', 'education', 'skills']),
                'suggestions' => new Collection,
            ];
        });
    }

    /** @param array<string, mixed> $draft */
    private function validateStoredDraft(array $draft): void
    {
        $validator = Validator::make($draft, [
            'profile' => ['required', 'array:phone,summary,location'],
            'profile.phone' => ['present', 'nullable', 'string', 'max:50'],
            'profile.summary' => ['present', 'nullable', 'string', 'max:5000'],
            'profile.location' => ['present', 'nullable', 'string', 'max:255'],
            'experience' => ['present', 'array', 'max:100'],
            'experience.*' => ['array:title,company_name,location,start_date,end_date,is_current,description'],
            'experience.*.title' => ['required', 'string', 'max:255'],
            'experience.*.company_name' => ['required', 'string', 'max:255'],
            'experience.*.location' => ['present', 'nullable', 'string', 'max:255'],
            'experience.*.start_date' => ['present', 'nullable', 'date'],
            'experience.*.end_date' => ['present', 'nullable', 'date'],
            'experience.*.is_current' => ['required', 'boolean'],
            'experience.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'education' => ['present', 'array', 'max:100'],
            'education.*' => ['array:institution,degree,field_of_study,start_date,end_date,description'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.degree' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.field_of_study' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.start_date' => ['present', 'nullable', 'date'],
            'education.*.end_date' => ['present', 'nullable', 'date'],
            'education.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'skills' => ['present', 'array', 'max:100'],
            'skills.*' => ['required', 'string', 'max:150'],
        ]);
        $validator->after(function ($validator) use ($draft): void {
            $unexpected = array_diff(array_keys($draft), ['profile', 'experience', 'education', 'skills']);
            if ($unexpected !== []) {
                $validator->errors()->add('payload', 'The payload contains unexpected fields.');
            }

            foreach (['experience', 'education'] as $section) {
                foreach ($draft[$section] ?? [] as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (! empty($item['start_date']) && ! empty($item['end_date']) && strtotime($item['end_date']) < strtotime($item['start_date'])) {
                        $validator->errors()->add("{$section}.{$index}.end_date", 'The end date must be after or equal to the start date.');
                    }
                    if ($section === 'experience' && ($item['is_current'] ?? false) && ($item['end_date'] ?? null) !== null) {
                        $validator->errors()->add("{$section}.{$index}.end_date", 'The end date must be null for a current experience.');
                    }
                }
            }
        });
        $validator->validate();
    }

    public function updateLabel(User $user, CVFile $cvFile, ?string $versionLabel): CVFile
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);
        $before = $cvFile->version_label;
        $after = $this->cleanLabel($versionLabel);

        if ($before !== $after) {
            $cvFile->forceFill(['version_label' => $after])->save();
            $this->auditLogService->record('cv.version_label_updated', $user, CVFile::class, $cvFile->id, null, null, [
                'cv_file_id' => $cvFile->id,
                'user_id' => $user->id,
                'version_label_changed' => true,
                'actor_id' => $user->id,
            ]);
        }

        return $cvFile->refresh();
    }

    public function makePrimary(User $user, CVFile $cvFile): CVFile
    {
        return DB::transaction(function () use ($user, $cvFile): CVFile {
            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $target = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $target);
            $this->assertAvailableAndUsable($target);

            $previous = $profile->primary_cv_file_id;
            if ($previous !== $target->id) {
                $profile->forceFill(['primary_cv_file_id' => $target->id])->save();
                $this->recordPrimaryChange($user, $target, $previous, $target->id);
            }

            return $target->refresh();
        });
    }

    public function archive(User $user, CVFile $cvFile, ?int $replacementId = null): CVFile
    {
        return DB::transaction(function () use ($user, $cvFile, $replacementId): CVFile {
            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $target = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $target);
            if ($target->archived_at !== null) {
                throw new CVLifecycleException('This CV is already archived.', 'CV_ALREADY_ARCHIVED');
            }

            $previousPrimary = $profile->primary_cv_file_id;
            if ($previousPrimary === $target->id) {
                $otherUsableExists = CVFile::query()
                    ->where('user_id', $user->id)->whereKeyNot($target->id)->whereNull('archived_at')
                    ->get()->contains(fn (CVFile $candidate): bool => $candidate->isUsableForApplication());

                if ($otherUsableExists && $replacementId === null) {
                    throw new CVLifecycleException('Select an active replacement before archiving the primary CV.', 'CV_PRIMARY_REPLACEMENT_REQUIRED');
                }

                if ($replacementId !== null) {
                    $replacement = CVFile::query()->lockForUpdate()->find($replacementId);
                    if (! $replacement instanceof CVFile) {
                        throw new CVLifecycleException('The replacement CV could not be found.', 'CV_NOT_USABLE', 422);
                    }
                    $this->assertOwned($user, $replacement);
                    $this->assertAvailableAndUsable($replacement);
                    if ($replacement->id === $target->id) {
                        throw new CVLifecycleException('The replacement must be a different active CV.', 'CV_NOT_USABLE', 422);
                    }
                    $profile->forceFill(['primary_cv_file_id' => $replacement->id])->save();
                } else {
                    $profile->forceFill(['primary_cv_file_id' => null])->save();
                }
            }

            $target->forceFill(['archived_at' => now()])->save();
            if ($profile->primary_cv_file_id !== $previousPrimary) {
                $this->recordPrimaryChange($user, $target, $previousPrimary, $profile->primary_cv_file_id);
            }
            $this->auditLogService->record('cv.archived', $user, CVFile::class, $target->id, null, null, [
                'cv_file_id' => $target->id, 'user_id' => $user->id, 'actor_id' => $user->id,
                'new_primary_cv_file_id' => $profile->primary_cv_file_id,
            ]);

            return $target->refresh();
        });
    }

    public function restore(User $user, CVFile $cvFile): CVFile
    {
        return DB::transaction(function () use ($user, $cvFile): CVFile {
            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $target = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $target);
            if ($target->archived_at === null) {
                throw new CVLifecycleException('This CV is not archived.', 'CV_NOT_ARCHIVED');
            }

            $target->forceFill(['archived_at' => null])->save();
            if ($profile->primary_cv_file_id === null) {
                $profile->forceFill(['primary_cv_file_id' => $target->id])->save();
                $this->recordPrimaryChange($user, $target, null, $target->id);
            }
            $this->auditLogService->record('cv.restored', $user, CVFile::class, $target->id, null, null, [
                'cv_file_id' => $target->id, 'user_id' => $user->id, 'actor_id' => $user->id,
            ]);

            return $target->refresh();
        });
    }

    public function downloadable(User $user, CVFile $cvFile): CVFile
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);
        $this->assertFileExists($cvFile);

        return $cvFile;
    }

    public function assertMutable(CVFile $cvFile): void
    {
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException('Archived CV data is read-only.', 'CV_ARCHIVED_READ_ONLY');
        }
    }

    private function assertOwned(User $user, CVFile $cvFile): void
    {
        if ($cvFile->user_id !== $user->id) {
            throw new CVLifecycleException('The CV does not belong to the authenticated user.', 'CV_NOT_OWNED', 403);
        }
    }

    private function assertAvailableAndUsable(CVFile $cvFile): void
    {
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException('Archived CVs cannot be used for this operation.', 'CV_ARCHIVED');
        }
        $this->assertFileExists($cvFile);
        if (! $cvFile->isUsableForApplication()) {
            throw new CVLifecycleException('This CV is not usable.', 'CV_NOT_USABLE');
        }
    }

    private function assertFileExists(CVFile $cvFile): void
    {
        if (! $this->privateStorage->exists($cvFile->disk, $cvFile->stored_path)) {
            throw new CVLifecycleException('The CV file is unavailable.', 'CV_FILE_UNAVAILABLE', 404);
        }
    }

    private function cleanLabel(?string $label): ?string
    {
        $label = $label === null ? null : trim($label);

        return $label === '' ? null : $label;
    }

    private function recordPrimaryChange(User $user, CVFile $cvFile, ?int $previous, ?int $new): void
    {
        $this->auditLogService->record('cv.primary_changed', $user, CVFile::class, $cvFile->id, null, null, [
            'cv_file_id' => $cvFile->id,
            'user_id' => $user->id,
            'previous_primary_cv_file_id' => $previous,
            'new_primary_cv_file_id' => $new,
            'actor_id' => $user->id,
        ]);
    }

    private function ownedCVFile(User $user, CVFile $cvFile): CVFile
    {
        abort_unless($cvFile->user_id === $user->id, 404);

        return $cvFile;
    }
}
