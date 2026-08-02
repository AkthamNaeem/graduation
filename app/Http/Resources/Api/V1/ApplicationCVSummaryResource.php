<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationCVSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'source_cv_file_id' => $this->source_cv_file_id,
            'locale' => $this->locale,
            'headline' => $this->headline,
            'summary' => $this->summary,
            'strengths' => $this->strengths,
            'gaps' => $this->gaps,
            'evidence' => $this->evidence,
            'ai_disclaimer' => __('cv_summary.disclaimer'),
            'generation' => [
                'provider' => $this->provider,
                'model' => $this->model,
                'prompt_version' => $this->prompt_version,
                'generated_at' => $this->generated_at?->toISOString(),
            ],
        ];
    }
}
