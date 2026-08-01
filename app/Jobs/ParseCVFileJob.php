<?php

namespace App\Jobs;

use App\Enums\CandidateCVOperation;
use App\Exceptions\CVParserException;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Services\AuditLogService;
use App\Services\CV\CandidateCVOperationResolver;
use App\Services\CV\CVProfileSnapshotService;
use App\Services\CV\CVReviewDraftService;
use App\Services\CVParsingService;
use App\Services\PrivateFileStorageService;
use App\Services\ProfileSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ParseCVFileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CVFile $cvFile,
    ) {}

    public function handle(
        CVParsingService $cvParsingService,
        ?AuditLogService $auditLogService = null,
        ?PrivateFileStorageService $privateStorage = null,
        ?CandidateCVOperationResolver $operationResolver = null,
        ?CVReviewDraftService $reviewDraftService = null,
        ?CVProfileSnapshotService $profileSnapshotService = null,
        ?ProfileSyncService $profileSyncService = null,
    ): void {
        $auditLogService ??= app(AuditLogService::class);
        $privateStorage ??= app(PrivateFileStorageService::class);
        $operationResolver ??= app(CandidateCVOperationResolver::class);
        $reviewDraftService ??= app(CVReviewDraftService::class);
        $profileSnapshotService ??= app(CVProfileSnapshotService::class);
        $profileSyncService ??= app(ProfileSyncService::class);
        $this->cvFile->refresh();
        if (! $this->cvFile->isActivePendingWorkflow()) {
            return;
        }

        $this->cvFile->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->save();
        $auditLogService->record('cv.parsing_started', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
            'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
            'parsing_status' => 'processing', 'actor_id' => $this->cvFile->user_id,
        ]);

        $temporaryPath = null;
        try {
            $stream = $privateStorage->readStream($this->cvFile->disk, $this->cvFile->stored_path);
            $basePath = tempnam(sys_get_temp_dir(), 'private-cv-');
            if ($basePath === false) {
                fclose($stream);
                throw new \RuntimeException('A temporary parsing file could not be created.');
            }
            $temporaryPath = $basePath.'.'.strtolower($this->cvFile->extension);
            if (! rename($basePath, $temporaryPath)) {
                fclose($stream);
                @unlink($basePath);
                throw new \RuntimeException('A temporary parsing file could not be prepared.');
            }
            $target = fopen($temporaryPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException('A temporary parsing file could not be opened.');
            }
            try {
                if (stream_copy_to_stream($stream, $target) === false) {
                    throw new \RuntimeException('The CV could not be copied for parsing.');
                }
            } finally {
                fclose($stream);
                fclose($target);
            }

            $path = $temporaryPath;
            $rawText = $cvParsingService->extractText($path);
            $parsedJson = $cvParsingService->parseText($rawText);

            $saved = DB::transaction(function () use ($rawText, $parsedJson, $operationResolver, $reviewDraftService, $profileSnapshotService): bool {
                $cvFile = CVFile::query()->lockForUpdate()->findOrFail($this->cvFile->id);
                if (! $cvFile->isActivePendingWorkflow()) {
                    return false;
                }
                $result = CVParsingResult::query()->firstOrCreate(
                    ['cv_file_id' => $cvFile->id],
                    ['raw_text' => $rawText, 'parsed_json' => $parsedJson],
                );

                $state = ['status' => 'parsed', 'error_message' => null];
                $profile = $cvFile->user->jobSeekerProfile()->lockForUpdate()->first();
                $mode = $cvFile->review_mode;
                if ($profile instanceof JobSeekerProfile) {
                    $profileSnapshot = $profileSnapshotService->snapshot($profile);
                    $result->forceFill(['comparison_base_json' => $profileSnapshot])->save();
                    $state['comparison_profile_updated_at'] = $profile->updated_at;
                    $state['comparison_profile_hash'] = $profileSnapshotService->hash($profileSnapshot);
                }
                if ($mode === null && $profile instanceof JobSeekerProfile) {
                    $operation = $operationResolver->resolve($cvFile->user, $profile);
                    $mode = $operation === CandidateCVOperation::INITIAL_UPLOAD
                        ? CVFile::REVIEW_MODE_INITIAL_IMPORT
                        : CVFile::REVIEW_MODE_PROFILE_SYNC;
                    $state['review_mode'] = $mode;
                } elseif ($mode === null) {
                    // Legacy CV rows may predate the mandatory candidate profile invariant.
                    // Parsing stays non-destructive and exposes an initial draft until the
                    // account is repaired through the normal profile lifecycle.
                    $mode = CVFile::REVIEW_MODE_INITIAL_IMPORT;
                    $state['review_mode'] = $mode;
                }
                $initial = $mode === CVFile::REVIEW_MODE_INITIAL_IMPORT;
                $state['review_status'] = $initial
                    ? CVFile::REVIEW_STATUS_DRAFT
                    : CVFile::REVIEW_STATUS_COMPARISON_PENDING;
                if ($initial) {
                    $draft = $reviewDraftService->build($result->parsed_json);
                    $result->forceFill([
                        'reviewed_json' => $draft,
                        'system_generated_review_json' => $draft,
                        'reviewed_at' => now(),
                    ])->save();
                }

                $cvFile->forceFill($state)->save();
                $this->cvFile = $cvFile;

                return true;
            });
            if (! $saved) {
                return;
            }
            if ($this->cvFile->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
                && $this->cvFile->user->jobSeekerProfile()->exists()) {
                $profileSyncService->generateSuggestionsFromParsedCV($this->cvFile->user, $this->cvFile);
                $this->cvFile->refresh();
            }
            $auditLogService->record('cv.parsing_completed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'parsed', 'actor_id' => $this->cvFile->user_id,
                'parser_driver' => $parsedJson['_meta']['parser_driver'] ?? 'rules',
                'model' => $parsedJson['_meta']['model'] ?? null,
                'fallback_used' => $parsedJson['_meta']['fallback_used'] ?? false,
                'structured_output_mode' => $parsedJson['_meta']['structured_output_mode'] ?? null,
                'structured_output_fallback_reason' => $parsedJson['_meta']['structured_output_fallback_reason'] ?? null,
                'schema_version' => $parsedJson['_meta']['schema_version'] ?? '1.0',
                'normalization' => $parsedJson['_meta']['normalization'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->cvFile->refresh();
            if (! $this->cvFile->isActivePendingWorkflow()) {
                return;
            }
            $this->cvFile->forceFill([
                'status' => 'failed',
                'error_message' => $exception instanceof CVParserException
                    ? $exception->reasonCode
                    : 'CV_PARSING_FAILED',
            ])->save();
            $auditLogService->record('cv.parsing_failed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'failed', 'actor_id' => $this->cvFile->user_id,
            ]);

            throw $exception;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
