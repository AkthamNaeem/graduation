<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\Test as RecruitmentTest;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_require_admin_role(): void
    {
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);

        $this->getJson('/api/v1/admin/users')
            ->assertUnauthorized();

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_show_and_update_users(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'name' => 'Candidate User',
            'role' => UserRole::JOB_SEEKER,
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 1);

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/users/{$user->id}/role", [
                'role' => UserRole::EMPLOYER->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::EMPLOYER->value);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'suspended',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => UserRole::EMPLOYER->value,
            'status' => 'suspended',
        ]);
    }

    public function test_admin_can_filter_users_and_activate_or_suspend_user(): void
    {
        $admin = $this->admin();
        $candidate = User::factory()->create([
            'name' => 'Filtered Candidate',
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        User::factory()->create([
            'name' => 'Filtered Employer',
            'role' => UserRole::EMPLOYER,
            'status' => 'suspended',
        ]);
        $candidateToken = $candidate->createToken('candidate-token')->plainTextToken;

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users?search=Candidate&role=job_seeker&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $candidate->id);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/users/{$candidate->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $candidate->id,
        ]);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/users/{$candidate->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.suspended',
            'entity_type' => User::class,
            'entity_id' => $candidate->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.activated',
            'entity_type' => User::class,
            'entity_id' => $candidate->id,
        ]);
        $this->assertNotEmpty($candidateToken);
    }

    public function test_admin_can_list_approve_and_reject_companies(): void
    {
        $admin = $this->admin();
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);

        EmployerProfile::create([
            'user_id' => $employer->id,
            'company_id' => $company->id,
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/companies')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Acme Hiring Co.');

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/companies?approval_status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJsonPath('data.counts.employer_users', 1);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected');
    }

    public function test_admin_can_suspend_company_and_company_status_changes_are_audited(): void
    {
        $admin = $this->admin();
        $company = Company::create(['name' => 'Audit Co.']);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected');

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'suspended');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.approved',
            'entity_type' => Company::class,
            'entity_id' => $company->id,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.rejected',
            'entity_type' => Company::class,
            'entity_id' => $company->id,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.suspended',
            'entity_type' => Company::class,
            'entity_id' => $company->id,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_list_and_filter_audit_logs(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->create(['role' => UserRole::EMPLOYER]);

        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => 'job.published',
            'entity_type' => 'job',
            'entity_id' => 10,
        ]);
        AuditLog::create([
            'actor_user_id' => $admin->id,
            'action' => 'company.approved',
            'entity_type' => Company::class,
            'entity_id' => 20,
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 1);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/audit-logs?action=job.published')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.action', 'job.published');
    }

    public function test_non_admin_cannot_list_audit_logs(): void
    {
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_crud_skills(): void
    {
        $admin = $this->admin();

        $createResponse = $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/skills', [
                'name' => 'Laravel',
                'slug' => 'laravel',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Laravel');

        $skillId = $createResponse->json('data.id');

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/skills')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $skillId);

        $this->withToken($this->tokenFor($admin))
            ->putJson("/api/v1/admin/skills/{$skillId}", [
                'name' => 'Advanced Laravel',
                'slug' => 'advanced-laravel',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'advanced-laravel');

        $this->withToken($this->tokenFor($admin))
            ->deleteJson("/api/v1/admin/skills/{$skillId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('skills', ['id' => $skillId]);
    }

    public function test_admin_skill_validation_enforces_unique_fields(): void
    {
        $admin = $this->admin();

        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);

        $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/skills', [
                'name' => 'laravel',
                'slug' => 'laravel',
            ])
            ->assertJsonValidationErrors(['name', 'slug']);
    }

    public function test_used_skill_is_not_hard_deleted(): void
    {
        $admin = $this->admin();
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);

        $profile->skills()->attach($skill->id);

        $this->withToken($this->tokenFor($admin))
            ->deleteJson("/api/v1/admin/skills/{$skill->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('skills', ['id' => $skill->id]);
    }

    public function test_non_admin_cannot_access_reports(): void
    {
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/admin/reports/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_access_overview_report_with_expected_keys(): void
    {
        $admin = $this->admin();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'users' => ['total', 'by_role', 'by_status'],
                    'companies' => ['total', 'by_approval_status'],
                    'jobs' => ['total', 'by_status'],
                    'applications' => ['total', 'by_status'],
                    'tests' => ['total', 'assignments', 'attempts'],
                    'interviews' => ['total', 'by_status'],
                    'notifications' => ['total', 'unread'],
                    'cv_files' => ['total', 'by_status'],
                    'cv_parsing_results' => ['success', 'failed'],
                    'audit_logs' => ['count'],
                ],
            ]);
    }

    public function test_admin_reports_return_application_job_and_cv_counts(): void
    {
        $this->seed(ApplicationStatusSeeder::class);

        $admin = $this->admin();
        $company = Company::create(['name' => 'Reports Co.', 'approval_status' => 'approved']);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Developer',
            'description' => 'Build APIs',
            'employment_type' => 'full_time',
            'experience_level' => 'mid',
            'status' => 'open',
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => User::factory()->create(['role' => UserRole::JOB_SEEKER])->id,
        ]);
        JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'accepted')->value('id'),
        ]);
        $cvFile = CVFile::create([
            'user_id' => $profile->user_id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cv/resume.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'status' => 'parsed',
        ]);
        CVParsingResult::create([
            'cv_file_id' => $cvFile->id,
            'raw_text' => 'Laravel developer',
            'parsed_json' => ['skills' => ['Laravel']],
        ]);
        ProfileChangeSuggestion::create([
            'user_id' => $profile->user_id,
            'cv_file_id' => $cvFile->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_SKILL,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_ADD,
            'status' => ProfileChangeSuggestion::STATUS_ACCEPTED,
            'new_value' => ['name' => 'Laravel'],
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/reports/applications')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_status.accepted', 1);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/reports/jobs')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_status.open', 1);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/reports/cv-parsing')
            ->assertOk()
            ->assertJsonPath('data.total_uploaded_cvs', 1)
            ->assertJsonPath('data.parsed_count', 1)
            ->assertJsonPath('data.suggestions_accepted', 1);
    }

    public function test_admin_can_crud_tests_without_breaking_catalog_routes(): void
    {
        $admin = $this->admin();
        $company = Company::create(['name' => 'Admin Catalog Co.', 'approval_status' => 'approved']);

        $createResponse = $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/tests', [
                'company_id' => $company->id,
                'title' => 'Backend Assessment',
                'duration_minutes' => 75,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Backend Assessment');

        $testId = $createResponse->json('data.id');
        RecruitmentTest::findOrFail($testId)->questions()->create([
            'question_text' => 'Admin scoreable question',
            'question_type' => 'short_text',
            'order_index' => 1,
            'points' => 100,
            'is_required' => false,
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/tests')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $testId);

        $this->withToken($this->tokenFor($admin))
            ->putJson("/api/v1/admin/tests/{$testId}", [
                'title' => 'Senior Backend Assessment',
                'passing_score' => 80,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Senior Backend Assessment');

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/tests')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $testId);

        $this->withToken($this->tokenFor($admin))
            ->deleteJson("/api/v1/admin/tests/{$testId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('tests', ['id' => $testId]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'email' => 'admin@example.com',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
