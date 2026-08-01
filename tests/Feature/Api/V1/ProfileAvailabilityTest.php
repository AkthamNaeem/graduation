<?php

namespace Tests\Feature\Api\V1;

use App\Enums\JobSeekerAvailabilityStatus;
use App\Enums\UserRole;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProfileAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_migration_is_nullable_and_reversible(): void
    {
        [$user, $profile] = $this->candidate();
        $this->assertTrue(Schema::hasColumns('job_seeker_profiles', ['availability_status', 'available_from']));
        $this->assertNull(DB::table('job_seeker_profiles')->where('id', $profile->id)->value('availability_status'));
        $this->assertNull(DB::table('job_seeker_profiles')->where('id', $profile->id)->value('available_from'));

        $migration = require database_path('migrations/2026_08_02_000001_add_availability_to_job_seeker_profiles.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('job_seeker_profiles', 'availability_status'));
        $this->assertFalse(Schema::hasColumn('job_seeker_profiles', 'available_from'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('job_seeker_profiles', ['availability_status', 'available_from']));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_available_now_not_available_and_clear_are_valid_partial_updates(): void
    {
        [$user, $profile] = $this->candidate();
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => 'available_now',
            'available_from' => null,
        ])->assertOk()
            ->assertJsonPath('data.availability_status', 'available_now')
            ->assertJsonPath('data.career_summary.availability.status.key', 'available_now')
            ->assertJsonPath('data.career_summary.availability.available_from', null)
            ->assertJsonPath('data.career_summary.availability.display_label', 'Available for work now');

        $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => 'not_available',
        ])->assertOk()->assertJsonPath('data.career_summary.availability.status.key', 'not_available');

        $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => null,
            'available_from' => null,
        ])->assertOk()
            ->assertJsonPath('data.career_summary.availability.status', null)
            ->assertJsonPath('data.career_summary.availability.display_label', null);

        $this->assertNull($profile->refresh()->availability_status);
    }

    public function test_available_from_date_is_iso_and_localized_in_english_and_arabic(): void
    {
        [$user] = $this->candidate();
        $date = now()->addMonth()->startOfMonth()->toDateString();
        $token = $this->tokenFor($user);

        $this->withHeader('Accept-Language', 'en')->withToken($token)
            ->putJson('/api/v1/profile', [
                'availability_status' => 'available_from_date',
                'available_from' => $date,
            ])->assertOk()
            ->assertJsonPath('data.available_from', $date)
            ->assertJsonPath('data.career_summary.availability.status.label', 'Available from a date')
            ->assertJsonPath('data.career_summary.availability.available_from', $date)
            ->assertJsonPath('data.career_summary.availability.display_label', fn ($label): bool => is_string($label) && str_starts_with($label, 'Available for work from '));

        $this->withHeader('Accept-Language', 'ar')->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.career_summary.availability.status.label', 'متاح من تاريخ')
            ->assertJsonPath('data.career_summary.availability.display_label', fn ($label): bool => is_string($label) && str_starts_with($label, 'متاح للعمل بدءًا من '));
    }

    public function test_validation_rejects_inconsistent_status_date_combinations_and_merged_partial_state(): void
    {
        [$user, $profile] = $this->candidate([
            'availability_status' => JobSeekerAvailabilityStatus::AVAILABLE_FROM_DATE,
            'available_from' => now()->addWeek()->toDateString(),
        ]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson('/api/v1/profile', ['available_from' => null])
            ->assertUnprocessable()->assertJsonPath('code', 'PROFILE_AVAILABILITY_DATE_REQUIRED');
        $this->withToken($token)->putJson('/api/v1/profile', ['availability_status' => 'available_now'])
            ->assertUnprocessable()->assertJsonPath('code', 'PROFILE_AVAILABILITY_DATE_NOT_ALLOWED');
        $this->withToken($token)->putJson('/api/v1/profile', ['availability_status' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('code', 'PROFILE_AVAILABILITY_STATUS_INVALID');
        $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => 'available_from_date',
            'available_from' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonPath('code', 'PROFILE_AVAILABILITY_DATE_IN_PAST');
        $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => 'not_available',
            'available_from' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonPath('code', 'PROFILE_AVAILABILITY_DATE_NOT_ALLOWED');

        $this->assertSame('available_from_date', $profile->refresh()->availability_status->value);
    }

    public function test_unset_availability_is_recommended_without_changing_completeness_percentage(): void
    {
        [$user] = $this->candidate();
        $token = $this->tokenFor($user);
        $before = $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $percentage = $before->json('data.profile_completeness.percentage');
        $this->assertContains('availability', array_column($before->json('data.profile_completeness.recommended_items'), 'key'));

        $after = $this->withToken($token)->putJson('/api/v1/profile', [
            'availability_status' => 'available_now',
            'available_from' => null,
        ])->assertOk();
        $this->assertSame($percentage, $after->json('data.profile_completeness.percentage'));
        $this->assertNotContains('availability', array_column($after->json('data.profile_completeness.recommended_items'), 'key'));
    }

    public function test_only_an_authenticated_job_seeker_can_update_availability(): void
    {
        $payload = ['availability_status' => 'available_now', 'available_from' => null];
        $this->putJson('/api/v1/profile', $payload)->assertUnauthorized();

        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->app['auth']->forgetGuards();
            $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', $payload)->assertForbidden();
        }
    }

    /** @param array<string, mixed> $profileAttributes */
    private function candidate(array $profileAttributes = []): array
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(array_merge(['user_id' => $user->id], $profileAttributes));

        return [$user, $profile];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('availability-test')->plainTextToken;
    }
}
