<?php

use App\Http\Controllers\Api\V1\Admin\AdminCompanyController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminSkillController;
use App\Http\Controllers\Api\V1\Admin\AdminTestController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Application\ApplicationInformationRequestController;
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
use App\Http\Controllers\Api\V1\Test\TestAnswerController;
use App\Http\Controllers\Api\V1\Test\TestAssignmentController;
use App\Http\Controllers\Api\V1\Test\TestAssignmentDeadlineController;
use App\Http\Controllers\Api\V1\Test\TestAttemptController;
use App\Http\Controllers\Api\V1\Test\TestAttemptQuestionController;
use App\Http\Controllers\Api\V1\Test\TestCatalogController;
use App\Http\Controllers\Api\V1\Test\TestManualGradingController;
use App\Http\Controllers\Api\V1\Test\TestQuestionController;
use App\Http\Controllers\Api\V1\Test\TestRetakeController;
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

        Route::middleware(['auth:sanctum', 'user.active'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

Route::middleware(['auth:sanctum', 'user.active'])->group(function (): void {
    Route::get('applications/{jobApplication}/information-requests', [ApplicationInformationRequestController::class, 'index'])->name('applications.information-requests.index');
    Route::get('applications/{jobApplication}/cv/download', [JobApplicationController::class, 'downloadCV'])->name('applications.cv.download');
    Route::post('applications/{jobApplication}/information-requests', [ApplicationInformationRequestController::class, 'store'])->name('applications.information-requests.store');
    Route::get('information-requests/{informationRequest}', [ApplicationInformationRequestController::class, 'show'])->name('information-requests.show');
    Route::patch('information-requests/{informationRequest}', [ApplicationInformationRequestController::class, 'update'])->name('information-requests.update');
    Route::post('information-requests/{informationRequest}/respond', [ApplicationInformationRequestController::class, 'respond'])->name('information-requests.respond');
    Route::post('information-requests/{informationRequest}/cancel', [ApplicationInformationRequestController::class, 'cancel'])->name('information-requests.cancel');
    Route::get('information-response-attachments/{attachment}/download', [ApplicationInformationRequestController::class, 'download'])->name('information-response-attachments.download');

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
            Route::patch('users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
            Route::patch('users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
            Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
            Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('reports/overview', [AdminReportController::class, 'overview'])->name('reports.overview');
            Route::get('reports/applications', [AdminReportController::class, 'applications'])->name('reports.applications');
            Route::get('reports/jobs', [AdminReportController::class, 'jobs'])->name('reports.jobs');
            Route::get('reports/cv-parsing', [AdminReportController::class, 'cvParsing'])->name('reports.cv-parsing');

            Route::get('companies', [AdminCompanyController::class, 'index'])->name('companies.index');
            Route::get('companies/{company}', [AdminCompanyController::class, 'show'])->name('companies.show');
            Route::patch('companies/{company}/approve', [AdminCompanyController::class, 'approve'])->name('companies.approve');
            Route::patch('companies/{company}/reject', [AdminCompanyController::class, 'reject'])->name('companies.reject');
            Route::patch('companies/{company}/suspend', [AdminCompanyController::class, 'suspend'])->name('companies.suspend');

            Route::get('skills', [AdminSkillController::class, 'index'])->name('skills.index');
            Route::post('skills', [AdminSkillController::class, 'store'])->name('skills.store');
            Route::patch('skills/{skill}', [AdminSkillController::class, 'update'])->name('skills.patch');
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
    Route::patch('cv/{cvFile}', [CVController::class, 'update'])->name('cv.update');
    Route::post('cv/{cvFile}/make-primary', [CVController::class, 'makePrimary'])->name('cv.make-primary');
    Route::post('cv/{cvFile}/archive', [CVController::class, 'archive'])->name('cv.archive');
    Route::post('cv/{cvFile}/restore', [CVController::class, 'restore'])->name('cv.restore');
    Route::get('cv/{cvFile}/download', [CVController::class, 'download'])->name('cv.download');
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
        Route::patch('interviews/{interview}', [InterviewController::class, 'update'])->name('interviews.patch');
        Route::post('interviews/{interview}/reschedule', [InterviewController::class, 'reschedule'])->name('interviews.reschedule');
        Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('interviews.cancel');
        Route::put('interviews/{interview}/attendance', [InterviewController::class, 'attendance'])->name('interviews.attendance');
        Route::post('interviews/{interview}/no-show', [InterviewController::class, 'noShow'])->name('interviews.no-show');
        Route::get('interviews/{interview}/status-history', [InterviewController::class, 'statusHistory'])->name('interviews.status-history');
        Route::get('interviews/{interview}/schedule-history', [InterviewController::class, 'scheduleHistory'])->name('interviews.schedule-history');
        Route::delete('interviews/{interview}', [InterviewController::class, 'destroy'])->name('interviews.destroy');
        Route::post('interviews/{interview}/complete', [InterviewController::class, 'complete'])->name('interviews.complete');
        Route::post('interviews/{interview}/evaluate', [InterviewController::class, 'evaluate'])->name('interviews.evaluate');
        Route::post('interviews/{interview}/evaluation', [InterviewController::class, 'evaluate'])->name('interviews.evaluation.store');
        Route::post('tests', [TestCatalogController::class, 'store'])->name('tests.store');
        Route::put('tests/{test}', [TestCatalogController::class, 'update'])->name('tests.update');
        Route::patch('tests/{test}', [TestCatalogController::class, 'update'])->name('tests.patch');
        Route::delete('tests/{test}', [TestCatalogController::class, 'destroy'])->name('tests.destroy');
        Route::get('tests/{test}/questions', [TestQuestionController::class, 'index'])->name('tests.questions.index');
        Route::post('tests/{test}/questions', [TestQuestionController::class, 'store'])->name('tests.questions.store');
        Route::post('tests/{test}/questions/reorder', [TestQuestionController::class, 'reorder'])->name('tests.questions.reorder');
        Route::get('tests/{test}/questions/{question}', [TestQuestionController::class, 'show'])->name('tests.questions.show');
        Route::put('tests/{test}/questions/{question}', [TestQuestionController::class, 'update'])->name('tests.questions.update');
        Route::patch('tests/{test}/questions/{question}', [TestQuestionController::class, 'update'])->name('tests.questions.patch');
        Route::delete('tests/{test}/questions/{question}', [TestQuestionController::class, 'destroy'])->name('tests.questions.destroy');
        Route::post('tests/{test}/questions/{question}/options', [TestQuestionController::class, 'storeOption'])->name('tests.questions.options.store');
        Route::post('tests/{test}/questions/{question}/options/reorder', [TestQuestionController::class, 'reorderOptions'])->name('tests.questions.options.reorder');
        Route::put('tests/{test}/questions/{question}/options/{option}', [TestQuestionController::class, 'updateOption'])->name('tests.questions.options.update');
        Route::patch('tests/{test}/questions/{question}/options/{option}', [TestQuestionController::class, 'updateOption'])->name('tests.questions.options.patch');
        Route::delete('tests/{test}/questions/{question}/options/{option}', [TestQuestionController::class, 'destroyOption'])->name('tests.questions.options.destroy');
        Route::post('tests/{testAttempt}/evaluate', [TestAttemptController::class, 'evaluate'])->name('tests.evaluate');
    });
    Route::middleware('company.approved')->group(function (): void {
        Route::get('jobs/recommended', [JobPostingController::class, 'recommended'])->name('jobs.recommended');

        Route::post('jobs/{jobPosting}/applications', [JobApplicationController::class, 'store'])->name('jobs.applications.store');
        Route::post('applications/{jobPosting}', [JobApplicationController::class, 'store'])->name('applications.store');
        Route::get('applications/my', [JobApplicationController::class, 'my'])->name('applications.my');
        Route::get('applications/{jobApplication}', [JobApplicationController::class, 'show'])->name('applications.show');
        Route::post('applications/{jobApplication}/withdraw', [JobApplicationController::class, 'withdraw'])->name('applications.withdraw');
        Route::get('my/interviews', [InterviewController::class, 'my'])->name('interviews.my');
        Route::post('interviews/{interview}/confirm', [InterviewController::class, 'confirm'])->name('interviews.confirm');
        Route::get('interviews/{interview}', [InterviewController::class, 'show'])->name('interviews.show');
        Route::get('tests', [TestCatalogController::class, 'index'])->name('tests.index');
        Route::get('tests/{test}', [TestCatalogController::class, 'show'])->name('tests.show');
        Route::get('my/tests', [TestAssignmentController::class, 'my'])->name('tests.my');
        Route::patch('test-assignments/{applicationTestAssignment}/retake-policy', [TestRetakeController::class, 'updatePolicy'])->name('test-assignments.retake-policy.update');
        Route::post('test-assignments/{applicationTestAssignment}/retake', [TestRetakeController::class, 'grant'])->name('test-assignments.retake.grant');
        Route::get('test-assignments/{applicationTestAssignment}/attempt-series', [TestRetakeController::class, 'series'])->name('test-assignments.attempt-series');
        Route::patch('test-assignments/{applicationTestAssignment}/deadline', [TestAssignmentDeadlineController::class, 'update'])->name('test-assignments.deadline.update');
        Route::get('test-assignments/{applicationTestAssignment}/deadline-history', [TestAssignmentDeadlineController::class, 'history'])->name('test-assignments.deadline-history');
        Route::post('tests/{applicationTestAssignment}/start', [TestAttemptController::class, 'start'])->name('tests.start');
        Route::post('tests/{applicationTestAssignment}/submit', [TestAttemptController::class, 'submit'])->name('tests.submit');
        Route::get('test-attempts/{testAttempt}/answers', [TestAnswerController::class, 'index'])->name('test-attempts.answers.index');
        Route::get('test-attempts/{testAttempt}/questions', [TestAttemptQuestionController::class, 'index'])->name('test-attempts.questions.index');
        Route::get('test-attempts/{testAttempt}/result', [TestAttemptController::class, 'result'])->name('test-attempts.result');
        Route::post('test-attempts/{testAttempt}/gradings/bulk', [TestManualGradingController::class, 'bulk'])->name('test-attempts.gradings.bulk');
        Route::put('test-attempts/{testAttempt}/answers/{question}/grading', [TestManualGradingController::class, 'upsert'])->name('test-attempts.answers.grading.update');
        Route::patch('test-attempts/{testAttempt}/answers/{question}/grading', [TestManualGradingController::class, 'upsert'])->name('test-attempts.answers.grading.patch');
        Route::delete('test-attempts/{testAttempt}/answers/{question}/grading', [TestManualGradingController::class, 'destroy'])->name('test-attempts.answers.grading.destroy');
        Route::post('test-attempts/{testAttempt}/answers/bulk', [TestAnswerController::class, 'bulk'])->name('test-attempts.answers.bulk');
        Route::post('test-attempts/{testAttempt}/answers/{question}/file', [TestAnswerController::class, 'upsert'])->name('test-attempts.answers.file');
        Route::get('test-attempts/{testAttempt}/answers/{question}/file', [TestAnswerController::class, 'download'])->name('test-attempts.answers.download');
        Route::put('test-attempts/{testAttempt}/answers/{question}', [TestAnswerController::class, 'upsert'])->name('test-attempts.answers.update');
        Route::patch('test-attempts/{testAttempt}/answers/{question}', [TestAnswerController::class, 'upsert'])->name('test-attempts.answers.patch');
        Route::delete('test-attempts/{testAttempt}/answers/{question}', [TestAnswerController::class, 'destroy'])->name('test-attempts.answers.destroy');
    });
});

Route::get('skills', [SkillController::class, 'index'])->name('skills.index');
Route::get('jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');
