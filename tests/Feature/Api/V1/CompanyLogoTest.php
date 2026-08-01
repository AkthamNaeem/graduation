<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_can_upload_replace_and_remove_a_valid_logo(): void
    {
        Storage::fake('public');
        [$owner, $company] = $this->ownerAndCompany();
        $this->authenticate($owner);

        $this->post('/api/v1/company', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJson(fn ($json) => $json->whereType('data.logo_url', 'string')->etc());

        $firstPath = $company->refresh()->logo_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->post('/api/v1/company', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('replacement.webp', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $secondPath = $company->refresh()->logo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->putJson('/api/v1/company', ['remove_logo' => true])
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_empty_or_unsupported_company_logo_is_rejected(): void
    {
        Storage::fake('public');
        [$owner] = $this->ownerAndCompany();
        $this->authenticate($owner);

        $this->post('/api/v1/company', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->create('logo.svg', 0, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    /** @return array{User, Company} */
    private function ownerAndCompany(): array
    {
        $company = Company::create(['name' => 'Logo Company', 'approval_status' => 'approved']);
        $owner = User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => UserStatus::ACTIVE,
        ]);
        EmployerProfile::create(['user_id' => $owner->id, 'company_id' => $company->id]);

        return [$owner->load('employerProfile.company'), $company];
    }

    private function authenticate(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('company-logo-test')->plainTextToken);
    }
}
