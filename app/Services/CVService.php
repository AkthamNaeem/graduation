<?php

namespace App\Services;

use App\Enums\CandidateCVOperation;
use App\Exceptions\CVLifecycleException;
use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\CV\CandidateCVOperationResolver;
use App\Services\CV\CVFinalizationService;
use App\Services\CV\CVReviewDraftService;
use App\Services\CV\CVReviewDraftValidator;
use App\Services\CV\CVStageResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class CVService
{
    public function __construct(
        private readonly ProfileSyncService $profileSyncService,
        private readonly AuditLogService $auditLogService,
        private readonly PrivateFileStorageService $privateStorage,
        private readonly CVReviewDraftService $reviewDraftService,
        private readonly CVReviewDraftValidator $reviewDraftValidator,
        private readonly CandidateCVOperationResolver $operationResolver,
        private readonly CVStageResolver $stageResolver,
        private readonly CVFinalizationService $finalizationService,
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

                $pending = CVFile::query()
                    ->where('user_id', $user->id)
                    ->whereNull('confirmed_at')
                    ->whereNull('archived_at')
                    ->whereNull('cancelled_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if ($pending instanceof CVFile) {
                    $stage = $this->stageResolver->resolve($pending);
                    throw new CVLifecycleException(
                        __('domain_errors.CV_PENDING_UPDATE_EXISTS'),
                        'CV_PENDING_UPDATE_EXISTS',
                        errors: ['cv' => [__('cv.pending_update_exists_help')]],
                        data: [
                            'pending_cv_id' => $pending->id,
                            'stage' => [
                                'key' => $stage->value,
                                'label' => __("profile.cv_contract.stages.{$stage->value}"),
                            ],
                        ],
                    );
                }

                $operation = $this->operationResolver->resolve($user, $profile);

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
                    'review_mode' => $operation === CandidateCVOperation::INITIAL_UPLOAD
                        ? CVFile::REVIEW_MODE_INITIAL_IMPORT
                        : CVFile::REVIEW_MODE_PROFILE_SYNC,
                ]);

                $this->auditLogService->record('cv.uploaded', $user, CVFile::class, $cvFile->id, null, null, [
                    'cv_file_id' => $cvFile->id,
                    'user_id' => $user->id,
                    'parsing_status' => 'uploaded',
                    'actor_id' => $user->id,
                    'operation' => $operation->value,
                    'make_primary_requested' => $makePrimary,
                ]);

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
            ->whereNull('cancelled_at')
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
        return $this->ownedCVFile($user, $cvFile)->load([
            'parsingResult',
            'profileChangeSuggestions' => fn ($query) => $query->where('user_id', $user->id)->orderBy('id'),
        ]);
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
            $editableInitialDraft = $lockedCV->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT
                && $lockedCV->review_status === CVFile::REVIEW_STATUS_DRAFT;
            $editableFinalDraft = $lockedCV->review_status === CVFile::REVIEW_STATUS_READY_TO_APPLY;
            if ((! $editableInitialDraft && ! $editableFinalDraft)
                || $lockedCV->confirmed_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_REVIEW_DRAFT_NOT_EDITABLE'), 'CV_REVIEW_DRAFT_NOT_EDITABLE');
            }

            $result = CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->lockForUpdate()->first();
            if (! $result instanceof CVParsingResult) {
                throw new CVLifecycleException(__('domain_errors.CV_REVIEW_DRAFT_NOT_EDITABLE'), 'CV_REVIEW_DRAFT_NOT_EDITABLE');
            }
            $this->reviewDraftValidator->validate($normalized);
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
        return $this->finalizationService->confirm($user, $this->ownedCVFile($user, $cvFile));
    }

    public function readyForConfirmation(User $user, CVFile $cvFile): CVFile
    {
        return DB::transaction(function () use ($user, $cvFile): CVFile {
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $lockedCV);
            $this->assertMutable($lockedCV);
            if ($lockedCV->review_mode !== CVFile::REVIEW_MODE_INITIAL_IMPORT
                || $lockedCV->review_status !== CVFile::REVIEW_STATUS_DRAFT
                || $lockedCV->status !== 'parsed') {
                throw new CVLifecycleException(__('domain_errors.CV_REVIEW_NOT_READY'), 'CV_REVIEW_NOT_READY');
            }
            $result = CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->lockForUpdate()->firstOrFail();
            if (! is_array($result->reviewed_json)) {
                throw new CVLifecycleException(__('domain_errors.CV_FINAL_DRAFT_INVALID'), 'CV_FINAL_DRAFT_INVALID', 422);
            }
            $this->reviewDraftValidator->validate($result->reviewed_json);
            $lockedCV->forceFill(['review_status' => CVFile::REVIEW_STATUS_READY_TO_APPLY])->save();
            $this->auditLogService->record('cv.ready_for_confirmation', $user, CVFile::class, $lockedCV->id, null, null, [
                'cv_file_id' => $lockedCV->id,
                'actor_id' => $user->id,
            ]);

            return $lockedCV->refresh()->load('parsingResult');
        });
    }

    /** @return array{cv:CVFile,already_cancelled:bool} */
    public function cancel(User $user, CVFile $cvFile): array
    {
        return DB::transaction(function () use ($user, $cvFile): array {
            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            $this->assertOwned($user, $lockedCV);
            if ($lockedCV->cancelled_at !== null) {
                return ['cv' => $lockedCV, 'already_cancelled' => true];
            }
            if ($lockedCV->confirmed_at !== null || $profile->primary_cv_file_id === $lockedCV->id) {
                throw new CVLifecycleException(
                    __('domain_errors.CV_CANNOT_CANCEL_CURRENT'),
                    'CV_CANNOT_CANCEL_CURRENT',
                );
            }
            if (! $lockedCV->isActivePendingWorkflow()) {
                throw new CVLifecycleException(__('domain_errors.CV_NOT_PENDING'), 'CV_NOT_PENDING');
            }

            $lockedCV->profileChangeSuggestions()
                ->where('status', '!=', ProfileChangeSuggestion::STATUS_APPLIED)
                ->update(['status' => ProfileChangeSuggestion::STATUS_REJECTED, 'decided_at' => now()]);
            CVParsingResult::query()->where('cv_file_id', $lockedCV->id)->update([
                'reviewed_json' => null,
                'comparison_base_json' => null,
                'system_generated_review_json' => null,
                'final_approved_json' => null,
                'reviewed_at' => null,
            ]);
            $lockedCV->forceFill([
                'cancelled_at' => now(),
                'review_status' => CVFile::REVIEW_STATUS_CANCELLED,
            ])->save();
            $this->auditLogService->record('cv.cancelled', $user, CVFile::class, $lockedCV->id, null, null, [
                'cv_file_id' => $lockedCV->id,
                'actor_id' => $user->id,
            ]);

            return ['cv' => $lockedCV->refresh(), 'already_cancelled' => false];
        });
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
            if (! $target->isConfirmedUsableForApplication()) {
                throw new CVLifecycleException(
                    __('domain_errors.CV_NOT_USABLE_FOR_APPLICATION'),
                    'CV_NOT_USABLE_FOR_APPLICATION',
                );
            }

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
            if ($target->confirmed_at === null || $target->cancelled_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_NOT_PENDING'), 'CV_NOT_PENDING');
            }
            if ($target->archived_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_ALREADY_ARCHIVED'), 'CV_ALREADY_ARCHIVED');
            }

            $previousPrimary = $profile->primary_cv_file_id;
            if ($previousPrimary === $target->id) {
                $otherUsableExists = CVFile::query()
                    ->where('user_id', $user->id)->whereKeyNot($target->id)->whereNull('archived_at')
                    ->get()->contains(fn (CVFile $candidate): bool => $candidate->isConfirmedUsableForApplication());

                if ($otherUsableExists && $replacementId === null) {
                    throw new CVLifecycleException(__('domain_errors.CV_PRIMARY_REPLACEMENT_REQUIRED'), 'CV_PRIMARY_REPLACEMENT_REQUIRED');
                }

                if ($replacementId !== null) {
                    $replacement = CVFile::query()->lockForUpdate()->find($replacementId);
                    if (! $replacement instanceof CVFile) {
                        throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE'), 'CV_NOT_USABLE', 422);
                    }
                    $this->assertOwned($user, $replacement);
                    $this->assertAvailableAndUsable($replacement);
                    if ($replacement->id === $target->id) {
                        throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE'), 'CV_NOT_USABLE', 422);
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
                throw new CVLifecycleException(__('domain_errors.CV_NOT_ARCHIVED'), 'CV_NOT_ARCHIVED');
            }
            if ($target->confirmed_at === null || $target->cancelled_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE'), 'CV_NOT_USABLE');
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
        if ($cvFile->cancelled_at !== null) {
            throw new CVLifecycleException(__('domain_errors.CV_ALREADY_CANCELLED'), 'CV_ALREADY_CANCELLED');
        }
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException(__('domain_errors.CV_ARCHIVED_READ_ONLY'), 'CV_ARCHIVED_READ_ONLY');
        }
    }

    private function assertOwned(User $user, CVFile $cvFile): void
    {
        if ($cvFile->user_id !== $user->id) {
            throw new CVLifecycleException(__('domain_errors.CV_NOT_OWNED'), 'CV_NOT_OWNED', 403);
        }
    }

    private function assertAvailableAndUsable(CVFile $cvFile): void
    {
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException(__('domain_errors.CV_ARCHIVED'), 'CV_ARCHIVED');
        }
        $this->assertFileExists($cvFile);
        if (! $cvFile->isUsableForApplication()) {
            throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE'), 'CV_NOT_USABLE');
        }
    }

    private function assertFileExists(CVFile $cvFile): void
    {
        if (! $this->privateStorage->exists($cvFile->disk, $cvFile->stored_path)) {
            throw new CVLifecycleException(__('domain_errors.CV_FILE_UNAVAILABLE'), 'CV_FILE_UNAVAILABLE', 404);
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
