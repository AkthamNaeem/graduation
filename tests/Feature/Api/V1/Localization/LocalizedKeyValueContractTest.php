<?php

namespace Tests\Feature\Api\V1\Localization;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Enums\JobWorkMode;
use App\Enums\ScreeningQuestionType;
use App\Enums\TestQuestionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\Api\V1\ApplicationStatusResource;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\CVFileResource;
use App\Http\Resources\Api\V1\InterviewResource;
use App\Http\Resources\Api\V1\JobScreeningQuestionResource;
use App\Http\Resources\Api\V1\ProfileChangeSuggestionResource;
use App\Http\Resources\Api\V1\TestQuestionResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\JobScreeningQuestion;
use App\Models\ProfileChangeSuggestion;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\AdminReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocalizedKeyValueContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_api_keeps_keys_and_free_text_stable_while_values_follow_accept_language(): void
    {
        $employer = User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => UserStatus::ACTIVE,
        ]);
        $company = Company::create([
            'name' => 'Localized Contract Co.',
            'approval_status' => 'approved',
        ]);
        EmployerProfile::create([
            'user_id' => $employer->id,
            'company_id' => $company->id,
            'company_role' => CompanyRole::OWNER,
            'membership_status' => CompanyMembershipStatus::ACTIVE,
        ]);
        $token = $employer->createToken('localized-contract')->plainTextToken;

        $created = $this->withToken($token)
            ->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/jobs', [
                'title' => 'Backend Engineer دمشق',
                'description' => 'User-authored description remains unchanged.',
                'requirements' => 'Laravel',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'work_mode' => JobWorkMode::REMOTE->value,
                'location' => 'Damascus, Syria',
            ])
            ->assertCreated()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.employment_type.key', 'full_time')
            ->assertJsonPath('data.experience_level.key', 'mid_level');

        $jobId = $created->json('data.id');
        $this->assertDatabaseHas('job_postings', [
            'id' => $jobId,
            'employment_type' => 'full_time',
            'experience_level' => 'mid_level',
        ]);

        $english = $this->withToken($token)
            ->withHeader('Accept-Language', 'en-US')
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->json('data');
        $arabic = $this->withToken($token)
            ->withHeader('Accept-Language', 'ar-SY')
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->json('data');

        foreach ([
            'employment_type',
            'experience_level',
            'work_mode',
            'status',
        ] as $field) {
            $this->assertSame($english[$field]['key'], $arabic[$field]['key'], $field);
            $this->assertNotSame($english[$field]['value'], $arabic[$field]['value'], $field);
        }

        foreach (['title', 'description', 'location'] as $field) {
            $this->assertSame($english[$field], $arabic[$field], $field);
        }
    }

    public function test_major_resource_families_emit_bilingual_key_value_objects(): void
    {
        $request = Request::create('/api/v1/localization-contract', 'GET');
        $resources = [
            'user' => new UserResource(new User([
                'role' => UserRole::JOB_SEEKER,
                'status' => UserStatus::ACTIVE,
            ])),
            'company' => new CompanyResource(new Company([
                'approval_status' => 'approved',
            ])),
            'application_status' => new ApplicationStatusResource(new ApplicationStatus([
                'name' => 'Under Review',
                'slug' => 'under_review',
            ])),
            'cv' => new CVFileResource(new CVFile([
                'status' => 'parsed',
                'review_mode' => 'profile_sync',
                'review_status' => 'comparison_pending',
            ])),
            'suggestion' => new ProfileChangeSuggestionResource(
                (new ProfileChangeSuggestion([
                    'entity_type' => 'experience',
                    'suggestion_type' => 'merge',
                    'status' => 'pending',
                    'source' => 'cv_parsed',
                ]))->setRelation('cvFile', null),
            ),
            'screening_question' => new JobScreeningQuestionResource(new JobScreeningQuestion([
                'question_type' => ScreeningQuestionType::SINGLE_CHOICE,
            ])),
            'test_question' => new TestQuestionResource(new TestQuestion([
                'question_type' => TestQuestionType::MULTIPLE_CHOICE,
            ])),
            'interview' => new InterviewResource(new Interview([
                'interview_type' => 'technical',
                'interview_mode' => 'online',
                'status' => 'scheduled',
                'candidate_attendance_status' => 'pending',
            ])),
        ];

        app()->setLocale('en');
        $english = collect($resources)
            ->map(fn ($resource): array => $resource->resolve($request))
            ->all();
        app()->setLocale('ar');
        $arabic = collect($resources)
            ->map(fn ($resource): array => $resource->resolve($request))
            ->all();

        $fields = [
            ['user', 'role'],
            ['user', 'status'],
            ['company', 'approval_status'],
            ['application_status', null],
            ['cv', 'parsing_status'],
            ['cv', 'review_mode'],
            ['cv', 'review_status'],
            ['cv', 'next_action'],
            ['suggestion', 'entity_type'],
            ['suggestion', 'suggestion_type'],
            ['suggestion', 'status'],
            ['suggestion', 'source'],
            ['suggestion', 'display_group'],
            ['screening_question', 'question_type'],
            ['test_question', 'question_type'],
            ['interview', 'interview_type'],
            ['interview', 'interview_mode'],
            ['interview', 'status'],
            ['interview', 'candidate_attendance_status'],
        ];

        foreach ($fields as [$resource, $field]) {
            $englishValue = $field === null
                ? ['key' => $english[$resource]['key'], 'value' => $english[$resource]['value']]
                : $english[$resource][$field];
            $arabicValue = $field === null
                ? ['key' => $arabic[$resource]['key'], 'value' => $arabic[$resource]['value']]
                : $arabic[$resource][$field];

            $this->assertSame($englishValue['key'], $arabicValue['key'], "{$resource}.{$field}");
            $this->assertNotSame($englishValue['value'], $arabicValue['value'], "{$resource}.{$field}");
        }
    }

    public function test_admin_report_distributions_keep_keys_and_counts_stable_across_locales(): void
    {
        User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => UserStatus::ACTIVE,
        ]);
        $reports = app(AdminReportService::class);

        app()->setLocale('en');
        $english = collect($reports->overview()['users']['by_role'])
            ->firstWhere('key', UserRole::EMPLOYER->value);
        app()->setLocale('ar');
        $arabic = collect($reports->overview()['users']['by_role'])
            ->firstWhere('key', UserRole::EMPLOYER->value);

        $this->assertSame(
            ['key', 'value', 'count'],
            array_keys($english),
        );
        $this->assertSame($english['key'], $arabic['key']);
        $this->assertSame($english['count'], $arabic['count']);
        $this->assertNotSame($english['value'], $arabic['value']);
    }
}
