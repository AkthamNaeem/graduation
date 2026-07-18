<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationInternalNote;
use App\Models\ApplicationInternalNoteRevision;
use App\Models\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationInternalNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        Carbon::setTestNow('2026-08-10T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_employer_creates_trimmed_note_without_workflow_or_notification_side_effects(): void
    {
        [$company, $author, , , $application] = $this->scenario();
        $statusId = $application->application_status_id;

        $response = $this->withToken($this->tokenFor($author))
            ->postJson("/api/v1/applications/{$application->id}/internal-notes", [
                'body' => '  Candidate has strong Laravel experience.  ',
            ])->assertCreated()
            ->assertJsonPath('data.body', 'Candidate has strong Laravel experience.')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.author.id', $author->id)
            ->assertJsonPath('data.author.name', $author->name)
            ->assertJsonPath('data.can_edit', true);

        $noteId = $response->json('data.id');
        $this->assertDatabaseHas('application_internal_notes', [
            'id' => $noteId, 'author_user_id' => $author->id, 'version' => 1,
            'body' => 'Candidate has strong Laravel experience.',
        ]);
        $this->assertSame($statusId, $application->refresh()->application_status_id);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.internal_note_created', 'entity_id' => $noteId]);
        $audit = AuditLog::where('action', 'application.internal_note_created')->firstOrFail();
        $this->assertSame(mb_strlen('Candidate has strong Laravel experience.'), $audit->metadata['body_length']);
        $this->assertStringNotContainsString('Laravel experience', json_encode($audit->metadata));
        $this->assertSame('approved', $company->approval_status);
    }

    public function test_create_validates_plain_text_length_access_company_and_final_state(): void
    {
        [$company, $author, , $otherEmployer, $application, $candidate] = $this->scenario();
        $url = "/api/v1/applications/{$application->id}/internal-notes";

        $this->withToken($this->tokenFor($author))->postJson($url, ['body' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->withToken($this->tokenFor($author))->postJson($url, ['body' => '<b>private</b>'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->withToken($this->tokenFor($author))->postJson($url, ['body' => str_repeat('a', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->withToken($this->tokenFor($candidate))->postJson($url, ['body' => 'private'])->assertForbidden();
        $this->withToken($this->tokenFor($otherEmployer))->postJson($url, ['body' => 'private'])->assertForbidden();

        $company->update(['approval_status' => 'suspended']);
        $this->withToken($this->tokenFor($author))->postJson($url, ['body' => 'private'])
            ->assertForbidden()->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_COMPANY_UNAVAILABLE');
        $company->update(['approval_status' => 'approved']);
        $application->update(['application_status_id' => $this->statusId('accepted')]);
        $this->withToken($this->tokenFor($author))->postJson($url, ['body' => 'private'])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTES_READ_ONLY');

        $this->assertDatabaseCount('application_internal_notes', 0);
    }

    public function test_list_is_paginated_filtered_ordered_and_deleted_notes_are_tombstones(): void
    {
        [, $author, $colleague, , $application] = $this->scenario();
        $older = $this->note($application, $author, 'older', '2026-08-10T09:58:00Z');
        $newer = $this->note($application, $colleague, 'newer', '2026-08-10T09:59:00Z');
        $deleted = $this->note($application, $author, 'deleted secret', '2026-08-10T09:57:00Z');
        $deleted->forceFill(['deleted_by_user_id' => $author->id])->save();
        $deleted->delete();
        $token = $this->tokenFor($author);

        $this->withToken($token)->getJson("/api/v1/applications/{$application->id}/internal-notes?per_page=1")
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $newer->id)
            ->assertJsonPath('data.meta.total', 2);

        $this->withToken($token)->getJson("/api/v1/applications/{$application->id}/internal-notes?include_deleted=true&author_user_id={$author->id}&sort_direction=asc")
            ->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $deleted->id)
            ->assertJsonPath('data.data.0.body', null)
            ->assertJsonPath('data.data.0.is_deleted', true)
            ->assertJsonPath('data.data.1.id', $older->id);
    }

    public function test_author_update_creates_revision_and_noop_has_no_side_effect(): void
    {
        [, $author, $colleague, , $application] = $this->scenario();
        $note = $this->note($application, $author, 'Version one');
        $url = "/api/v1/application-internal-notes/{$note->id}";

        $this->withToken($this->tokenFor($colleague))->patchJson($url, ['body' => 'Other edit', 'version' => 1])
            ->assertForbidden()->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_AUTHOR_ONLY');

        $this->withToken($this->tokenFor($author))->patchJson($url, ['body' => 'Version two', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_edited', true);
        $this->assertDatabaseHas('application_internal_note_revisions', [
            'application_internal_note_id' => $note->id, 'version' => 1, 'body' => 'Version one',
            'edited_by_user_id' => $author->id,
        ]);

        $auditCount = AuditLog::where('action', 'application.internal_note_updated')->count();
        $this->withToken($this->tokenFor($author))->patchJson($url, ['body' => '  Version two  ', 'version' => 2])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('application_internal_note_revisions', 1);
        $this->assertSame($auditCount, AuditLog::where('action', 'application.internal_note_updated')->count());

        $this->withToken($this->tokenFor($author))->patchJson($url, ['body' => 'stale', 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_VERSION_CONFLICT')
            ->assertJsonPath('errors.current_version.0', 2);
        $this->assertDatabaseCount('application_internal_note_revisions', 1);
        $this->assertSame(1, AuditLog::where('action', 'application.internal_note_updated')->count());
    }

    public function test_exact_edit_boundary_is_allowed_and_one_second_later_is_rejected(): void
    {
        [, $author, , , $application] = $this->scenario();
        $atBoundary = $this->note($application, $author, 'boundary');
        Carbon::setTestNow('2026-08-10T10:15:00Z');
        $this->withToken($this->tokenFor($author))->patchJson("/api/v1/application-internal-notes/{$atBoundary->id}", [
            'body' => 'allowed', 'version' => 1,
        ])->assertOk();

        $expired = $this->note($application, $author, 'expired', '2026-08-10T10:00:00Z');
        Carbon::setTestNow('2026-08-10T10:15:01Z');
        $this->withToken($this->tokenFor($author))->patchJson("/api/v1/application-internal-notes/{$expired->id}", [
            'body' => 'blocked', 'version' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_EDIT_WINDOW_EXPIRED');
    }

    public function test_soft_delete_requires_author_window_and_version_and_preserves_history(): void
    {
        [, $author, $colleague, , $application] = $this->scenario();
        $note = $this->note($application, $author, 'current');
        ApplicationInternalNoteRevision::create([
            'application_internal_note_id' => $note->id, 'version' => 1, 'body' => 'previous', 'edited_by_user_id' => $author->id,
        ]);
        $note->update(['version' => 2]);
        $url = "/api/v1/application-internal-notes/{$note->id}";

        $this->withToken($this->tokenFor($colleague))->deleteJson($url, ['version' => 2])->assertForbidden();
        $this->withToken($this->tokenFor($author))->deleteJson($url, ['version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_VERSION_CONFLICT');
        $this->withToken($this->tokenFor($author))->deleteJson($url, ['version' => 2])
            ->assertOk()->assertJsonPath('data.is_deleted', true)->assertJsonPath('data.body', null);

        $this->assertSoftDeleted('application_internal_notes', ['id' => $note->id]);
        $this->assertSame('current', ApplicationInternalNote::withTrashed()->findOrFail($note->id)->body);
        $this->assertDatabaseCount('application_internal_note_revisions', 1);
        $this->withToken($this->tokenFor($author))->deleteJson($url, ['version' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_ALREADY_DELETED');
        $this->withToken($this->tokenFor($author))->patchJson($url, ['body' => 'cannot revive', 'version' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_READ_ONLY');
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_revision_history_is_paginated_ordered_private_and_survives_deletion(): void
    {
        [, $author, , $otherEmployer, $application, $candidate] = $this->scenario();
        $note = $this->note($application, $author, 'v3');
        foreach ([1 => 'v1', 2 => 'v2'] as $version => $body) {
            ApplicationInternalNoteRevision::create([
                'application_internal_note_id' => $note->id, 'version' => $version, 'body' => $body, 'edited_by_user_id' => $author->id,
            ]);
        }
        $note->update(['version' => 3, 'deleted_by_user_id' => $author->id]);
        $note->delete();
        $url = "/api/v1/application-internal-notes/{$note->id}/revisions?per_page=1";

        $this->withToken($this->tokenFor($author))->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.version', 2)
            ->assertJsonPath('data.data.0.edited_by.id', $author->id)->assertJsonPath('data.meta.total', 2);
        $this->withToken($this->tokenFor($author))->getJson("/api/v1/application-internal-notes/{$note->id}")
            ->assertOk()->assertJsonPath('data.is_deleted', true)->assertJsonPath('data.body', null);
        $this->withToken($this->tokenFor($candidate))->getJson($url)->assertForbidden();
        $this->withToken($this->tokenFor($otherEmployer))->getJson($url)->assertForbidden();
    }

    public function test_final_states_allow_reads_and_block_all_mutations(): void
    {
        foreach (['accepted', 'rejected', 'withdrawn'] as $slug) {
            [, $author, , , $application] = $this->scenario('approved', $slug);
            $note = $this->note($application, $author, "{$slug} note");
            $token = $this->tokenFor($author);

            $this->withToken($token)->getJson("/api/v1/applications/{$application->id}/internal-notes")->assertOk();
            $this->withToken($token)->getJson("/api/v1/application-internal-notes/{$note->id}")->assertOk();
            $this->withToken($token)->getJson("/api/v1/application-internal-notes/{$note->id}/revisions")->assertOk();
            $this->withToken($token)->postJson("/api/v1/applications/{$application->id}/internal-notes", ['body' => 'blocked'])
                ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTES_READ_ONLY');
            $this->withToken($token)->patchJson("/api/v1/application-internal-notes/{$note->id}", ['body' => 'blocked', 'version' => 1])
                ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTES_READ_ONLY');
            $this->withToken($token)->deleteJson("/api/v1/application-internal-notes/{$note->id}", ['version' => 1])
                ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTES_READ_ONLY');
        }
    }

    public function test_suspended_company_keeps_reads_but_blocks_writes_and_reapproval_does_not_reset_window(): void
    {
        [$company, $author, , , $application] = $this->scenario();
        $note = $this->note($application, $author, 'historical');
        $company->update(['approval_status' => 'suspended']);
        $token = $this->tokenFor($author);

        $this->withToken($token)->getJson("/api/v1/applications/{$application->id}/internal-notes")->assertOk();
        $this->withToken($token)->patchJson("/api/v1/application-internal-notes/{$note->id}", ['body' => 'blocked', 'version' => 1])
            ->assertForbidden()->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_COMPANY_UNAVAILABLE');
        $this->withToken($token)->deleteJson("/api/v1/application-internal-notes/{$note->id}", ['version' => 1])
            ->assertForbidden()->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_COMPANY_UNAVAILABLE');

        Carbon::setTestNow('2026-08-10T10:16:00Z');
        $company->update(['approval_status' => 'approved']);
        $this->withToken($token)->patchJson("/api/v1/application-internal-notes/{$note->id}", ['body' => 'still blocked', 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_EDIT_WINDOW_EXPIRED');
        $this->withToken($token)->deleteJson("/api/v1/application-internal-notes/{$note->id}", ['version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'APPLICATION_INTERNAL_NOTE_EDIT_WINDOW_EXPIRED');
    }

    public function test_candidate_never_sees_or_accesses_internal_notes(): void
    {
        [, $author, , , $application, $candidate] = $this->scenario();
        $note = $this->note($application, $author, 'private assessment');
        $token = $this->tokenFor($candidate);

        $this->withToken($token)->getJson('/api/v1/applications/my')->assertOk()
            ->assertJsonMissingPath('data.data.0.internal_notes')
            ->assertJsonMissingPath('data.data.0.internal_notes_count')
            ->assertJsonMissingPath('data.data.0.latest_internal_note_at');
        $this->withToken($token)->getJson("/api/v1/applications/{$application->id}")->assertOk()
            ->assertJsonMissingPath('data.internal_notes')->assertJsonMissingPath('data.internal_notes_count')
            ->assertJsonMissingPath('data.latest_internal_note_at')->assertJsonMissingPath('data.note_revisions');
        $this->withToken($token)->getJson("/api/v1/applications/{$application->id}/internal-notes")->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/application-internal-notes/{$note->id}")->assertForbidden();
    }

    /** @return array{Company,User,User,User,JobApplication,User} */
    private function scenario(string $companyState = 'approved', string $status = 'under_review'): array
    {
        $company = Company::create(['name' => 'Notes Co '.Str::random(5), 'approval_status' => $companyState]);
        $author = $this->employer($company, 'author-'.Str::random(5).'@example.com');
        $colleague = $this->employer($company, 'colleague-'.Str::random(5).'@example.com');
        $otherCompany = Company::create(['name' => 'Other '.Str::random(5), 'approval_status' => 'approved']);
        $otherEmployer = $this->employer($otherCompany, 'other-'.Str::random(5).'@example.com');
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create([
            'company_id' => $company->id, 'title' => 'Engineer', 'description' => 'Build APIs',
            'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'status' => 'open', 'published_at' => now(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id, 'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $this->statusId($status), 'consent_to_share_profile' => true,
        ]);

        return [$company, $author, $colleague, $otherEmployer, $application, $candidate];
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user;
    }

    private function note(JobApplication $application, User $author, string $body, string $createdAt = '2026-08-10T10:00:00Z'): ApplicationInternalNote
    {
        return ApplicationInternalNote::forceCreate([
            'job_application_id' => $application->id, 'author_user_id' => $author->id,
            'body' => $body, 'version' => 1, 'created_at' => Carbon::parse($createdAt), 'updated_at' => Carbon::parse($createdAt),
        ]);
    }

    private function statusId(string $slug): int
    {
        return ApplicationStatus::where('slug', $slug)->value('id');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
