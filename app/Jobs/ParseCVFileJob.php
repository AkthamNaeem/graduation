<?php

namespace App\Jobs;

use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Services\CVParsingService;
use App\Services\AuditLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ParseCVFileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CVFile $cvFile,
    ) {
    }

    public function handle(CVParsingService $cvParsingService, ?AuditLogService $auditLogService = null): void
    {
        $auditLogService ??= app(AuditLogService::class);
        $this->cvFile->refresh();
        if ($this->cvFile->archived_at !== null) {
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

        try {
            $path = Storage::disk($this->cvFile->disk)->path($this->cvFile->stored_path);
            $rawText = $cvParsingService->extractText($path);
            $parsedJson = $cvParsingService->parseText($rawText);

            CVParsingResult::query()->updateOrCreate(
                ['cv_file_id' => $this->cvFile->id],
                [
                    'raw_text' => $rawText,
                    'parsed_json' => $parsedJson,
                ],
            );

            $this->cvFile->forceFill([
                'status' => 'parsed',
                'error_message' => null,
            ])->save();
            $auditLogService->record('cv.parsing_completed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'parsed', 'actor_id' => $this->cvFile->user_id,
            ]);
        } catch (Throwable $exception) {
            $this->cvFile->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();
            $auditLogService->record('cv.parsing_failed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'failed', 'actor_id' => $this->cvFile->user_id,
            ]);

            throw $exception;
        }
    }
}
