<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    use ResolvesResourceViewer;

    public function toArray(Request $request): array
    {
        $manager = $this->viewerIsManager($request);

        return [
            'id' => $this->id,
            'job_posting_id' => $this->job_posting_id,
            'job_seeker_profile_id' => $this->job_seeker_profile_id,
            'selected_cv_file_id' => $this->selected_cv_file_id,
            'application_status_id' => $this->application_status_id,
            'status' => ApplicationStatusResource::make($this->whenLoaded('applicationStatus')),
            'job_posting' => JobPostingResource::make($this->whenLoaded('jobPosting')),
            'job_seeker_profile' => $this->when(
                $manager && $this->relationLoaded('jobSeekerProfile'),
                fn () => new JobSeekerProfileResource($this->jobSeekerProfile),
            ),
            'selected_cv' => $this->when(
                $this->relationLoaded('selectedCvFile'),
                fn (): ?array => $this->selectedCvFile === null ? null : [
                    'id' => $this->selectedCvFile->id,
                    'original_name' => $this->selectedCvFile->original_name,
                    'version_label' => $this->selectedCvFile->version_label,
                    'mime_type' => $this->selectedCvFile->mime_type,
                    'extension' => $this->selectedCvFile->extension,
                    'size_bytes' => $this->selectedCvFile->size_bytes,
                    'download_url' => route('v1.applications.cv.download', ['jobApplication' => $this->id]),
                    'uploaded_at' => $this->selectedCvFile->created_at?->toISOString(),
                ],
            ),
            'cover_letter' => $this->cover_letter,
            'consent_to_share_profile' => $this->consent_to_share_profile,
            'screening_answers' => $this->relationLoaded('screeningQuestionSnapshots')
                && $this->screeningQuestionSnapshots->isNotEmpty()
                ? JobApplicationScreeningQuestionResource::collection($this->screeningQuestionSnapshots)
                : ($this->screening_answers ?? []),
            'status_history' => ApplicationStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'latest_information_request' => $this->when(
                $this->relationLoaded('latestInformationRequest'),
                fn (): ?array => $this->latestInformationRequest === null ? null : [
                    'id' => $this->latestInformationRequest->id,
                    'status' => $this->latestInformationRequest->status?->value,
                    'due_at' => $this->latestInformationRequest->due_at?->toISOString(),
                    'is_expired' => $this->latestInformationRequest->isExpired(),
                    'can_respond' => ! $manager && $this->latestInformationRequest->canBeRespondedTo(),
                ],
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
