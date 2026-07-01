<?php

use App\Http\Controllers\Api\V1\Admin\AdminCompanyController;
use App\Http\Controllers\Api\V1\Admin\AdminSkillController;
use App\Http\Controllers\Api\V1\Admin\AdminTestController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Application\JobApplicationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\CV\CVController;
use App\Http\Controllers\Api\V1\Interview\InterviewController;
use App\Http\Controllers\Api\V1\JobPosting\JobPostingController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Profile\CompanyController;
use App\Http\Controllers\Api\V1\Profile\EducationController;
use App\Http\Controllers\Api\V1\Profile\EmployerProfileController;
use App\Http\Controllers\Api\V1\Profile\ExperienceController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Profile\ProfileSkillController;
use App\Http\Controllers\Api\V1\Profile\ProfileSuggestionController;
use App\Http\Controllers\Api\V1\Skill\SkillController;
use App\Http\Controllers\Api\V1\Test\TestAssignmentController;
use App\Http\Controllers\Api\V1\Test\TestAttemptController;
use App\Http\Controllers\Api\V1\Test\TestCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::post('register/job-seeker', [RegistrationController::class, 'registerJobSeeker'])
            ->name('register.job-seeker');
        Route::post('register/employer', [RegistrationController::class, 'registerEmployer'])
            ->name('register.employer');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read.legacy');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('admin')
        ->group(function (): void {
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
            Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
            Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

            Route::get('companies', [AdminCompanyController::class, 'index'])->name('companies.index');
            Route::patch('companies/{company}/approve', [AdminCompanyController::class, 'approve'])->name('companies.approve');
            Route::patch('companies/{company}/reject', [AdminCompanyController::class, 'reject'])->name('companies.reject');
            Route::patch('companies/{company}/suspend', [AdminCompanyController::class, 'suspend'])->name('companies.suspend');

            Route::get('skills', [AdminSkillController::class, 'index'])->name('skills.index');
            Route::post('skills', [AdminSkillController::class, 'store'])->name('skills.store');
            Route::put('skills/{skill}', [AdminSkillController::class, 'update'])->name('skills.update');
            Route::delete('skills/{skill}', [AdminSkillController::class, 'destroy'])->name('skills.destroy');

            Route::get('tests', [AdminTestController::class, 'index'])->name('tests.index');
            Route::post('tests', [AdminTestController::class, 'store'])->name('tests.store');
            Route::put('tests/{test}', [AdminTestController::class, 'update'])->name('tests.update');
            Route::delete('tests/{test}', [AdminTestController::class, 'destroy'])->name('tests.destroy');
        });

    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::apiResource('profile/experiences', ExperienceController::class)
        ->names('profile.experiences');
    Route::apiResource('profile/education', EducationController::class)
        ->names('profile.education');
    Route::post('profile/skills', [ProfileSkillController::class, 'store'])->name('profile.skills.store');
    Route::delete('profile/skills/{skill}', [ProfileSkillController::class, 'destroy'])->name('profile.skills.destroy');

    Route::get('cv', [CVController::class, 'index'])->name('cv.index');
    Route::post('cv/upload', [CVController::class, 'upload'])->name('cv.upload');
    Route::get('cv/{cvFile}', [CVController::class, 'show'])->name('cv.show');
    Route::get('cv/{cvFile}/parsed', [CVController::class, 'parsed'])->name('cv.parsed');
    Route::post('cv/{cvFile}/confirm', [CVController::class, 'confirm'])->name('cv.confirm');
    Route::get('cv/{cvFile}/suggestions', [ProfileSuggestionController::class, 'index'])->name('cv.suggestions.index');
    Route::post('cv/{cvFile}/suggestions/generate', [ProfileSuggestionController::class, 'generate'])->name('cv.suggestions.generate');
    Route::post('profile/suggestions/{suggestion}/accept', [ProfileSuggestionController::class, 'accept'])->name('profile.suggestions.accept');
    Route::post('profile/suggestions/{suggestion}/reject', [ProfileSuggestionController::class, 'reject'])->name('profile.suggestions.reject');
    Route::post('profile/suggestions/apply-bulk', [ProfileSuggestionController::class, 'applyBulk'])->name('profile.suggestions.apply-bulk');

    Route::get('company', [CompanyController::class, 'show'])->name('company.show');
    Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    Route::get('employer/profile', [EmployerProfileController::class, 'show'])->name('employer.profile.show');
    Route::put('employer/profile', [EmployerProfileController::class, 'update'])->name('employer.profile.update');

    Route::middleware('company.approved')->group(function (): void {
        Route::post('jobs', [JobPostingController::class, 'store'])->name('jobs.store');
        Route::get('jobs/my', [JobPostingController::class, 'my'])->name('jobs.my');
        Route::put('jobs/{jobPosting}', [JobPostingController::class, 'update'])->name('jobs.update');
        Route::delete('jobs/{jobPosting}', [JobPostingController::class, 'destroy'])->name('jobs.destroy');
        Route::post('jobs/{jobPosting}/skills', [JobPostingController::class, 'attachSkills'])->name('jobs.skills.store');
        Route::delete('jobs/{jobPosting}/skills/{skill}', [JobPostingController::class, 'detachSkill'])->name('jobs.skills.destroy');
        Route::post('jobs/{jobPosting}/publish', [JobPostingController::class, 'publish'])->name('jobs.publish');
        Route::post('jobs/{jobPosting}/close', [JobPostingController::class, 'close'])->name('jobs.close');
        Route::get('jobs/{jobPosting}/candidates/ranked', [JobPostingController::class, 'rankedCandidates'])->name('jobs.candidates.ranked');

        Route::get('jobs/{jobPosting}/applications', [JobApplicationController::class, 'indexByJob'])->name('jobs.applications.index');
        Route::post('applications/{jobApplication}/status', [JobApplicationController::class, 'changeStatus'])->name('applications.status');
        Route::post('applications/{jobApplication}/assign-test', [TestAssignmentController::class, 'assign'])->name('applications.tests.assign');
        Route::get('applications/{jobApplication}/tests', [TestAssignmentController::class, 'indexByApplication'])->name('applications.tests.index');
        Route::post('applications/{jobApplication}/interviews', [InterviewController::class, 'store'])->name('applications.interviews.store');
        Route::get('applications/{jobApplication}/interviews', [InterviewController::class, 'indexByApplication'])->name('applications.interviews.index');
        Route::put('interviews/{interview}', [InterviewController::class, 'update'])->name('interviews.update');
        Route::delete('interviews/{interview}', [InterviewController::class, 'destroy'])->name('interviews.destroy');
        Route::post('interviews/{interview}/complete', [InterviewController::class, 'complete'])->name('interviews.complete');
        Route::post('interviews/{interview}/evaluate', [InterviewController::class, 'evaluate'])->name('interviews.evaluate');
        Route::post('tests', [TestCatalogController::class, 'store'])->name('tests.store');
        Route::put('tests/{test}', [TestCatalogController::class, 'update'])->name('tests.update');
        Route::patch('tests/{test}', [TestCatalogController::class, 'update'])->name('tests.patch');
        Route::delete('tests/{test}', [TestCatalogController::class, 'destroy'])->name('tests.destroy');
        Route::post('tests/{testAttempt}/evaluate', [TestAttemptController::class, 'evaluate'])->name('tests.evaluate');
    });
    Route::get('jobs/recommended', [JobPostingController::class, 'recommended'])->name('jobs.recommended');

    Route::post('jobs/{jobPosting}/applications', [JobApplicationController::class, 'store'])->name('jobs.applications.store');
    Route::post('applications/{jobPosting}', [JobApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/my', [JobApplicationController::class, 'my'])->name('applications.my');
    Route::get('applications/{jobApplication}', [JobApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{jobApplication}/withdraw', [JobApplicationController::class, 'withdraw'])->name('applications.withdraw');
    Route::get('my/interviews', [InterviewController::class, 'my'])->name('interviews.my');
    Route::get('interviews/{interview}', [InterviewController::class, 'show'])->name('interviews.show');
    Route::get('tests', [TestCatalogController::class, 'index'])->name('tests.index');
    Route::get('tests/{test}', [TestCatalogController::class, 'show'])->name('tests.show');
    Route::get('my/tests', [TestAssignmentController::class, 'my'])->name('tests.my');
    Route::post('tests/{applicationTestAssignment}/start', [TestAttemptController::class, 'start'])->name('tests.start');
    Route::post('tests/{applicationTestAssignment}/submit', [TestAttemptController::class, 'submit'])->name('tests.submit');
});

Route::get('skills', [SkillController::class, 'index'])->name('skills.index');
Route::get('jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');
