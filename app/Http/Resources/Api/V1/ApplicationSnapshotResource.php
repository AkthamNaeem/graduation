<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApplicationSnapshot;
use App\Services\CV\CVFileAccessService;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationSnapshot */
class ApplicationSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $previewable = strtolower($this->cv_mime_type) === CVFileAccessService::PDF_MIME
            && strtolower($this->cv_extension) === 'pdf';

        return [
            'schema_version' => $this->schema_version,
            'origin' => LocalizedValue::make($this->origin, 'application_snapshot_origins'),
            'accuracy' => LocalizedValue::make($this->accuracy, 'application_snapshot_accuracies'),
            'captured_at' => $this->captured_at?->toISOString(),
            'profile' => $this->profile_snapshot,
            'answers' => $this->application_answers_snapshot,
            'cv' => [
                'source_cv_file_id' => $this->source_cv_file_id,
                'original_name' => $this->cv_original_name,
                'mime_type' => $this->cv_mime_type,
                'extension' => $this->cv_extension,
                'size_bytes' => $this->cv_size_bytes,
                'checksum_sha256' => $this->cv_checksum_sha256,
                'preview_supported' => $previewable,
                'allowed_actions' => $previewable ? ['preview', 'download'] : ['download'],
                'preview_url' => $previewable
                    ? route('v1.applications.cv.preview', ['jobApplication' => $this->job_application_id])
                    : null,
                'download_url' => route('v1.applications.cv.download', ['jobApplication' => $this->job_application_id]),
            ],
        ];
    }
}
