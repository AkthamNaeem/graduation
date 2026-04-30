<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\Skill;
use App\Models\Test as RecruitmentTest;
use App\Models\User;
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
            ->patchJson("/api/v1/admin/companies/{$company->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$company->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected');
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
                'name' => 'Laravel',
                'slug' => 'laravel',
            ])
            ->assertJsonValidationErrors(['name', 'slug']);
    }

    public function test_admin_can_crud_tests_without_breaking_catalog_routes(): void
    {
        $admin = $this->admin();

        $createResponse = $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/tests', [
                'title' => 'Backend Assessment',
                'duration_minutes' => 75,
                'max_score' => 100,
                'passing_score' => 70,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Backend Assessment');

        $testId = $createResponse->json('data.id');

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
