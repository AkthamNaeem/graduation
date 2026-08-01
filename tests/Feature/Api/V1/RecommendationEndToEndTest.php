<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Contracts\Recommendation\RecommendationResultCacheContract;
use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecommendationRun;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationEndToEndTest extends TestCase
{
    use BuildsRecommendationPersistenceScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecommendationPersistenceScenario();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecommendationPersistenceScenario();
        parent::tearDown();
    }

    public function test_recommendation_end_to_end_cold_cache_and_persistence_lifecycle(): void
    {
        [$user, $eligibleJobs, $sensitiveValues] = $this->canonicalFixture();
        $this->fakeSuccessfulRecommendationMl();
        $token = $user->createToken('phase17-e2e-'.Str::random(8))->plainTextToken;

        $cold = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recommended jobs retrieved successfully.')
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.recommendation_engine', 'ml_xgbranker')
            ->assertJsonPath('data.0.fallback_used', false);

        $this->assertSame(
            collect($eligibleJobs)->pluck('id')->sort()->values()->all(),
            collect($cold->json('data'))->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(range(1, 5), array_column($cold->json('data'), 'rank'));
        foreach ($cold->json('data') as $item) {
            $this->assertGreaterThanOrEqual(0, $item['score']);
            $this->assertLessThanOrEqual(100, $item['score']);
            foreach ($item['reasons'] as $reason) {
                $this->assertContains($reason['code'], [
                    'DOMAIN_ALIGNMENT',
                    'SKILLS_ALIGNMENT',
                    'EXPERIENCE_ALIGNMENT',
                    'EDUCATION_ALIGNMENT',
                    'WORK_MODE_ALIGNMENT',
                    'EMPLOYMENT_TYPE_ALIGNMENT',
                    'MISSING_REQUIRED_SKILLS',
                    'SAME_CITY',
                    'DIFFERENT_CITY',
                    'REMOTE_LOCATION_COMPATIBLE',
                    'LOCATION_DATA_MISSING',
                ]);
            }
        }
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 5);
        Http::assertSentCount(1);

        $cached = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $this->assertSame($cold->json('data'), $cached->json('data'));
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 5);
        Http::assertSentCount(1);

        Cache::flush();
        $persisted = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $this->assertSame($cold->json('data'), $persisted->json('data'));
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 5);
        Http::assertSentCount(1);

        $eligibility = app(RecommendationEligibilityProviderContract::class)
            ->eligibleJobs($user->fresh(), now());
        $context = app(RecommendationContextFingerprintContract::class)
            ->fingerprint($eligibility, true);
        $key = app(RecommendationResultCacheContract::class)->key(
            $user->jobSeekerProfile->id,
            $context,
            5,
        );
        $this->assertSame([
            'schema_version',
            'recommendation_run_id',
            'context_hash',
            'requested_limit',
            'expires_at',
        ], array_keys(Cache::get($key)));

        $publicBodies = $cold->getContent().$cached->getContent().$persisted->getContent();
        foreach ([...$sensitiveValues, self::ML_TOKEN, $token] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $publicBodies);
        }
        $this->assertStringNotContainsString('raw_score', $publicBodies);
        $this->assertStringNotContainsString('feature_group', $publicBodies);
        $this->assertStringNotContainsString('contribution', $publicBodies);

        $stored = RecommendationRun::firstOrFail()->load('items');
        $storedJson = $stored->toJson();
        foreach ([...$sensitiveValues, self::ML_TOKEN, $token] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $storedJson);
        }
        $this->assertStringNotContainsString('Builds private candidate systems.', $storedJson);
        $this->assertStringNotContainsString('Laravel', $storedJson);
    }

    public function test_recommendation_end_to_end_invalidation_and_eligibility(): void
    {
        [$user, $eligibleJobs] = $this->canonicalFixture();
        $this->fakeSuccessfulRecommendationMl();
        $token = $user->createToken('phase17-invalidation')->plainTextToken;

        $first = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $firstContextHash = RecommendationRun::firstOrFail()->context_hash;
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);

        $user->update([
            'name' => 'Changed Private Fixture Name',
            'email' => 'changed-private-phase17@example.test',
        ]);
        $user->jobSeekerProfile->update(['phone' => '+000000000000']);
        $unrelated = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $this->assertSame($first->json('data'), $unrelated->json('data'));
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);

        $user->jobSeekerProfile->update(['headline' => 'Changed scoring headline']);
        $profileMutation = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $this->assertDatabaseCount('recommendation_runs', 2);
        $this->assertNotSame(
            $firstContextHash,
            RecommendationRun::latest('id')->firstOrFail()->context_hash,
        );
        Http::assertSentCount(2);

        $eligibleJobs[0]->update(['description' => 'Changed ranking description.']);
        $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk();
        $this->assertDatabaseCount('recommendation_runs', 3);
        Http::assertSentCount(3);

        JobApplication::create([
            'job_posting_id' => $eligibleJobs[0]->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::firstOrFail()->id,
            'cover_letter' => null,
            'consent_to_share_profile' => true,
        ]);
        $excluded = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk()
            ->assertJsonCount(4, 'data');
        $this->assertNotContains(
            $eligibleJobs[0]->id,
            array_column($excluded->json('data'), 'id'),
        );
        $this->assertDatabaseCount('recommendation_runs', 4);
        Http::assertSentCount(4);
        $this->assertSame($first->json('data'), $profileMutation->json('data'));
    }

    public function test_recommendation_public_route_and_resource_contract_remain_frozen(): void
    {
        $route = Route::getRoutes()->getByName('v1.jobs.recommended');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/jobs/recommended', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame([
            'api',
            'auth:sanctum',
            'user.active',
            'company.approved',
        ], $route->middleware());

        $user = $this->recommendationUser();
        $job = $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $response = $this->withToken(
            $user->createToken('phase17-contract')->plainTextToken,
        )->getJson('/api/v1/jobs/recommended?limit=1');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertExactJsonStructure([
                'success',
                'message',
                'data' => [[
                    'id',
                    'company_id',
                    'title',
                    'department',
                    'description',
                    'responsibilities',
                    'requirements',
                    'benefits',
                    'employment_type',
                    'experience_level',
                    'education_level',
                    'location',
                    'city',
                    'work_mode',
                    'salary_min',
                    'salary_max',
                    'status',
                    'published_at',
                    'application_deadline',
                    'has_application_deadline',
                    'is_application_deadline_passed',
                    'is_accepting_applications',
                    'can_apply',
                    'company',
                    'skills',
                    'required_skills',
                    'nice_to_have_skills',
                    'created_at',
                    'updated_at',
                    'score',
                    'matching_score_version',
                    'breakdown',
                    'matched_skills',
                    'skill_breakdown',
                    'matched_required_skills',
                    'missing_required_skills',
                    'matched_nice_to_have_skills',
                    'location_match',
                    'reasons',
                    'rank',
                    'recommendation_engine',
                    'model_version',
                    'feature_schema_version',
                    'explanation_contract_version',
                    'fallback_used',
                ]],
            ]);
    }

    public function test_phase17_protected_baseline_entries_and_aggregate_are_valid(): void
    {
        $path = base_path('docs/ml-job-recommendation/PHASE_17_PROTECTED_BASELINE.json');
        $this->assertSame(
            'CB3959FF2550064CA4F7D82953A1E6E0A539A1C32A2BC97ABB1E64F09FDBCAC0',
            strtoupper(hash_file('sha256', $path)),
        );
        $this->assertSame(
            'F425D51C0094D2D2AAAFC220C02FE3DA4AA1796DDC59534F0F5E471A27995521',
            strtoupper(hash_file(
                'sha256',
                base_path('docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json'),
            )),
        );
        $baseline = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('phase-17-protected-baseline-v1', $baseline['baseline_version']);
        $this->assertSame(864, $baseline['file_count']);
        $this->assertSame(864, count($baseline['files']));
        $this->assertSame(
            'indeterminate',
            $baseline['historical_phase_16_mismatch_count'],
        );
        $this->assertFalse(collect($baseline['files'])->contains(
            fn (array $entry): bool => $entry['path']
                === 'docs/ml-job-recommendation/PHASE_17_PROTECTED_BASELINE.json',
        ));

        $records = [];
        $paths = [];
        $approvedPhase17Documentation = [
            // Approved localization-only API presentation, validation, and notification changes.
            'app/Http/Controllers/Api/V1/Admin/AdminReportController.php',
            'app/Http/Controllers/Api/V1/Admin/AdminSkillController.php',
            'app/Http/Controllers/Api/V1/Admin/AdminTestController.php',
            'app/Http/Controllers/Api/V1/Admin/AdminUserController.php',
            'app/Http/Controllers/Api/V1/Admin/AuditLogController.php',
            'app/Http/Controllers/Api/V1/Application/ApplicationInformationRequestController.php',
            'app/Http/Controllers/Api/V1/Application/ApplicationInternalNoteController.php',
            'app/Http/Controllers/Api/V1/Application/JobApplicationController.php',
            'app/Http/Controllers/Api/V1/CV/CVController.php',
            'app/Http/Controllers/Api/V1/Interview/InterviewController.php',
            'app/Http/Controllers/Api/V1/JobPosting/JobPostingController.php',
            'app/Http/Controllers/Api/V1/JobPosting/JobScreeningQuestionController.php',
            'app/Http/Controllers/Api/V1/Notification/NotificationController.php',
            'app/Http/Controllers/Api/V1/Profile/CompanyController.php',
            'app/Http/Controllers/Api/V1/Profile/EducationController.php',
            'app/Http/Controllers/Api/V1/Profile/EmployerProfileController.php',
            'app/Http/Controllers/Api/V1/Profile/ExperienceController.php',
            'app/Http/Controllers/Api/V1/Profile/ProfileController.php',
            'app/Http/Controllers/Api/V1/Profile/ProfileSkillController.php',
            'app/Http/Controllers/Api/V1/Profile/ProfileSuggestionController.php',
            'app/Http/Controllers/Api/V1/Skill/SkillController.php',
            'app/Http/Controllers/Api/V1/Test/TestAnswerController.php',
            'app/Http/Controllers/Api/V1/Test/TestAssignmentController.php',
            'app/Http/Controllers/Api/V1/Test/TestAssignmentDeadlineController.php',
            'app/Http/Controllers/Api/V1/Test/TestAttemptController.php',
            'app/Http/Controllers/Api/V1/Test/TestAttemptQuestionController.php',
            'app/Http/Controllers/Api/V1/Test/TestCatalogController.php',
            'app/Http/Controllers/Api/V1/Test/TestManualGradingController.php',
            'app/Http/Controllers/Api/V1/Test/TestQuestionController.php',
            'app/Http/Controllers/Api/V1/Test/TestRetakeController.php',
            'app/Http/Middleware/AdminMiddleware.php',
            'app/Http/Middleware/EnsureUserIsActive.php',
            'app/Http/Requests/Api/V1/Admin/StoreAdminSkillRequest.php',
            'app/Http/Requests/Api/V1/Admin/UpdateAdminSkillRequest.php',
            'app/Http/Requests/Api/V1/Application/Concerns/ValidatesInformationRequestItems.php',
            'app/Http/Requests/Api/V1/Application/InternalNote/InternalNoteRequest.php',
            'app/Http/Requests/Api/V1/Application/InternalNote/StoreApplicationInternalNoteRequest.php',
            'app/Http/Requests/Api/V1/Application/InternalNote/UpdateApplicationInternalNoteRequest.php',
            'app/Http/Requests/Api/V1/CV/UpdateCVReviewDraftRequest.php',
            'app/Http/Requests/Api/V1/JobPosting/Concerns/NormalizesJobSkillInput.php',
            'app/Http/Requests/Api/V1/JobPosting/IndexJobPostingRequest.php',
            'app/Http/Requests/Api/V1/Profile/AcceptProfileSuggestionRequest.php',
            'app/Http/Requests/Api/V1/Test/StoreTestCatalogRequest.php',
            'app/Http/Resources/Api/V1/ApplicationStatusHistoryResource.php',
            'app/Http/Resources/Api/V1/InterviewStatusHistoryResource.php',
            'app/Http/Resources/Api/V1/ProfileChangeSuggestionResource.php',
            'app/Http/Resources/Api/V1/RankedCandidateResource.php',
            'app/Http/Resources/Api/V1/RecommendedJobResource.php',
            'app/Http/Resources/Api/V1/TestAnswerGradingResource.php',
            'app/Http/Resources/Api/V1/TestAttemptResultResource.php',
            'app/Listeners/CreateApplicationInformationRequestCancelledNotification.php',
            'app/Listeners/CreateApplicationInformationRequestUpdatedNotification.php',
            'app/Listeners/CreateApplicationInformationRequestedNotification.php',
            'app/Listeners/CreateApplicationInformationRespondedNotification.php',
            'app/Listeners/CreateApplicationSubmittedNotifications.php',
            'app/Listeners/CreateInterviewAttendanceUpdatedNotification.php',
            'app/Listeners/CreateInterviewCancelledNotification.php',
            'app/Listeners/CreateInterviewCompletedNotification.php',
            'app/Listeners/CreateInterviewConfirmedNotification.php',
            'app/Listeners/CreateInterviewEvaluatedNotification.php',
            'app/Listeners/CreateInterviewNoShowNotifications.php',
            'app/Listeners/CreateInterviewRescheduledNotification.php',
            'app/Listeners/CreateInterviewScheduledNotification.php',
            'app/Listeners/CreateInterviewUpdatedNotification.php',
            'app/Listeners/CreateTestAssignedNotification.php',
            'app/Listeners/CreateTestAssignmentDeadlineExtendedNotification.php',
            'app/Listeners/CreateTestEvaluatedNotification.php',
            'app/Listeners/CreateTestRetakeGrantedNotification.php',
            'app/Listeners/CreateTestSubmittedNotification.php',
            'app/Services/ApplicationScreeningAnswerService.php',
            'app/Services/ApplicationWorkflowService.php',
            'app/Services/CVService.php',
            'app/Services/InterviewService.php',
            'app/Services/JobScreeningQuestionService.php',
            'app/Services/PrivateFileStorageService.php',
            'app/Services/ProfileSyncService.php',
            'app/Services/Recommendation/RecommendationEligibilityProvider.php',
            'app/Services/TestAnswerService.php',
            'app/Services/TestAttemptContentService.php',
            'app/Services/TestAttemptTimingService.php',
            'app/Services/TestGradingService.php',
            'app/Services/TestManualGradingService.php',
            'app/Services/TestRetakeService.php',
            'app/Services/TestScorePolicyService.php',
            'app/Services/TestService.php',
            'app/Support/ApiResponse.php',
            'app/Support/Recommendation/MlRecommendationResourceAdapter.php',
            'BACKEND_IMPLEMENTATION_REPORT.md',
            'README.md',
            'services/ml-recommendation/README.md',
            // Approved post-handover commit-safety portability remediations.
            'services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md',
            'services/ml-recommendation/data/baselines/v1/manifest.json',
            'services/ml-recommendation/src/smart_recruitment_ml/baselines/evaluator.py',
            // Approved post-handover provenance and Locked-Test-safety maintenance.
            'services/ml-recommendation/src/smart_recruitment_ml/training/trainer.py',
            'services/ml-recommendation/tests/test_model_artifacts.py',
            // Approved post-handover comprehensive demo-database implementation.
            'database/seeders/DatabaseSeeder.php',
            'database/seeders/SampleUserSeeder.php',
            'tests/Feature/Api/V1/JobPostingTest.php',
            // Approved temporary registration email-verification implementation.
            'app/Http/Controllers/Api/V1/Auth/AuthController.php',
            'app/Http/Controllers/Api/V1/Auth/RegistrationController.php',
            'app/Http/Requests/Api/V1/Auth/EmployerRegisterRequest.php',
            'app/Http/Requests/Api/V1/Auth/JobSeekerRegisterRequest.php',
            'app/Http/Requests/Api/V1/Auth/LoginRequest.php',
            'app/Http/Requests/Api/V1/Auth/ForgotPasswordRequest.php',
            'app/Http/Requests/Api/V1/Auth/ResetPasswordRequest.php',
            'app/Http/Resources/Api/V1/UserResource.php',
            'app/Models/User.php',
            'app/Providers/AppServiceProvider.php',
            'app/Services/Auth/AuthService.php',
            'app/Services/Auth/RegistrationService.php',
            'bootstrap/app.php',
            'postman/Smart Recruitment Platform - Web App.postman_collection.json',
            'postman/Smart Recruitment Platform - Mobile App.postman_collection.json',
            'postman/Smart Recruitment Platform - Environment.postman_environment.json',
            'routes/api/v1.php',
            'tests/Feature/Api/V1/AuthTest.php',
            'tests/Feature/Api/V1/RecommendationEndToEndTest.php',
            // Approved company membership, invitation, and role-authorization implementation.
            '.env.example',
            'app/Http/Controllers/Api/V1/Admin/AdminCompanyController.php',
            'app/Http/Middleware/EnsureCompanyApproved.php',
            'app/Http/Requests/Api/V1/Admin/Concerns/AuthorizesAdmin.php',
            'app/Http/Requests/Api/V1/JobPosting/Concerns/ResolvesJobPostingUser.php',
            'app/Http/Requests/Api/V1/JobPosting/MyJobPostingIndexRequest.php',
            'app/Http/Requests/Api/V1/JobPosting/StoreJobPostingRequest.php',
            'app/Http/Requests/Api/V1/Profile/Concerns/AuthorizesProfileRoles.php',
            'app/Http/Requests/Api/V1/Profile/UpdateCompanyRequest.php',
            'app/Http/Requests/Api/V1/Test/Concerns/AuthorizesTestCatalog.php',
            'app/Http/Resources/Api/V1/CompanyResource.php',
            'app/Http/Resources/Api/V1/EmployerProfileResource.php',
            'app/Models/Company.php',
            'app/Models/EmployerProfile.php',
            'app/Policies/ApplicationInformationRequestPolicy.php',
            'app/Policies/ApplicationInternalNotePolicy.php',
            'app/Policies/ApplicationTestAssignmentPolicy.php',
            'app/Policies/InterviewPolicy.php',
            'app/Policies/JobApplicationPolicy.php',
            'app/Policies/JobPostingPolicy.php',
            'app/Policies/TestAttemptPolicy.php',
            'app/Policies/TestPolicy.php',
            'app/Services/ApplicationInformationRequestService.php',
            'app/Services/ApplicationInternalNoteService.php',
            'app/Services/CompanyRecruitmentAccessService.php',
            'app/Services/JobPostingService.php',
            'app/Services/ProfileService.php',
            'database/seeders/DemoUsersSeeder.php',
            'tests/Feature/Api/V1/EmailVerificationOtpTest.php',
            'tests/Unit/FinalHandoverDocumentationTest.php',
        ];
        $approvedPhase17Documentation = array_merge($approvedPhase17Documentation, [
            // Approved localized key/value API presentation-contract changes.
            'app/Http/Requests/Api/V1/JobPosting/UpdateJobPostingRequest.php',
            'app/Http/Resources/Api/V1/ApplicationInformationRequestResource.php',
            'app/Http/Resources/Api/V1/ApplicationStatusResource.php',
            'app/Http/Resources/Api/V1/ApplicationTestAssignmentResource.php',
            'app/Http/Resources/Api/V1/AuditLogResource.php',
            'app/Http/Resources/Api/V1/CVFileResource.php',
            'app/Http/Resources/Api/V1/CVReviewResource.php',
            'app/Http/Resources/Api/V1/CandidateApplicationTestAssignmentResource.php',
            'app/Http/Resources/Api/V1/CandidateTestQuestionResource.php',
            'app/Http/Resources/Api/V1/EducationResource.php',
            'app/Http/Resources/Api/V1/ExperienceResource.php',
            'app/Http/Resources/Api/V1/InterviewEvaluationResource.php',
            'app/Http/Resources/Api/V1/InterviewResource.php',
            'app/Http/Resources/Api/V1/InterviewScheduleChangeResource.php',
            'app/Http/Resources/Api/V1/JobApplicationResource.php',
            'app/Http/Resources/Api/V1/JobApplicationScreeningQuestionResource.php',
            'app/Http/Resources/Api/V1/JobPostingResource.php',
            'app/Http/Resources/Api/V1/JobScreeningQuestionResource.php',
            'app/Http/Resources/Api/V1/SkillResource.php',
            'app/Http/Resources/Api/V1/TestAnswerResource.php',
            'app/Http/Resources/Api/V1/TestAssignmentSeriesResource.php',
            'app/Http/Resources/Api/V1/TestAttemptResource.php',
            'app/Http/Resources/Api/V1/TestQuestionResource.php',
            'app/Services/AdminReportService.php',
            'config/matching.php',
            'tests/Feature/Api/V1/AccountStateTest.php',
            'tests/Feature/Api/V1/AdminApiTest.php',
            'tests/Feature/Api/V1/ApplicationInformationRequestTest.php',
            'tests/Feature/Api/V1/ApplicationPrivacyTest.php',
            'tests/Feature/Api/V1/AuthTest.php',
            'tests/Feature/Api/V1/CVReviewContractTest.php',
            'tests/Feature/Api/V1/CVReviewFlowTest.php',
            'tests/Feature/Api/V1/CVTest.php',
            'tests/Feature/Api/V1/CompanyStateTest.php',
            'tests/Feature/Api/V1/InterviewAttendanceTest.php',
            'tests/Feature/Api/V1/InterviewLifecycleTest.php',
            'tests/Feature/Api/V1/InterviewModeTest.php',
            'tests/Feature/Api/V1/InterviewModuleTest.php',
            'tests/Feature/Api/V1/InterviewPrivacyTest.php',
            'tests/Feature/Api/V1/JobApplicationTest.php',
            'tests/Feature/Api/V1/JobPostingContractTest.php',
            'tests/Feature/Api/V1/JobScreeningQuestionTest.php',
            'tests/Feature/Api/V1/JobSkillRequirementTest.php',
            'tests/Feature/Api/V1/JobSkillWeightTest.php',
            'tests/Feature/Api/V1/JobWorkModeTest.php',
            'tests/Feature/Api/V1/ManualGradingModuleTest.php',
            'tests/Feature/Api/V1/MatchingTest.php',
            'tests/Feature/Api/V1/ProfileSourceTrackingTest.php',
            'tests/Feature/Api/V1/ProfileSuggestionTest.php',
            'tests/Feature/Api/V1/TestGradingModuleTest.php',
            'tests/Feature/Api/V1/TestModuleTest.php',
            // Approved candidate applications-page expansion.
            'app/Http/Controllers/Api/V1/Application/JobApplicationController.php',
            'app/Http/Requests/Api/V1/Application/MyJobApplicationIndexRequest.php',
            'app/Models/JobApplication.php',
            // Approved Activity-page aggregation and versioned notification feed.
            'app/Services/ApplicationPageService.php',
            'app/Services/Home/HomeActionResolver.php',
            'app/Services/NotificationService.php',
            // Approved structured Syrian-city API, matching, and CV integration.
            'app/Http/Requests/Api/V1/Auth/JobSeekerRegisterRequest.php',
            'app/Http/Requests/Api/V1/Profile/UpdateJobSeekerProfileRequest.php',
            'app/Http/Resources/Api/V1/JobPostingResource.php',
            'app/Http/Resources/Api/V1/JobSeekerProfileResource.php',
            'app/Http/Resources/Api/V1/RankedCandidateResource.php',
            'app/Http/Resources/Api/V1/RecommendedJobResource.php',
            // Approved Single Current CV contract invariant.
            'app/Models/CVFile.php',
            'app/Models/JobPosting.php',
            'app/Models/JobSeekerProfile.php',
            'app/Services/ApplicationInformationRequestService.php',
            'app/Services/ApplicationWorkflowService.php',
            'app/Services/Auth/AuthService.php',
            'app/Services/Auth/EmailVerificationService.php',
            'app/Services/Auth/RegistrationService.php',
            'app/Services/CV/CVReviewDraftService.php',
            'app/Services/CVService.php',
            'app/Services/JobPostingService.php',
            'app/Services/MatchingService.php',
            'app/Services/ProfileService.php',
            'app/Services/ProfileSyncService.php',
            'app/Services/Recommendation/RecommendationContextFingerprint.php',
            'app/Services/Recommendation/RecommendationEligibilityProvider.php',
            'app/Services/Recommendation/RecommendationOrchestrator.php',
            'app/Services/Recommendation/RecommendationResultHydrator.php',
            'app/Support/Recommendation/MlRecommendationResourceAdapter.php',
            'config/matching.php',
            'docs/MOBILE_CV_REVIEW_FLOW.md',
            'routes/api/v1.php',
            'tests/Feature/Api/V1/RecommendationEndToEndTest.php',
            'tests/Feature/Api/V1/RecommendedJobsMlTest.php',
            'tests/Unit/FinalHandoverDocumentationTest.php',
            'tests/Unit/MatchingScoreV2Test.php',
            'tests/Unit/MatchingServiceTest.php',
        ]);
        foreach ($baseline['files'] as $entry) {
            $file = base_path($entry['path']);
            $this->assertFileExists($file);
            if (! in_array($entry['path'], $approvedPhase17Documentation, true)) {
                $this->assertSame($entry['size_bytes'], filesize($file), $entry['path']);
                $this->assertSame($entry['sha256'], strtoupper(hash_file('sha256', $file)), $entry['path']);
            }
            $records[] = implode('|', [
                $entry['path'],
                $entry['size_bytes'],
                $entry['sha256'],
            ]);
            $paths[] = $entry['path'];
        }
        $sorted = $paths;
        usort($sorted, strcmp(...));
        $this->assertSame($sorted, $paths);
        $this->assertSame(count($paths), count(array_unique($paths)));
        $this->assertSame(
            $baseline['aggregate_sha256'],
            strtoupper(hash('sha256', implode("\n", $records)."\n")),
        );

        $trainer = file_get_contents(base_path(
            'services/ml-recommendation/src/smart_recruitment_ml/training/trainer.py',
        ));
        $this->assertIsString($trainer);
        $this->assertStringContainsString(
            'C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF',
            $trainer,
        );

        $artifactTest = file_get_contents(base_path(
            'services/ml-recommendation/tests/test_model_artifacts.py',
        ));
        $this->assertIsString($artifactTest);
        foreach ([
            'frozen_manifest = json.loads(frozen_bytes["manifest.json"])',
            'historical_locked_test_sha256 = str(historical_locked_test["sha256"])',
            'monkeypatch.setattr(Path, "open", reject_locked_test_open)',
            'for name in ARTIFACT_NAMES:',
        ] as $historicalReproductionContract) {
            $this->assertStringContainsString(
                $historicalReproductionContract,
                $artifactTest,
            );
        }

        $phase7ManifestPath =
            'services/ml-recommendation/data/baselines/v1/manifest.json';
        $phase7Manifest = json_decode(
            file_get_contents(base_path($phase7ManifestPath)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $reportPath =
            'services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md';
        $reportRecord = collect($phase7Manifest['output_files'])->firstWhere(
            'path',
            $reportPath,
        );
        $this->assertIsArray($reportRecord);
        $this->assertSame(
            filesize(base_path($reportPath)),
            $reportRecord['size_bytes'],
        );
        $this->assertSame(
            strtolower(hash_file('sha256', base_path($reportPath))),
            $reportRecord['sha256'],
        );

        $rootReadme = base_path('README.md');
        $this->assertFileExists($rootReadme);
        $this->assertTrue(is_readable($rootReadme));
        $readme = file_get_contents($rootReadme);
        $this->assertIsString($readme);
        foreach ([
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md',
            'docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md',
            'services/ml-recommendation/DEPLOYMENT.md',
            'docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md',
        ] as $relativePath) {
            $this->assertStringContainsString("]({$relativePath})", $readme);
        }
        $this->assertStringContainsString(
            'Production deployment has not been performed.',
            $readme,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|[\s("`])[A-Za-z]:[\\\\\/]/m',
            $readme,
        );
        $this->assertStringNotContainsString('/home/', strtolower($readme));
        $this->assertStringNotContainsString('file://', strtolower($readme));
        $this->assertDoesNotMatchRegularExpression(
            '/Bearer\s+[A-Za-z0-9._-]{16,}/i',
            $readme,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:X-ML-Service-Token|ML_SERVICE_TOKEN)\s*[:=]\s*[\'"]?(?!<|replace-|\{\{)[A-Za-z0-9._-]{8,}/i',
            $readme,
        );
    }

    public function test_phase17_e2e_matrix_schema_is_complete_and_secret_free(): void
    {
        $path = base_path(
            'docs/ml-job-recommendation/phase17/E2E_TEST_MATRIX.json',
        );
        $matrix = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('phase-17-e2e-test-matrix-v1', $matrix['matrix_version']);
        $this->assertSame(35, $matrix['scenario_count']);
        $this->assertSame(35, $matrix['passed_count']);
        $this->assertSame(0, $matrix['failed_count']);
        $this->assertCount(35, $matrix['scenarios']);

        $scenarioKeys = [
            'id',
            'category',
            'description',
            'expected_behavior',
            'result',
            'provider_calls',
            'matching_calls',
            'http_status',
            'public_engine',
            'recommendation_runs_created',
            'recommendation_items_created',
            'sensitive_data_exposed',
            'notes',
        ];
        $ids = [];
        foreach ($matrix['scenarios'] as $scenario) {
            $this->assertSame($scenarioKeys, array_keys($scenario));
            $this->assertSame('passed', $scenario['result']);
            $this->assertFalse($scenario['sensitive_data_exposed']);
            $ids[] = $scenario['id'];
        }
        $sortedIds = $ids;
        sort($sortedIds, SORT_STRING);
        $this->assertSame($sortedIds, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));

        $encoded = json_encode($matrix, JSON_THROW_ON_ERROR);
        foreach ([
            'access_token',
            'service_token',
            'authorization',
            'bearer ',
            '@example.test',
            'private fixture',
            'c:\\users\\',
            '/home/',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                strtolower($encoded),
            );
        }
    }

    public function test_phase17_harness_is_loopback_only_and_never_rebuilds_the_image(): void
    {
        $harness = file_get_contents(
            base_path('scripts/phase17/run-e2e.ps1'),
        );
        $faultServer = file_get_contents(
            base_path('scripts/phase17/fault_server.py'),
        );

        $this->assertStringContainsString(
            'workeyx/ml-recommendation:0.2.0-phase16',
            $harness,
        );
        $this->assertStringNotContainsString('docker build', strtolower($harness));
        $this->assertStringContainsString('127.0.0.1', $harness);
        $this->assertStringContainsString('finally {', $harness);
        $this->assertStringContainsString('default="127.0.0.1"', $faultServer);
        $this->assertStringNotContainsString(
            'events.write(raw_body',
            $faultServer,
        );
        $this->assertStringNotContainsString(
            'events.write(str(self.headers',
            $faultServer,
        );
    }

    /**
     * @return array{0: User, 1: list<JobPosting>, 2: list<string>}
     */
    private function canonicalFixture(): array
    {
        $user = $this->recommendationUser(
            [
                'headline' => 'Backend platform engineer',
                'summary' => 'Builds private candidate systems.',
                'phone' => '+111111111111',
            ],
            [
                'name' => 'Private Phase Seventeen Candidate',
                'email' => 'private-phase17@example.test',
                'role' => UserRole::JOB_SEEKER,
            ],
        );
        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Backend Engineer',
            'company_name' => 'Fixture Company',
            'start_date' => now()->subYears(4)->toDateString(),
            'end_date' => null,
            'is_current' => true,
            'description' => 'Builds reliable backend services.',
            'source_type' => 'manual',
        ]);
        Education::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'institution' => 'Fixture University',
            'degree' => 'bachelor',
            'field_of_study' => 'Computer Science',
            'start_date' => now()->subYears(8)->toDateString(),
            'end_date' => now()->subYears(4)->toDateString(),
            'description' => 'Synthetic education fixture.',
            'source_type' => 'manual',
        ]);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel-phase17']);
        $python = Skill::create(['name' => 'Python', 'slug' => 'python-phase17']);
        $user->jobSeekerProfile->skills()->attach($laravel, ['source_type' => 'manual']);
        $user->jobSeekerProfile->skills()->attach($python, ['source_type' => 'manual']);

        $approved = $this->recommendationCompany();
        $eligibleJobs = [];
        for ($index = 0; $index < 5; $index++) {
            $eligibleJobs[] = $this->recommendationJob($approved, [
                'title' => 'Eligible Platform Role '.$index,
                'published_at' => $index === 4 ? null : now()->subHours($index + 1),
                'application_deadline' => match ($index) {
                    0 => null,
                    1 => now(),
                    default => now()->addDays($index),
                },
            ]);
            $eligibleJobs[$index]->skills()->attach($laravel, [
                'requirement_type' => 'required',
                'weight' => 5,
            ]);
            $eligibleJobs[$index]->skills()->attach($python, [
                'requirement_type' => 'nice_to_have',
                'weight' => 2,
            ]);
        }

        $this->recommendationJob($approved, ['status' => 'draft']);
        $this->recommendationJob($approved, ['status' => 'closed']);
        $this->recommendationJob($approved, [
            'application_deadline' => now()->subSecond(),
        ]);
        foreach (['pending', 'rejected', 'suspended'] as $approval) {
            $this->recommendationJob($this->recommendationCompany($approval));
        }
        $applied = $this->recommendationJob($approved);
        JobApplication::create([
            'job_posting_id' => $applied->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::firstOrFail()->id,
            'cover_letter' => null,
            'consent_to_share_profile' => true,
        ]);

        return [
            $user,
            $eligibleJobs,
            [
                'Private Phase Seventeen Candidate',
                'private-phase17@example.test',
                '+111111111111',
            ],
        ];
    }
}
