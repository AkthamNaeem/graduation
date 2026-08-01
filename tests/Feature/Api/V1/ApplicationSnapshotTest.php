<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ScreeningQuestionType;
use App\Enums\UserRole;
use App\Models\ApplicationSnapshot;
use App\Models\ApplicationStatus;
use App\Models\City;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\Education;
use App\Models\EmployerProfile;
use App\Models\Experience;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\ApplicationScreeningAnswerService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ApplicationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_submission_captures_complete_profile_answers_and_an_independent_cv_copy(): void
    {
        [$candidate, , $job, $cv] = $this->scenario();
        $question = JobScreeningQuestion::factory()->for($job)->create([
            'question_text' => 'Why Workey?',
            'question_type' => ScreeningQuestionType::SINGLE_CHOICE,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        $option = $question->options()->create(['option_text' => 'Mission', 'sort_order' => 1]);

        $response = $this->apply($candidate, $job, $cv, [[
            'question_id' => $question->id,
            'selected_option_ids' => [$option->id],
        ]])->assertCreated()
            ->assertJsonPath('data.snapshot_status.key', 'available')
            ->assertJsonPath('data.submitted_snapshot.schema_version', 1)
            ->assertJsonPath('data.submitted_snapshot.profile.identity.email', $candidate->email)
            ->assertJsonPath('data.submitted_snapshot.profile.identity.headline', 'Original headline')
            ->assertJsonPath('data.submitted_snapshot.profile.availability.status', 'available_from_date')
            ->assertJsonPath('data.submitted_snapshot.profile.experiences.0.job_title', 'Backend Engineer')
            ->assertJsonPath('data.submitted_snapshot.profile.education.0.institution', 'Damascus University')
            ->assertJsonPath('data.submitted_snapshot.profile.skills.0.name', 'Laravel')
            ->assertJsonPath('data.submitted_snapshot.answers.0.question_text', 'Why Workey?')
            ->assertJsonPath('data.submitted_snapshot.answers.0.answer.selected_options.0.option_text', 'Mission');

        $snapshot = ApplicationSnapshot::where('job_application_id', $response->json('data.id'))->firstOrFail();
        $this->assertNotSame($cv->stored_path, $snapshot->cv_stored_path);
        $this->assertSame(hash('sha256', '%PDF-original'), $snapshot->cv_checksum_sha256);
        $this->assertSame(strlen('%PDF-original'), $snapshot->cv_size_bytes);
        Storage::disk('local')->assertExists($snapshot->cv_stored_path);
        $this->assertSame('%PDF-original', Storage::disk('local')->get($snapshot->cv_stored_path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.snapshot_created', 'entity_id' => $snapshot->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.snapshot_file_copied', 'entity_id' => $snapshot->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.submitted', 'entity_id' => $response->json('data.id')]);
    }

    public function test_detail_and_download_remain_bound_to_submission_state_after_live_data_changes(): void
    {
        [$candidate, $employer, $job, $cv] = $this->scenario();
        $applicationId = $this->apply($candidate, $job, $cv)->assertCreated()->json('data.id');
        $snapshot = ApplicationSnapshot::where('job_application_id', $applicationId)->firstOrFail();

        $candidate->jobSeekerProfile->update(['headline' => 'Changed later', 'phone' => '+999']);
        $candidate->update(['name' => 'Changed Candidate']);
        Storage::disk('local')->put($cv->stored_path, '%PDF-replaced-live-file');
        $cv->update(['original_name' => 'changed.pdf', 'archived_at' => now()]);
        $cv->delete();

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.submitted_snapshot.profile.identity.name', 'Original Candidate')
            ->assertJsonPath('data.submitted_snapshot.profile.identity.headline', 'Original headline')
            ->assertJsonPath('data.selected_cv.original_name', 'original.pdf')
            ->assertJsonMissingPath('data.job_seeker_profile');

        $this->app['auth']->forgetGuards();
        $download = $this->withToken($this->tokenFor($candidate))
            ->get("/api/v1/applications/{$applicationId}/cv/download")
            ->assertOk()
            ->assertStreamedContent('%PDF-original');
        $this->assertStringContainsString('attachment;', (string) $download->headers->get('content-disposition'));
        $this->assertSame('%PDF-original', Storage::disk('local')->get($snapshot->cv_stored_path));
    }

    public function test_snapshot_pdf_preview_is_private_and_authorized_for_candidate_and_owning_company(): void
    {
        [$candidate, $employer, $job, $cv] = $this->scenario();
        $applicationId = $this->apply($candidate, $job, $cv)->assertCreated()->json('data.id');
        $url = "/api/v1/applications/{$applicationId}/cv/preview";

        $candidatePreview = $this->withToken($this->tokenFor($candidate))->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('accept-ranges', 'none')
            ->assertStreamedContent('%PDF-original');
        $this->assertStringContainsString('inline;', (string) $candidatePreview->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', (string) $candidatePreview->headers->get('cache-control'));

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($employer))->get($url)->assertOk();

        $otherCompany = Company::factory()->create(['approval_status' => 'approved']);
        $otherEmployer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $otherEmployer->id, 'company_id' => $otherCompany->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($otherEmployer))->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson($url)->assertUnauthorized();
    }

    public function test_docx_snapshot_rejects_preview_but_remains_downloadable(): void
    {
        [$candidate, , $job, $cv] = $this->scenario([
            'original_name' => 'resume.docx',
            'stored_path' => 'cv-files/'.Str::uuid().'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
        ], 'docx-original');
        $applicationId = $this->apply($candidate, $job, $cv)->assertCreated()->json('data.id');
        $token = $this->tokenFor($candidate);

        $this->withToken($token)->getJson("/api/v1/applications/{$applicationId}/cv/preview")
            ->assertStatus(415)
            ->assertJsonPath('code', 'APPLICATION_SNAPSHOT_CV_PREVIEW_NOT_SUPPORTED')
            ->assertJsonPath('errors.file.0', __('cv.preview_not_supported_hint'));

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($candidate))
            ->get("/api/v1/applications/{$applicationId}/cv/download")
            ->assertOk()
            ->assertStreamedContent('docx-original');
    }

    public function test_modern_submission_requires_the_confirmed_current_cv(): void
    {
        [$candidate, , $job] = $this->scenario();
        $current = $this->modernCV($candidate, 'current.pdf');
        $nonCurrent = $this->modernCV($candidate, 'older-confirmed.pdf');
        $candidate->jobSeekerProfile->update(['primary_cv_file_id' => $current->id]);

        $this->apply($candidate, $job, $nonCurrent)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'APPLICATION_CURRENT_CV_REQUIRED');

        $this->assertDatabaseCount('job_applications', 0);
        $this->assertDatabaseCount('application_snapshots', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('application-snapshots'));
    }

    public function test_failure_after_file_copy_rolls_back_rows_audits_and_snapshot_object(): void
    {
        [$candidate, , $job, $cv] = $this->scenario();
        $this->app->bind(ApplicationScreeningAnswerService::class, static fn () => new class extends ApplicationScreeningAnswerService
        {
            public function persistSnapshots(JobApplication $application, array $plan): void
            {
                throw new RuntimeException('Synthetic persistence failure.');
            }
        });

        $this->apply($candidate, $job, $cv)->assertStatus(500);

        $this->assertDatabaseCount('job_applications', 0);
        $this->assertDatabaseCount('application_snapshots', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'application.snapshot_created']);
        $this->assertSame([], Storage::disk('local')->allFiles('application-snapshots'));
        Storage::disk('local')->assertExists($cv->stored_path);
    }

    public function test_legacy_contract_and_backfill_are_safe_dry_run_scoped_and_idempotent(): void
    {
        [$candidate, , $job, $cv] = $this->scenario();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'selected_cv_file_id' => $cv->id,
            'application_status_id' => ApplicationStatus::where('slug', 'submitted')->value('id'),
            'consent_to_share_profile' => true,
            'screening_answers' => ['legacy_question' => 'legacy answer'],
        ]);

        $this->withToken($this->tokenFor($candidate))->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot_status.key', 'not_available')
            ->assertJsonPath('data.submitted_snapshot', null);

        $this->artisan('applications:backfill-snapshots', [
            '--dry-run' => true,
            '--application-id' => [$application->id],
            '--chunk' => 1,
        ])->assertSuccessful()->expectsOutputToContain('eligible=1 created=0');
        $this->assertDatabaseCount('application_snapshots', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('application-snapshots'));

        $this->artisan('applications:backfill-snapshots', [
            '--application-id' => [$application->id],
            '--chunk' => 1,
        ])->assertSuccessful()->expectsOutputToContain('eligible=1 created=1');

        $snapshot = $application->snapshot()->firstOrFail();
        $this->assertSame(ApplicationSnapshot::ORIGIN_BACKFILL, $snapshot->origin);
        $this->assertSame(ApplicationSnapshot::ACCURACY_BEST_AVAILABLE, $snapshot->accuracy);
        $this->assertSame(['legacy_question' => 'legacy answer'], $snapshot->application_answers_snapshot);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.snapshot_backfilled',
            'entity_id' => $snapshot->id,
            'actor_user_id' => null,
        ]);

        $this->artisan('applications:backfill-snapshots', [
            '--application-id' => [$application->id],
        ])->assertSuccessful()->expectsOutputToContain('eligible=0 created=0');
        $this->assertDatabaseCount('application_snapshots', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('application-snapshots'));
    }

    public function test_snapshot_model_rejects_mutation_and_direct_deletion(): void
    {
        [$candidate, , $job, $cv] = $this->scenario();
        $applicationId = $this->apply($candidate, $job, $cv)->assertCreated()->json('data.id');
        $snapshot = ApplicationSnapshot::where('job_application_id', $applicationId)->firstOrFail();

        try {
            $snapshot->update(['origin' => 'changed']);
            $this->fail('Snapshot update should have failed.');
        } catch (LogicException $exception) {
            $this->assertSame('Application snapshots are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $snapshot->delete();
    }

    /** @return array{User, User, JobPosting, CVFile} */
    private function scenario(array $cvOverrides = [], string $contents = '%PDF-original'): array
    {
        $company = Company::factory()->create(['approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create([
            'name' => 'Original Candidate',
            'email' => 'candidate-'.Str::uuid().'@example.com',
            'role' => UserRole::JOB_SEEKER,
        ]);
        $city = City::create([
            'code' => 'damascus-'.Str::lower(Str::random(6)),
            'country_code' => 'SY',
            'name_ar' => 'دمشق',
            'name_en' => 'Damascus',
            'is_active' => true,
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $candidate->id,
            'headline' => 'Original headline',
            'summary' => 'Original summary',
            'phone' => '+963111111',
            'location' => 'Damascus centre',
            'city_id' => $city->id,
            'availability_status' => 'available_from_date',
            'available_from' => now()->addMonth()->toDateString(),
            'portfolio_url' => 'https://example.com/portfolio',
            'linkedin_url' => 'https://linkedin.com/in/candidate',
            'github_url' => 'https://github.com/candidate',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Backend Engineer',
            'company_name' => 'Original Company',
            'start_date' => '2022-01-01',
            'is_current' => true,
            'source_type' => 'manual',
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
            'degree' => 'BSc',
            'source_type' => 'manual',
        ]);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel-'.Str::lower(Str::random(6))]);
        $profile->skills()->attach($skill->id, ['source_type' => 'manual']);
        $candidate->load('jobSeekerProfile');

        $job = JobPosting::factory()->for($company)->create(['status' => 'open', 'published_at' => now()]);
        $cv = CVFile::create(array_merge([
            'user_id' => $candidate->id,
            'original_name' => 'original.pdf',
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'status' => 'parsed',
        ], $cvOverrides));
        Storage::disk('local')->put($cv->stored_path, $contents);

        return [$candidate, $employer, $job, $cv];
    }

    private function apply(User $candidate, JobPosting $job, CVFile $cv, array $answers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($candidate))->postJson("/api/v1/jobs/{$job->id}/applications", [
            'selected_cv_file_id' => $cv->id,
            'consent_to_share_profile' => true,
            'screening_answers' => $answers,
        ]);
    }

    private function modernCV(User $candidate, string $name): CVFile
    {
        $contents = '%PDF-'.$name;
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => $name,
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now(),
        ]);
        Storage::disk('local')->put($cv->stored_path, $contents);

        return $cv;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
