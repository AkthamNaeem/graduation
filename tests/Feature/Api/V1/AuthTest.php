<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_registration_requires_terms_accepted(): void
    {
        $response = $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'Jane Applicant',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);
    }

    public function test_employer_registration_requires_terms_accepted(): void
    {
        $response = $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'Evan Employer',
            'email' => 'evan@example.com',
            'company_name' => 'Talent Forge',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'Duplicate Applicant',
            'email' => 'duplicate@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_job_seeker_registration_creates_user_and_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'Jane Applicant',
            'email' => 'jane@example.com',
            'phone' => '+1 555 0100',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Job seeker registered successfully.')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.role', UserRole::JOB_SEEKER->value)
            ->assertJsonPath('data.status', UserStatus::ACTIVE->value)
            ->assertJsonPath('data.job_seeker_profile.phone', '+1 555 0100');

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::JOB_SEEKER->value,
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => $user->id,
            'phone' => '+1 555 0100',
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
                'errors' => ['name', 'email', 'password', 'terms_accepted'],
            ]);
    }

    public function test_employer_registration_creates_company_and_profile_relationships(): void
    {
        $response = $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'Evan Employer',
            'email' => 'evan@example.com',
            'company_name' => 'Talent Forge',
            'company_website' => 'https://talentforge.example.com',
            'phone' => '+1 555 0200',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', UserRole::EMPLOYER->value)
            ->assertJsonPath('data.status', UserStatus::ACTIVE->value)
            ->assertJsonPath('data.employer_profile.phone', '+1 555 0200')
            ->assertJsonPath('data.employer_profile.company.name', 'Talent Forge')
            ->assertJsonPath('data.employer_profile.company.website', 'https://talentforge.example.com');

        $user = User::query()->where('email', 'evan@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Talent Forge')->firstOrFail();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'website' => 'https://talentforge.example.com',
        ]);

        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'phone' => '+1 555 0200',
        ]);
    }

    public function test_employer_registration_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'Evan Employer',
            'email' => 'evan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['company_name'],
            ]);
    }

    public function test_login_succeeds_with_valid_active_user(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'role' => UserRole::JOB_SEEKER,
            'status' => UserStatus::ACTIVE,
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

    public function test_login_fails_with_invalid_password(): void
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

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_blocked_for_non_active_user_and_does_not_create_token(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'role' => UserRole::JOB_SEEKER,
            'status' => UserStatus::SUSPENDED,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your account is not active. Please contact support.')
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_returns_authenticated_user_with_role_and_profile(): void
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
            ->assertJsonPath('data.role', UserRole::EMPLOYER->value)
            ->assertJsonPath('data.employer_profile.company.name', 'Acme Hiring Co.');
    }

    public function test_authenticated_user_endpoint_requires_a_valid_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_deletes_current_access_token(): void
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

    public function test_forgot_password_returns_generic_success_for_existing_and_non_existing_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@example.com']);

        $existingResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $missingResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        $existingResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');

        $missingResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_works_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);
        $token = Password::createToken($user);
        $user->createToken('old-token');

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password reset successfully.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid or expired password reset token.')
            ->assertJsonValidationErrors(['token']);
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('current-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Current password is incorrect.')
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_change_password_succeeds_with_correct_current_password(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-token')->plainTextToken;
        $otherToken = $user->createToken('other-token')->plainTextToken;

        $response = $this->withToken($currentToken)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password changed successfully.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull(PersonalAccessToken::findToken($currentToken));
        $this->assertNull(PersonalAccessToken::findToken($otherToken));
    }

    public function test_logout_all_deletes_all_tokens(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-token')->plainTextToken;
        $otherToken = $user->createToken('other-token')->plainTextToken;

        $response = $this->withToken($currentToken)->postJson('/api/v1/auth/logout-all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logged out from all devices successfully.');

        $this->assertNull(PersonalAccessToken::findToken($currentToken));
        $this->assertNull(PersonalAccessToken::findToken($otherToken));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
