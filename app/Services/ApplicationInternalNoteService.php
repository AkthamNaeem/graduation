<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Enums\UserRole;
use App\Exceptions\ApplicationInternalNoteException;
use App\Models\ApplicationInternalNote;
use App\Models\ApplicationInternalNoteRevision;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ApplicationInternalNoteService
{
    private const FINAL_STATUSES = ['accepted', 'rejected', 'withdrawn'];

    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function listForApplication(User $actor, JobApplication $application, array $filters): LengthAwarePaginator
    {
        $application->loadMissing('jobPosting');
        $this->assertCompanyOwnership($actor, $application);

        return $application->internalNotes()
            ->when((bool) ($filters['include_deleted'] ?? false), fn ($query) => $query->withTrashed())
            ->when(isset($filters['author_user_id']), fn ($query) => $query->where('author_user_id', $filters['author_user_id']))
            ->with(['author:id,name', 'jobApplication.applicationStatus', 'jobApplication.jobPosting.company'])
            ->orderBy('created_at', $filters['sort_direction'] ?? 'desc')
            ->orderBy('id', $filters['sort_direction'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function show(User $actor, ApplicationInternalNote $note): ApplicationInternalNote
    {
        $note = ApplicationInternalNote::withTrashed()
            ->with(['author:id,name', 'jobApplication.applicationStatus', 'jobApplication.jobPosting.company'])
            ->findOrFail($note->id);
        $this->assertCompanyOwnership($actor, $note->jobApplication);

        return $note;
    }

    public function create(User $actor, JobApplication $application, string $body): ApplicationInternalNote
    {
        return DB::transaction(function () use ($actor, $application, $body): ApplicationInternalNote {
            $lockedApplication = $this->lockedApplication($application->id);
            $this->assertCompanyOwnership($actor, $lockedApplication);
            $this->assertCompanyApproved($lockedApplication);
            $this->assertApplicationMutable($lockedApplication);

            $note = ApplicationInternalNote::create([
                'job_application_id' => $lockedApplication->id,
                'author_user_id' => $actor->id,
                'body' => trim($body),
                'version' => 1,
            ]);

            $this->auditLogService->record('application.internal_note_created', $actor, ApplicationInternalNote::class, $note->id, null, null, [
                'application_id' => $lockedApplication->id,
                'note_id' => $note->id,
                'author_user_id' => $actor->id,
                'actor_id' => $actor->id,
                'new_version' => 1,
                'body_length' => mb_strlen($note->body),
                'is_deleted' => false,
            ]);

            return $this->loadNote($note);
        });
    }

    public function update(User $actor, ApplicationInternalNote $note, string $body, int $expectedVersion): ApplicationInternalNote
    {
        return DB::transaction(function () use ($actor, $note, $body, $expectedVersion): ApplicationInternalNote {
            $application = $this->lockedApplication($note->job_application_id);
            $lockedNote = ApplicationInternalNote::withTrashed()->lockForUpdate()->findOrFail($note->id);
            $this->assertCompanyOwnership($actor, $application);
            $this->assertAuthor($actor, $lockedNote);
            $this->assertCompanyApproved($application);
            $this->assertApplicationMutable($application);
            $this->assertActive($lockedNote);
            $this->assertWithinWindow($lockedNote);
            $this->assertVersion($lockedNote, $expectedVersion);

            $newBody = trim($body);
            if ($newBody === $lockedNote->body) {
                return $this->loadNote($lockedNote);
            }

            ApplicationInternalNoteRevision::create([
                'application_internal_note_id' => $lockedNote->id,
                'version' => $lockedNote->version,
                'body' => $lockedNote->body,
                'edited_by_user_id' => $actor->id,
            ]);

            $previousVersion = $lockedNote->version;
            $lockedNote->forceFill([
                'body' => $newBody,
                'version' => $previousVersion + 1,
                'edited_at' => now(),
            ])->save();

            $this->auditLogService->record('application.internal_note_updated', $actor, ApplicationInternalNote::class, $lockedNote->id, null, null, [
                'application_id' => $application->id,
                'note_id' => $lockedNote->id,
                'author_user_id' => $lockedNote->author_user_id,
                'actor_id' => $actor->id,
                'previous_version' => $previousVersion,
                'new_version' => $lockedNote->version,
                'body_length' => mb_strlen($lockedNote->body),
                'is_deleted' => false,
            ]);

            return $this->loadNote($lockedNote);
        });
    }

    public function softDelete(User $actor, ApplicationInternalNote $note, int $expectedVersion): ApplicationInternalNote
    {
        return DB::transaction(function () use ($actor, $note, $expectedVersion): ApplicationInternalNote {
            $application = $this->lockedApplication($note->job_application_id);
            $lockedNote = ApplicationInternalNote::withTrashed()->lockForUpdate()->findOrFail($note->id);
            $this->assertCompanyOwnership($actor, $application);
            $this->assertAuthor($actor, $lockedNote);
            $this->assertCompanyApproved($application);
            $this->assertApplicationMutable($application);
            $this->assertActive($lockedNote, deleting: true);
            $this->assertWithinWindow($lockedNote);
            $this->assertVersion($lockedNote, $expectedVersion);

            $lockedNote->forceFill(['deleted_by_user_id' => $actor->id])->save();
            $lockedNote->delete();

            $this->auditLogService->record('application.internal_note_deleted', $actor, ApplicationInternalNote::class, $lockedNote->id, null, null, [
                'application_id' => $application->id,
                'note_id' => $lockedNote->id,
                'author_user_id' => $lockedNote->author_user_id,
                'actor_id' => $actor->id,
                'previous_version' => $lockedNote->version,
                'new_version' => $lockedNote->version,
                'body_length' => mb_strlen($lockedNote->body),
                'is_deleted' => true,
            ]);

            return $this->loadNote($lockedNote->refresh());
        });
    }

    public function listRevisions(User $actor, ApplicationInternalNote $note, int $perPage = 15): LengthAwarePaginator
    {
        $note = ApplicationInternalNote::withTrashed()->with('jobApplication.jobPosting')->findOrFail($note->id);
        $this->assertCompanyOwnership($actor, $note->jobApplication);

        return $note->revisions()->with('editedBy:id,name')->orderByDesc('version')->orderByDesc('id')->paginate($perPage);
    }

    private function lockedApplication(int $applicationId): JobApplication
    {
        return JobApplication::query()->lockForUpdate()->findOrFail($applicationId)
            ->load(['applicationStatus', 'jobPosting.company']);
    }

    private function loadNote(ApplicationInternalNote $note): ApplicationInternalNote
    {
        return $note->load(['author:id,name', 'jobApplication.applicationStatus', 'jobApplication.jobPosting.company']);
    }

    private function assertCompanyOwnership(User $actor, JobApplication $application): void
    {
        $companyId = $application->jobPosting?->company_id;
        $owned = $actor->role === UserRole::EMPLOYER
            && $companyId !== null
            && $actor->employerProfile()->where('company_id', $companyId)->exists();

        if (! $owned) {
            throw new ApplicationInternalNoteException('The internal note is not available to this user.', 'APPLICATION_INTERNAL_NOTE_NOT_OWNED', 403);
        }
    }

    private function assertAuthor(User $actor, ApplicationInternalNote $note): void
    {
        if ($note->author_user_id !== $actor->id) {
            throw new ApplicationInternalNoteException('Only the note author can change it.', 'APPLICATION_INTERNAL_NOTE_AUTHOR_ONLY', 403);
        }
    }

    private function assertCompanyApproved(JobApplication $application): void
    {
        if ($application->jobPosting?->company?->approval_status !== CompanyApprovalStatus::APPROVED->value) {
            throw new ApplicationInternalNoteException('Internal note changes are unavailable while the company is not approved.', 'APPLICATION_INTERNAL_NOTE_COMPANY_UNAVAILABLE', 403);
        }
    }

    private function assertApplicationMutable(JobApplication $application): void
    {
        if (in_array($application->applicationStatus?->slug, self::FINAL_STATUSES, true)) {
            throw new ApplicationInternalNoteException('Internal notes are read-only for final applications.', 'APPLICATION_INTERNAL_NOTES_READ_ONLY');
        }
    }

    private function assertActive(ApplicationInternalNote $note, bool $deleting = false): void
    {
        if ($note->trashed()) {
            throw new ApplicationInternalNoteException(
                'This internal note has already been deleted.',
                $deleting ? 'APPLICATION_INTERNAL_NOTE_ALREADY_DELETED' : 'APPLICATION_INTERNAL_NOTE_READ_ONLY',
            );
        }
    }

    private function assertWithinWindow(ApplicationInternalNote $note): void
    {
        if (! $note->isWithinEditWindow()) {
            throw new ApplicationInternalNoteException('The internal note edit window has expired.', 'APPLICATION_INTERNAL_NOTE_EDIT_WINDOW_EXPIRED');
        }
    }

    private function assertVersion(ApplicationInternalNote $note, int $expectedVersion): void
    {
        if ($note->version !== $expectedVersion) {
            throw new ApplicationInternalNoteException('The note was changed by another request.', 'APPLICATION_INTERNAL_NOTE_VERSION_CONFLICT', errors: [
                'current_version' => [$note->version],
            ]);
        }
    }
}
