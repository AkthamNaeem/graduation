<?php

namespace App\Jobs;

use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Services\CVParsingService;
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

    public function handle(CVParsingService $cvParsingService): void
    {
        $this->cvFile->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->save();

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
        } catch (Throwable $exception) {
            $this->cvFile->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
