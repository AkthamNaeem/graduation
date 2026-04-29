<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_registration_creates_user_and_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'Jane Applicant',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Job seeker registered successfully.')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.role', UserRole::JOB_SEEKER->value)
            ->assertJsonPath('data.job_seeker_profile.user_id', 1);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::JOB_SEEKER->value,
        ]);

        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => 1,
        ]);
    }

    public function test_job_seeker_registration_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/register/job-seeker', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['name', 'email', 'password'],
            ]);
    }

    public function test_employer_registration_creates_company_and_profile_relationships(): void
    {
        $response = $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'Evan Employer',
            'email' => 'evan@example.com',
            'company_name' => 'Talent Forge',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', UserRole::EMPLOYER->value)
            ->assertJsonPath('data.employer_profile.company.name', 'Talent Forge');

        $user = User::query()->where('email', 'evan@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Talent Forge')->firstOrFail();

        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_employer_registration_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'Evan Employer',
            'email' => 'evan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['company_name'],
            ]);
    }

    public function test_login_returns_token_and_user_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'role' => UserRole::JOB_SEEKER,
        ]);

        JobSeekerProfile::create(['user_id' => $user->id]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'login@example.com')
            ->assertJsonPath('data.user.job_seeker_profile.user_id', $user->id);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials_with_standard_error_envelope(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'role' => UserRole::JOB_SEEKER,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['email'],
            ]);
    }

    public function test_authenticated_user_endpoint_returns_nested_profile_data(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);

        $user = User::factory()->create([
            'email' => 'me@example.com',
            'role' => UserRole::EMPLOYER,
        ]);

        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.employer_profile.company.name', 'Acme Hiring Co.');
    }

    public function test_authenticated_user_endpoint_requires_a_valid_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_revokes_current_access_token(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
        ]);

        JobSeekerProfile::create(['user_id' => $user->id]);

        $token = $user->createToken('test-token')->plainTextToken;

        $logoutResponse = $this->withToken($token)->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }
}
