<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobWorkModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_can_create_every_supported_work_mode(): void
    {
        $employer = $this->employer();
        $token = $this->tokenFor($employer);

        foreach ([
            ['on_site', 'Damascus'],
            ['hybrid', 'Damascus'],
            ['remote', null],
        ] as [$mode, $location]) {
            $payload = $this->payload($mode);
            if ($location !== null) {
                $payload['location'] = $location;
            }

            $this->withToken($token)->postJson('/api/v1/jobs', $payload)
                ->assertCreated()
                ->assertJsonPath('data.work_mode', $mode)
                ->assertJsonPath('data.location', $location)
                ->assertJsonPath('data.can_apply', false);
        }
    }

    public function test_location_and_enum_validation_are_enforced(): void
    {
        $token = $this->tokenFor($this->employer());

        foreach (['on_site', 'hybrid'] as $mode) {
            $this->withToken($token)->postJson('/api/v1/jobs', $this->payload($mode))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['location']);
        }

        $this->withToken($token)->postJson('/api/v1/jobs', $this->payload('virtual'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_mode']);
    }

    public function test_owner_can_update_work_mode_and_effective_location_is_validated(): void
    {
        $employer = $this->employer();
        $token = $this->tokenFor($employer);
        $jobId = $this->withToken($token)->postJson('/api/v1/jobs', $this->payload('remote'))
            ->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", ['work_mode' => 'hybrid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['location']);
        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", [
            'work_mode' => 'hybrid',
            'location' => 'Damascus',
        ])->assertOk()->assertJsonPath('data.work_mode', 'hybrid');
        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", ['location' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['location']);
    }

    public function test_public_work_mode_filter_preserves_approved_company_scope(): void
    {
        $employer = $this->employer()->load('employerProfile.company');
        foreach (['on_site', 'remote', 'hybrid'] as $mode) {
            JobPosting::create([
                'company_id' => $employer->employerProfile->company_id,
                'title' => ucfirst($mode).' Role',
                'description' => 'Build APIs.',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'work_mode' => $mode,
                'location' => $mode === 'remote' ? null : 'Damascus',
                'status' => 'open',
                'published_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/jobs?work_mode=remote')
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.work_mode', 'remote');
    }

    private function payload(string $mode): array
    {
        return [
            'title' => ucfirst($mode).' Backend Developer',
            'description' => 'Build recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => $mode,
        ];
    }

    private function employer(): User
    {
        $company = Company::create(['name' => 'Work Mode Co.', 'approval_status' => 'approved']);
        $user = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
