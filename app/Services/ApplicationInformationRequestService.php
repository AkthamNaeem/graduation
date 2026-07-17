<?php

namespace App\Services;

use App\Enums\ApplicationInformationRequestStatus;
use App\Enums\UserRole;
use App\Events\ApplicationInformationRequestCancelled;
use App\Events\ApplicationInformationRequested;
use App\Events\ApplicationInformationRequestUpdated;
use App\Events\ApplicationInformationResponded;
use App\Exceptions\ApplicationInformationRequestException;
use App\Exceptions\RecruitmentAccessException;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationInformationResponse;
use App\Models\ApplicationInformationResponseAttachment;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationInformationRequestService
{
    private const FILE_DISK = 'local';

    public function __construct(
        private readonly ApplicationWorkflowService $workflow,
        private readonly CompanyRecruitmentAccessService $companyAccess,
        private readonly AuditLogService $audit,
    ) {}

    /** @return Collection<int, ApplicationInformationRequest> */
    public function list(JobApplication $application): Collection
    {
        return $application->informationRequests()->with($this->relations())->get();
    }

    public function view(ApplicationInformationRequest $request): ApplicationInformationRequest
    {
        return $request->load($this->relations());
    }

    public function create(User $actor, JobApplication $application, array $payload): ApplicationInformationRequest
    {
        $this->ensureEmployerOwns($actor, $application);
        $this->assertCompanyAvailable($application);

        return DB::transaction(function () use ($actor, $application, $payload): ApplicationInformationRequest {
            $locked = JobApplication::query()->with(['applicationStatus', 'jobPosting.company'])->lockForUpdate()->findOrFail($application->id);
            $this->ensureEmployerOwns($actor, $locked);
            $this->assertCompanyAvailable($locked);
            $this->ensureNoPendingRequest($locked);
            $this->ensureActiveApplication($locked);

            $request = ApplicationInformationRequest::query()->create([
                'job_application_id' => $locked->id,
                'requested_by_user_id' => $actor->id,
                'message' => trim($payload['message']),
                'due_at' => $this->dueAt($payload),
                'status' => ApplicationInformationRequestStatus::PENDING,
                'previous_application_status' => $locked->applicationStatus->slug,
            ]);
            $this->replaceItems($request, $payload['requested_items']);
            $this->workflow->requestMoreInformation($actor, $locked);
            $this->audit->record('application.information_request_created', $actor, ApplicationInformationRequest::class, $request->id, null, ['status' => 'pending'], $this->metadata($request, $request->previous_application_status, 'need_more_information'));
            DB::afterCommit(fn (): array => event(new ApplicationInformationRequested($request->id)));

            return $this->view($request);
        });
    }

    public function update(User $actor, ApplicationInformationRequest $request, array $payload): ApplicationInformationRequest
    {
        $application = $request->jobApplication()->with('jobPosting.company')->firstOrFail();
        $this->ensureEmployerOwns($actor, $application);
        $this->assertCompanyAvailable($application);

        return DB::transaction(function () use ($actor, $request, $payload): ApplicationInformationRequest {
            $applicationId = $request->job_application_id;
            $lockedApplication = JobApplication::query()->with(['applicationStatus', 'jobPosting.company'])->lockForUpdate()->findOrFail($applicationId);
            $locked = ApplicationInformationRequest::query()->with('items')->lockForUpdate()->findOrFail($request->id);
            $this->ensureEmployerOwns($actor, $lockedApplication);
            $this->assertCompanyAvailable($lockedApplication);
            $this->ensurePending($locked);

            $before = $this->comparable($locked);
            if (array_key_exists('message', $payload)) {
                $locked->message = trim($payload['message']);
            }
            if (array_key_exists('due_at', $payload)) {
                $locked->due_at = $this->dueAt($payload);
            }
            $locked->save();
            if (array_key_exists('requested_items', $payload)) {
                $this->replaceItems($locked, $payload['requested_items']);
            }
            $locked->load('items');
            $changed = $before !== $this->comparable($locked);

            if ($changed) {
                $audit = $this->audit->record('application.information_request_updated', $actor, ApplicationInformationRequest::class, $locked->id, ['status' => 'pending'], ['status' => 'pending'], $this->metadata($locked, $lockedApplication->applicationStatus->slug, $lockedApplication->applicationStatus->slug));
                $occurrenceId = $audit?->id ?? 'state-'.hash('sha256', json_encode($this->comparable($locked), JSON_THROW_ON_ERROR));
                DB::afterCommit(fn (): array => event(new ApplicationInformationRequestUpdated($locked->id, $occurrenceId)));
            }

            return $this->view($locked);
        });
    }

    /** @param array<int, UploadedFile> $files */
    public function respond(User $actor, ApplicationInformationRequest $request, ?string $message, array $files): ApplicationInformationRequest
    {
        $message = $message === null ? null : trim($message);
        if (($message === null || $message === '') && $files === []) {
            throw ValidationException::withMessages(['response' => ['Provide a non-empty message or at least one attachment.']]);
        }
        $application = $request->jobApplication()->with(['jobPosting.company', 'jobSeekerProfile'])->firstOrFail();
        $this->ensureCandidateOwns($actor, $application);
        $this->assertCompanyAvailable($application);
        $stored = $this->storeFiles($request, $files);

        try {
            return DB::transaction(function () use ($actor, $request, $message, $stored): ApplicationInformationRequest {
                $application = JobApplication::query()->with(['applicationStatus', 'jobPosting.company', 'jobSeekerProfile'])->lockForUpdate()->findOrFail($request->job_application_id);
                $locked = ApplicationInformationRequest::query()->with('response')->lockForUpdate()->findOrFail($request->id);
                $this->ensureCandidateOwns($actor, $application);
                $this->assertCompanyAvailable($application);
                $this->ensurePending($locked);
                if ($locked->response !== null) {
                    $this->fail('This information request already has a response.', 'APPLICATION_INFORMATION_RESPONSE_ALREADY_SUBMITTED');
                }
                if ($locked->due_at !== null && now()->gt($locked->due_at)) {
                    $this->fail('The deadline for submitting the requested information has passed.', 'APPLICATION_INFORMATION_REQUEST_EXPIRED');
                }
                if ($application->applicationStatus->slug !== 'need_more_information') {
                    $this->fail('The application is not awaiting requested information.', 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
                }

                $response = ApplicationInformationResponse::query()->create(['application_information_request_id' => $locked->id, 'submitted_by_user_id' => $actor->id, 'message' => $message ?: null, 'submitted_at' => now()]);
                foreach ($stored as $file) {
                    $response->attachments()->create($file);
                }
                $locked->forceFill(['status' => ApplicationInformationRequestStatus::RESPONDED, 'responded_at' => now()])->save();
                $this->workflow->submitRequestedInformation($actor, $application);
                $this->audit->record('application.information_response_submitted', $actor, ApplicationInformationResponse::class, $response->id, null, ['status' => 'submitted'], $this->metadata($locked, 'need_more_information', 'under_review', $response->id, count($stored)));
                DB::afterCommit(fn (): array => event(new ApplicationInformationResponded($locked->id)));

                return $this->view($locked);
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $file) {
                Storage::disk($file['disk'])->delete($file['stored_path']);
            }
            throw $exception;
        }
    }

    public function cancel(User $actor, ApplicationInformationRequest $request, ?string $reason = null): ApplicationInformationRequest
    {
        $application = $request->jobApplication()->with('jobPosting.company')->firstOrFail();
        $this->ensureEmployerOwns($actor, $application);
        $this->assertCompanyAvailable($application);

        return DB::transaction(function () use ($actor, $request, $reason): ApplicationInformationRequest {
            $application = JobApplication::query()->with(['applicationStatus', 'jobPosting.company'])->lockForUpdate()->findOrFail($request->job_application_id);
            $locked = ApplicationInformationRequest::query()->with('response')->lockForUpdate()->findOrFail($request->id);
            $this->ensureEmployerOwns($actor, $application);
            $this->assertCompanyAvailable($application);
            $this->ensurePending($locked);
            if ($locked->response !== null) {
                $this->fail('Responded information requests cannot be cancelled.', 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
            }
            if ($application->applicationStatus->slug !== 'need_more_information') {
                $this->fail('The application is not awaiting requested information.', 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
            }

            $locked->forceFill(['status' => ApplicationInformationRequestStatus::CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id])->save();
            $target = $locked->previous_application_status ?: 'under_review';
            $this->workflow->cancelInformationRequest($actor, $application, $target, $reason === null ? null : trim($reason));
            $this->audit->record('application.information_request_cancelled', $actor, ApplicationInformationRequest::class, $locked->id, ['status' => 'pending'], ['status' => 'cancelled'], $this->metadata($locked, 'need_more_information', $target));
            DB::afterCommit(fn (): array => event(new ApplicationInformationRequestCancelled($locked->id)));

            return $this->view($locked);
        });
    }

    public function downloadableAttachment(ApplicationInformationResponseAttachment $attachment): ApplicationInformationResponseAttachment
    {
        if (! Storage::disk($attachment->disk)->exists($attachment->stored_path)) {
            abort(404);
        }

        return $attachment;
    }

    private function ensureNoPendingRequest(JobApplication $application): void
    {
        if (ApplicationInformationRequest::query()->where('job_application_id', $application->id)->where('status', 'pending')->exists()) {
            $this->fail('There is already an open information request for this application.', 'APPLICATION_INFORMATION_REQUEST_ALREADY_OPEN');
        }
    }

    private function assertCompanyAvailable(JobApplication $application): void
    {
        try {
            $this->companyAccess->assertRecruitmentAvailable($application);
        } catch (RecruitmentAccessException) {
            throw new ApplicationInformationRequestException(
                'Recruitment activity for this information request is currently unavailable.',
                'APPLICATION_INFORMATION_REQUEST_COMPANY_UNAVAILABLE',
                403,
            );
        }
    }

    private function ensurePending(ApplicationInformationRequest $request): void
    {
        if (! $request->isPending()) {
            $this->fail('This information request is no longer pending.', 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
        }
    }

    private function ensureActiveApplication(JobApplication $application): void
    {
        if (in_array($application->applicationStatus->slug, ['accepted', 'rejected', 'withdrawn'], true)) {
            $this->fail('Terminal applications cannot request additional information.', 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
        }
        $this->workflow->validateTransition($application->applicationStatus->slug, 'need_more_information');
    }

    private function ensureEmployerOwns(User $actor, JobApplication $application): void
    {
        if ($actor->role !== UserRole::EMPLOYER || ! $actor->employerProfile()->where('company_id', $application->jobPosting->company_id)->exists()) {
            throw new ApplicationInformationRequestException('This information request does not belong to your company.', 'APPLICATION_INFORMATION_REQUEST_NOT_OWNED', 403);
        }
    }

    private function ensureCandidateOwns(User $actor, JobApplication $application): void
    {
        if ($actor->role !== UserRole::JOB_SEEKER || (int) ($actor->jobSeekerProfile?->id ?? 0) !== (int) $application->job_seeker_profile_id) {
            throw new ApplicationInformationRequestException('This information request does not belong to you.', 'APPLICATION_INFORMATION_REQUEST_NOT_OWNED', 403);
        }
    }

    private function replaceItems(ApplicationInformationRequest $request, array $items): void
    {
        $request->items()->delete();
        foreach (array_values($items) as $index => $item) {
            $request->items()->create(['label' => trim($item['label']), 'description' => isset($item['description']) && trim((string) $item['description']) !== '' ? trim($item['description']) : null, 'is_required' => $item['is_required'] ?? true, 'order_index' => $index]);
        }
    }

    private function dueAt(array $payload): ?Carbon
    {
        return ! array_key_exists('due_at', $payload) || $payload['due_at'] === null ? null : Carbon::parse($payload['due_at'])->utc();
    }

    /** @param array<int, UploadedFile> $files @return array<int, array<string,mixed>> */
    private function storeFiles(ApplicationInformationRequest $request, array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $path = $file->storeAs("application-information-responses/{$request->id}", Str::uuid().'.'.$extension, self::FILE_DISK);
                if (! is_string($path)) {
                    throw ValidationException::withMessages(['attachments' => ['An attachment could not be stored.']]);
                }
                $stored[] = ['original_name' => basename($file->getClientOriginalName()), 'stored_path' => $path, 'disk' => self::FILE_DISK, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'extension' => $extension ?: null, 'size_bytes' => $file->getSize()];
            }
        } catch (\Throwable $exception) {
            foreach ($stored as $item) {
                Storage::disk($item['disk'])->delete($item['stored_path']);
            }
            throw $exception;
        }

        return $stored;
    }

    private function comparable(ApplicationInformationRequest $request): array
    {
        return ['message' => $request->message, 'due_at' => $request->due_at?->toISOString(), 'items' => $request->items->map(fn ($i) => [$i->label, $i->description, $i->is_required, $i->order_index])->all()];
    }

    private function metadata(ApplicationInformationRequest $request, string $previous, string $new, ?int $responseId = null, int $attachments = 0): array
    {
        return ['application_id' => $request->job_application_id, 'request_id' => $request->id, 'response_id' => $responseId, 'previous_application_status' => $previous, 'new_application_status' => $new, 'due_at' => $request->due_at?->toISOString(), 'requested_items_count' => $request->items()->count(), 'attachment_count' => $attachments, 'status_after' => $request->status?->value];
    }

    private function relations(): array
    {
        return ['items', 'requestedBy:id,name', 'cancelledBy:id,name', 'response.submittedBy:id,name', 'response.attachments', 'jobApplication.applicationStatus', 'jobApplication.jobPosting.company', 'jobApplication.jobSeekerProfile.user'];
    }

    private function fail(string $message, string $code): never
    {
        throw new ApplicationInformationRequestException($message, $code);
    }
}
