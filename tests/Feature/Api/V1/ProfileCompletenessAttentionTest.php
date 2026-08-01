<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileCompletenessAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_returns_complete_contract_and_latest_pending_cv_attention_in_english(): void
    {
        [$user, $profile] = $this->completeProfile();
        $this->cv($user, [
            'status' => 'parsed',
            'confirmed_at' => now()->subMinute(),
        ]);
        $pending = $this->cv($user, ['status' => 'processing']);

        $response = $this->withHeader('Accept-Language', 'en')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.profile_completeness.percentage', 100)
            ->assertJsonPath('data.profile_completeness.is_complete', true)
            ->assertJsonPath('data.profile_completeness.completed_items_count', 7)
            ->assertJsonPath('data.profile_completeness.missing_items_count', 0)
            ->assertJsonCount(3, 'data.profile_completeness.recommended_items')
            ->assertJsonPath('data.profile_completeness.recommended_items.0.key', 'github_link')
            ->assertJsonPath('data.attention_items.0.type.key', 'cv_processing')
            ->assertJsonPath('data.attention_items.0.type.label', 'CV processing')
            ->assertJsonPath('data.attention_items.0.title', 'We are processing your CV')
            ->assertJsonPath('data.attention_items.0.description', 'Your CV is being processed. This usually takes only a few moments.')
            ->assertJsonPath('data.attention_items.0.severity.key', 'info')
            ->assertJsonPath('data.attention_items.0.severity.label', 'Information')
            ->assertJsonPath('data.attention_items.0.action', null)
            ->assertJsonPath('data.attention_items.0.target.type', 'cv')
            ->assertJsonPath('data.attention_items.0.target.id', $pending->id)
            ->assertJsonCount(1, 'data.attention_items');

        $payload = $response->getContent();
        $this->assertStringNotContainsString('error_message', $payload);
        $this->assertStringNotContainsString('parsed_json', $payload);
        $this->assertStringNotContainsString('raw_text', $payload);
        $this->assertStringNotContainsString('confidence_score', $payload);
        $this->assertSame($profile->id, $response->json('data.identity.profile_id'));
    }

    public function test_incomplete_profile_returns_arabic_labels_and_next_item_action(): void
    {
        $user = User::factory()->create([
            'name' => '',
            'role' => UserRole::JOB_SEEKER,
        ]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        $this->withHeader('Accept-Language', 'ar')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.profile_completeness.percentage', 0)
            ->assertJsonPath('data.profile_completeness.next_item.key', 'basic_information')
            ->assertJsonPath('data.profile_completeness.next_item.label', 'أكمل معلوماتك الأساسية')
            ->assertJsonPath('data.attention_items.0.type.key', 'profile_incomplete')
            ->assertJsonPath('data.attention_items.0.title', 'ملفك الشخصي غير مكتمل')
            ->assertJsonPath('data.attention_items.0.description', 'أكمل العنصر التالي الناقص لتحسين ملفك الشخصي.')
            ->assertJsonPath('data.attention_items.0.severity.key', 'info')
            ->assertJsonPath('data.attention_items.0.severity.label', 'معلومة')
            ->assertJsonPath('data.attention_items.0.action.type.key', 'complete_profile')
            ->assertJsonPath('data.attention_items.0.action.type.label', 'إكمال الملف الشخصي')
            ->assertJsonPath('data.attention_items.0.action.target.type', 'profile_section')
            ->assertJsonPath('data.attention_items.0.action.target.value', 'basic_information')
            ->assertJsonCount(1, 'data.attention_items');
    }

    public function test_differences_attention_exposes_only_safe_pending_change_count(): void
    {
        [$user, $profile] = $this->completeProfile();
        $cv = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        ProfileChangeSuggestion::create([
            'user_id' => $user->id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'new_value' => ['summary' => 'Sensitive proposed content'],
            'confidence_score' => 0.97,
            'reason' => 'Internal parsing reason',
        ]);

        $response = $this->withHeader('Accept-Language', 'en')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.attention_items.0.type.key', 'cv_differences_review_required')
            ->assertJsonPath('data.attention_items.0.title', 'New profile changes were found')
            ->assertJsonPath('data.attention_items.0.description', 'Review the suggested changes before applying them to your profile.')
            ->assertJsonPath('data.attention_items.0.severity.label', 'Warning')
            ->assertJsonPath('data.attention_items.0.action.type.key', 'review_cv_changes')
            ->assertJsonPath('data.attention_items.0.action.type.label', 'Review CV changes')
            ->assertJsonPath('data.attention_items.0.action.target.type', 'cv_review')
            ->assertJsonPath('data.attention_items.0.action.target.id', $cv->id)
            ->assertJsonPath('data.attention_items.0.meta.changes_count', 1);

        $payload = $response->getContent();
        $this->assertStringNotContainsString('Sensitive proposed content', $payload);
        $this->assertStringNotContainsString('Internal parsing reason', $payload);
        $this->assertStringNotContainsString('confidence_score', $payload);
    }

    public function test_home_and_profile_use_the_same_completeness_percentage(): void
    {
        [$user] = $this->completeProfile();
        $this->cv($user, [
            'status' => 'parsed',
            'confirmed_at' => now(),
        ]);
        $token = $this->tokenFor($user);

        $profilePercentage = $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->json('data.profile_completeness.percentage');
        $homePercentage = $this->withToken($token)
            ->getJson('/api/v1/home')
            ->assertOk()
            ->json('data.profile_completeness.percentage');

        $this->assertSame(100, $profilePercentage);
        $this->assertSame($profilePercentage, $homePercentage);
    }

    public function test_profile_query_count_stays_bounded_as_pending_suggestions_grow(): void
    {
        [$user, $profile] = $this->completeProfile();
        $cv = $this->cv($user, [
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
        ]);
        $this->suggestion($user, $profile, $cv, 1);
        $token = $this->tokenFor($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $singleCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 20) as $number) {
            $this->suggestion($user, $profile, $cv, $number);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $singleCount);
        $this->assertLessThanOrEqual($singleCount + 1, $manyCount);
    }

    /** @return array{User, JobSeekerProfile} */
    private function completeProfile(): array
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create([
            'user_id' => $user->id,
            'phone' => '+963111',
            'headline' => 'Backend Developer',
            'summary' => 'Laravel developer',
            'location' => 'Damascus',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Developer',
            'company_name' => 'Workey',
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
        ]);
        foreach (['PHP', 'Laravel', 'SQL'] as $name) {
            $skill = Skill::create(['name' => $name, 'slug' => strtolower($name)]);
            $profile->skills()->attach($skill->id);
        }

        return [$user, $profile];
    }

    /** @param array<string, mixed> $overrides */
    private function cv(User $user, array $overrides = []): CVFile
    {
        return CVFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cvs/resume.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'processing',
        ], $overrides));
    }

    private function suggestion(User $user, JobSeekerProfile $profile, CVFile $cv, int $number): void
    {
        ProfileChangeSuggestion::create([
            'user_id' => $user->id,
            'cv_file_id' => $cv->id,
            'job_seeker_profile_id' => $profile->id,
            'entity_type' => ProfileChangeSuggestion::ENTITY_PROFILE,
            'suggestion_type' => ProfileChangeSuggestion::TYPE_UPDATE,
            'status' => ProfileChangeSuggestion::STATUS_PENDING,
            'new_value' => ['headline' => "Suggestion {$number}"],
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
