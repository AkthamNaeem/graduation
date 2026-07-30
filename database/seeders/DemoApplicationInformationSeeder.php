<?php

namespace Database\Seeders;

use App\Enums\ApplicationInformationRequestStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationInformationRequestItem;
use App\Models\ApplicationInformationResponse;
use App\Models\ApplicationInformationResponseAttachment;
use App\Models\ApplicationInternalNote;
use App\Models\ApplicationInternalNoteRevision;
use App\Models\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\JobApplication;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DemoApplicationInformationSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $requester = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $secondEmployer = User::query()->where('email', 'employer.recruiter@workey.test')->firstOrFail();

        $pending = $this->applicationByStatus('need_more_information');
        $this->createRequest(
            application: $pending,
            requester: $requester,
            status: ApplicationInformationRequestStatus::PENDING,
            previousStatus: 'under_review',
            createdAt: $now->copy()->subDays(3),
            dueAt: $now->copy()->subDay(),
            message: 'Please provide a public repository link and clarify your weekly availability.',
        );

        $respondedApplication = $this->applicationByStatus('under_review');
        $respondedRequest = $this->createRequest(
            application: $respondedApplication,
            requester: $requester,
            status: ApplicationInformationRequestStatus::RESPONDED,
            previousStatus: 'under_review',
            createdAt: $now->copy()->subDays(7),
            dueAt: $now->copy()->subDays(2),
            message: 'Please attach a concise portfolio summary.',
        );
        $candidate = $respondedApplication->jobSeekerProfile->user;
        $response = ApplicationInformationResponse::query()->create([
            'application_information_request_id' => $respondedRequest->id,
            'submitted_by_user_id' => $candidate->id,
            'message' => 'Attached is a summary of the design system and accessible interfaces I delivered.',
            'submitted_at' => $now->copy()->subDays(5),
        ]);
        $path = 'demo-seed/application-information/frontend-portfolio.txt';
        $content = "Frontend portfolio summary\nReact, TypeScript, accessibility, and automated tests.\n";
        Storage::disk('local')->put($path, $content);
        ApplicationInformationResponseAttachment::query()->create([
            'application_information_response_id' => $response->id,
            'original_name' => 'frontend-portfolio.txt',
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($content),
        ]);
        $this->appendRoundTripHistory($respondedApplication, 'under_review', $requester, $candidate, $now->copy()->subDays(7));

        $cancelledApplication = $this->applicationByStatus('shortlisted');
        $cancelledRequest = $this->createRequest(
            application: $cancelledApplication,
            requester: $requester,
            status: ApplicationInformationRequestStatus::CANCELLED,
            previousStatus: 'shortlisted',
            createdAt: $now->copy()->subDays(6),
            dueAt: $now->copy()->addDay(),
            message: 'This wording was updated before cancellation: confirm leadership availability.',
        );
        $cancelledRequest->forceFill([
            'cancelled_at' => $now->copy()->subDays(4),
            'cancelled_by_user_id' => $requester->id,
            'updated_at' => $now->copy()->subDays(4),
        ])->save();
        $this->appendRoundTripHistory($cancelledApplication, 'shortlisted', $requester, $requester, $now->copy()->subDays(6));

        $this->internalNotes($this->applicationByStatus('accepted'), $requester, $secondEmployer, $now);
    }

    private function createRequest(
        JobApplication $application,
        User $requester,
        ApplicationInformationRequestStatus $status,
        string $previousStatus,
        Carbon $createdAt,
        Carbon $dueAt,
        string $message,
    ): ApplicationInformationRequest {
        $request = ApplicationInformationRequest::query()->create([
            'job_application_id' => $application->id,
            'requested_by_user_id' => $requester->id,
            'message' => $message,
            'due_at' => $dueAt,
            'status' => $status,
            'previous_application_status' => $previousStatus,
            'responded_at' => $status === ApplicationInformationRequestStatus::RESPONDED
                ? $createdAt->copy()->addDays(2)
                : null,
            'cancelled_at' => $status === ApplicationInformationRequestStatus::CANCELLED
                ? $createdAt->copy()->addDays(2)
                : null,
            'cancelled_by_user_id' => $status === ApplicationInformationRequestStatus::CANCELLED
                ? $requester->id
                : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt->copy()->addDay(),
        ]);

        foreach ([
            ['label' => 'Portfolio evidence', 'description' => 'A link or short attachment showing recent work.', 'required' => true],
            ['label' => 'Availability', 'description' => 'Earliest start date and weekly availability.', 'required' => false],
        ] as $index => $item) {
            ApplicationInformationRequestItem::query()->create([
                'application_information_request_id' => $request->id,
                'label' => $item['label'],
                'description' => $item['description'],
                'is_required' => $item['required'],
                'order_index' => $index + 1,
            ]);
        }

        return $request;
    }

    private function appendRoundTripHistory(
        JobApplication $application,
        string $returnStatus,
        User $requestActor,
        User $returnActor,
        Carbon $at,
    ): void {
        $statuses = ApplicationStatus::query()
            ->whereIn('slug', [$returnStatus, 'need_more_information'])
            ->get()
            ->keyBy('slug');
        $last = $application->statusHistory()->latest('created_at')->firstOrFail();
        $firstAt = $last->created_at->greaterThan($at) ? $last->created_at->copy()->addHour() : $at;

        ApplicationStatusHistory::query()->create([
            'job_application_id' => $application->id,
            'from_application_status_id' => $statuses[$returnStatus]->id,
            'to_application_status_id' => $statuses['need_more_information']->id,
            'changed_by_user_id' => $requestActor->id,
            'note' => 'Additional information requested.',
            'created_at' => $firstAt,
            'updated_at' => $firstAt,
        ]);
        ApplicationStatusHistory::query()->create([
            'job_application_id' => $application->id,
            'from_application_status_id' => $statuses['need_more_information']->id,
            'to_application_status_id' => $statuses[$returnStatus]->id,
            'changed_by_user_id' => $returnActor->id,
            'note' => 'Information request resolved and the previous state restored.',
            'created_at' => $firstAt->copy()->addDay(),
            'updated_at' => $firstAt->copy()->addDay(),
        ]);
    }

    private function internalNotes(JobApplication $application, User $author, User $secondAuthor, Carbon $now): void
    {
        $edited = ApplicationInternalNote::query()->create([
            'job_application_id' => $application->id,
            'author_user_id' => $author->id,
            'body' => 'Candidate demonstrated strong API design and testing fundamentals.',
            'version' => 2,
            'edited_at' => $now->copy()->subDays(4),
            'created_at' => $now->copy()->subDays(6),
            'updated_at' => $now->copy()->subDays(4),
        ]);
        ApplicationInternalNoteRevision::query()->create([
            'application_internal_note_id' => $edited->id,
            'version' => 1,
            'body' => 'Candidate demonstrated strong API design fundamentals.',
            'edited_by_user_id' => $author->id,
            'created_at' => $now->copy()->subDays(4),
        ]);
        ApplicationInternalNote::query()->create([
            'job_application_id' => $application->id,
            'author_user_id' => $secondAuthor->id,
            'body' => 'Reference check completed; feedback was positive.',
            'version' => 1,
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now->copy()->subDays(3),
        ]);
        $deleted = ApplicationInternalNote::query()->create([
            'job_application_id' => $application->id,
            'author_user_id' => $author->id,
            'body' => 'Outdated interview scheduling note.',
            'version' => 1,
            'created_at' => $now->copy()->subDays(8),
            'updated_at' => $now->copy()->subDays(8),
        ]);
        $deleted->forceFill([
            'deleted_at' => $now->copy()->subDays(7),
            'deleted_by_user_id' => $author->id,
        ])->save();
    }

    private function applicationByStatus(string $slug): JobApplication
    {
        return JobApplication::query()
            ->with(['jobSeekerProfile.user', 'statusHistory'])
            ->whereHas('applicationStatus', fn ($query) => $query->where('slug', $slug))
            ->firstOrFail();
    }
}
