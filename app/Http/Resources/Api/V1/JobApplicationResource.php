<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    use ResolvesResourceViewer;

    public function toArray(Request $request): array
    {
        $manager = $this->viewerIsManager($request);
        $page = $this->getAttribute('application_page');
        $snapshotLoaded = $this->relationLoaded('snapshot');
        $snapshot = $snapshotLoaded ? $this->snapshot : null;
        $snapshotHasDetails = $snapshot !== null
            && array_key_exists('profile_snapshot', $snapshot->getAttributes());
        $includeSubmittedSnapshot = (bool) $this->getAttribute('include_submitted_snapshot');

        return [
            'id' => $this->id,
            'job_posting_id' => $this->job_posting_id,
            'job_seeker_profile_id' => $this->job_seeker_profile_id,
            'selected_cv_file_id' => $this->selected_cv_file_id,
            'application_status_id' => $this->application_status_id,
            'status' => ApplicationStatusResource::make($this->whenLoaded('applicationStatus')),
            'job_posting' => JobPostingResource::make($this->whenLoaded('jobPosting')),
            'job_seeker_profile' => $this->when(
                $manager && $snapshot === null && $this->relationLoaded('jobSeekerProfile'),
                fn () => new JobSeekerProfileResource($this->jobSeekerProfile),
            ),
            'selected_cv' => $this->when(
                $snapshot !== null || $this->relationLoaded('selectedCvFile'),
                fn (): ?array => $snapshot !== null ? [
                    'id' => $snapshot->source_cv_file_id,
                    'original_name' => $snapshot->cv_original_name,
                    'version_label' => null,
                    'mime_type' => $snapshot->cv_mime_type,
                    'extension' => $snapshot->cv_extension,
                    'size_bytes' => $snapshot->cv_size_bytes,
                    'download_url' => route('v1.applications.cv.download', ['jobApplication' => $this->id]),
                    'preview_url' => strtolower($snapshot->cv_extension) === 'pdf'
                        ? route('v1.applications.cv.preview', ['jobApplication' => $this->id])
                        : null,
                    'uploaded_at' => null,
                ] : ($this->selectedCvFile === null ? null : [
                    'id' => $this->selectedCvFile->id,
                    'original_name' => $this->selectedCvFile->original_name,
                    'version_label' => $this->selectedCvFile->version_label,
                    'mime_type' => $this->selectedCvFile->mime_type,
                    'extension' => $this->selectedCvFile->extension,
                    'size_bytes' => $this->selectedCvFile->size_bytes,
                    'download_url' => route('v1.applications.cv.download', ['jobApplication' => $this->id]),
                    'uploaded_at' => $this->selectedCvFile->created_at?->toISOString(),
                ]),
            ),
            'snapshot_status' => $this->when(
                $snapshotLoaded,
                fn () => LocalizedValue::make(
                    $snapshot === null ? 'not_available' : 'available',
                    'application_snapshot_statuses',
                ),
            ),
            'submitted_cv_name' => $this->when($snapshotLoaded, $snapshot?->cv_original_name),
            'snapshot_captured_at' => $this->when($snapshotLoaded, $snapshot?->captured_at?->toISOString()),
            'submitted_snapshot' => $this->when(
                $includeSubmittedSnapshot,
                fn () => $snapshotHasDetails ? new ApplicationSnapshotResource($snapshot) : null,
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
                    'status' => LocalizedValue::make(
                        $this->latestInformationRequest->status,
                        'application_information_request_statuses',
                    ),
                    'due_at' => $this->latestInformationRequest->due_at?->toISOString(),
                    'is_expired' => $this->latestInformationRequest->isExpired(),
                    'can_respond' => ! $manager && $this->latestInformationRequest->canBeRespondedTo(),
                ],
            ),
            'requires_action' => $this->when($page !== null, $page['requires_action'] ?? false),
            'next_action' => $this->when($page !== null, $page['next_action'] ?? null),
            'allowed_actions' => $this->when($page !== null, $page['allowed_actions'] ?? []),
            'last_status_changed_at' => $this->when($page !== null, $page['last_status_changed_at'] ?? null),
            'upcoming_event' => $this->when($page !== null, $page['upcoming_event'] ?? null),
            'current_test' => $this->when($page !== null, $page['current_test'] ?? null),
            'relevant_interview' => $this->when($page !== null, $page['relevant_interview'] ?? null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
