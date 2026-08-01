<?php

namespace App\Services\CV;

use App\Exceptions\CVLifecycleException;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CVFinalizationService
{
    public function __construct(
        private readonly CVReviewDraftValidator $draftValidator,
        private readonly CVReviewDraftService $reviewDraftService,
        private readonly CVFinalDraftService $finalDraftService,
        private readonly CVApprovedProfileApplier $profileApplier,
        private readonly CVProfileSnapshotService $snapshotService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{profile:JobSeekerProfile,suggestions:Collection<int,ProfileChangeSuggestion>,cv:CVFile,applied_changes:array<string,int>,already_confirmed:bool}
     */
    public function confirm(User $user, CVFile $cvFile): array
    {
        abort_unless($cvFile->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $cvFile): array {
            $profile = JobSeekerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedCV = CVFile::query()->lockForUpdate()->findOrFail($cvFile->id);
            abort_unless($lockedCV->user_id === $user->id, 404);

            if ($lockedCV->confirmed_at !== null || $lockedCV->review_status === CVFile::REVIEW_STATUS_APPLIED) {
                if ($profile->primary_cv_file_id === $lockedCV->id) {
                    return $this->result($profile, $lockedCV, true);
                }

                throw new CVLifecycleException(
                    __('domain_errors.CV_ALREADY_CONFIRMED'),
                    'CV_ALREADY_CONFIRMED',
                );
            }
            if ($lockedCV->cancelled_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_ALREADY_CANCELLED'), 'CV_ALREADY_CANCELLED');
            }
            if ($lockedCV->archived_at !== null) {
                throw new CVLifecycleException(__('domain_errors.CV_ARCHIVED_READ_ONLY'), 'CV_ARCHIVED_READ_ONLY');
            }
            if (! $lockedCV->isActivePendingWorkflow()) {
                throw new CVLifecycleException(__('domain_errors.CV_NOT_PENDING'), 'CV_NOT_PENDING');
            }
            if ($lockedCV->status !== 'parsed') {
                throw new CVLifecycleException(__('domain_errors.CV_REVIEW_NOT_READY'), 'CV_REVIEW_NOT_READY');
            }
            if ($lockedCV->review_status !== CVFile::REVIEW_STATUS_READY_TO_APPLY) {
                throw new CVLifecycleException(
                    __('domain_errors.CV_REVIEW_HAS_UNRESOLVED_CHANGES'),
                    'CV_REVIEW_HAS_UNRESOLVED_CHANGES',
                );
            }

            $suggestions = ProfileChangeSuggestion::query()
                ->where('cv_file_id', $lockedCV->id)
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($suggestions->contains(fn (ProfileChangeSuggestion $suggestion): bool => $suggestion->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE
                && $suggestion->status === ProfileChangeSuggestion::STATUS_PENDING)) {
                throw new CVLifecycleException(
                    __('domain_errors.CV_REVIEW_HAS_UNRESOLVED_CHANGES'),
                    'CV_REVIEW_HAS_UNRESOLVED_CHANGES',
                );
            }

            if (is_string($lockedCV->comparison_profile_hash)) {
                $currentHash = $this->snapshotService->currentHash($profile);
                if (! hash_equals($lockedCV->comparison_profile_hash, $currentHash)) {
                    throw new CVLifecycleException(
                        __('domain_errors.CV_PROFILE_CHANGED_SINCE_COMPARISON'),
                        'CV_PROFILE_CHANGED_SINCE_COMPARISON',
                        errors: ['profile' => [__('cv.rebuild_comparison')]],
                    );
                }
            }

            $parsingResult = CVParsingResult::query()
                ->where('cv_file_id', $lockedCV->id)
                ->lockForUpdate()
                ->first();
            $draft = $parsingResult?->reviewed_json;
            if (! is_array($draft)
                && $lockedCV->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
                && $parsingResult instanceof CVParsingResult) {
                $draft = $this->finalDraftService->build($profile, $suggestions);
                $parsingResult->forceFill([
                    'reviewed_json' => $draft,
                    'system_generated_review_json' => $draft,
                    'reviewed_at' => now(),
                ])->save();
            }
            if (! is_array($draft)) {
                throw new CVLifecycleException(
                    __('domain_errors.CV_FINAL_DRAFT_INVALID'),
                    'CV_FINAL_DRAFT_INVALID',
                    422,
                );
            }
            $this->draftValidator->validate($draft);
            $draft = $this->reviewDraftService->normalize($draft);

            $changes = $this->profileApplier->apply(
                $profile,
                $lockedCV,
                $draft,
                $lockedCV->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC,
                $parsingResult->system_generated_review_json,
            );
            $changes['merged'] = $suggestions
                ->where('suggestion_type', ProfileChangeSuggestion::TYPE_MERGE)
                ->where('status', ProfileChangeSuggestion::STATUS_ACCEPTED)
                ->count();

            $now = now();
            ProfileChangeSuggestion::query()
                ->where('cv_file_id', $lockedCV->id)
                ->where(function ($query): void {
                    $query->where('status', ProfileChangeSuggestion::STATUS_ACCEPTED)
                        ->orWhere('suggestion_type', ProfileChangeSuggestion::TYPE_IGNORE);
                })
                ->update(['status' => ProfileChangeSuggestion::STATUS_APPLIED, 'applied_at' => $now]);
            $parsingResult->forceFill(['final_approved_json' => $draft])->save();
            $previousPrimary = $profile->primary_cv_file_id;
            $lockedCV->forceFill([
                'review_status' => CVFile::REVIEW_STATUS_APPLIED,
                'confirmed_at' => $now,
            ])->save();
            $profile->forceFill(['primary_cv_file_id' => $lockedCV->id])->save();

            $metadata = [
                'cv_file_id' => $lockedCV->id,
                'actor_id' => $user->id,
                'operation' => $lockedCV->review_mode,
                'previous_primary_cv_file_id' => $previousPrimary,
                'new_primary_cv_file_id' => $lockedCV->id,
                'applied_changes' => $changes,
            ];
            $this->auditLogService->record('cv.confirmed', $user, CVFile::class, $lockedCV->id, null, null, $metadata);
            $this->auditLogService->record('profile.updated_from_cv', $user, JobSeekerProfile::class, $profile->id, null, null, $metadata);
            if ($previousPrimary !== $lockedCV->id) {
                $this->auditLogService->record('cv.current_changed', $user, CVFile::class, $lockedCV->id, null, null, $metadata);
            }

            return $this->result($profile, $lockedCV, false, $changes);
        });
    }

    /**
     * @param  array<string, int>|null  $changes
     * @return array{profile:JobSeekerProfile,suggestions:Collection<int,ProfileChangeSuggestion>,cv:CVFile,applied_changes:array<string,int>,already_confirmed:bool}
     */
    private function result(JobSeekerProfile $profile, CVFile $cvFile, bool $already, ?array $changes = null): array
    {
        return [
            'profile' => $profile->refresh()->load(['user', 'city', 'experiences', 'education', 'skills', 'primaryCVFile']),
            'suggestions' => $cvFile->profileChangeSuggestions()->with('cvFile')->orderBy('id')->get(),
            'cv' => $cvFile->refresh(),
            'applied_changes' => $changes ?? ['added' => 0, 'updated' => 0, 'merged' => 0, 'removed' => 0, 'unchanged' => 0],
            'already_confirmed' => $already,
        ];
    }
}
