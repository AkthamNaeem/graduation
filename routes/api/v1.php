<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Application\JobApplicationController;
use App\Http\Controllers\Api\V1\CV\CVController;
use App\Http\Controllers\Api\V1\JobPosting\JobPostingController;
use App\Http\Controllers\Api\V1\Profile\CompanyController;
use App\Http\Controllers\Api\V1\Profile\EducationController;
use App\Http\Controllers\Api\V1\Profile\EmployerProfileController;
use App\Http\Controllers\Api\V1\Profile\ExperienceController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Profile\ProfileSkillController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::post('register/job-seeker', [RegistrationController::class, 'registerJobSeeker'])
            ->name('register.job-seeker');
        Route::post('register/employer', [RegistrationController::class, 'registerEmployer'])
            ->name('register.employer');
        Route::post('login', [AuthController::class, 'login'])->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

Route::middleware('auth:sanctum')->group(function (): void {
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

    Route::get('company', [CompanyController::class, 'show'])->name('company.show');
    Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    Route::get('employer/profile', [EmployerProfileController::class, 'show'])->name('employer.profile.show');
    Route::put('employer/profile', [EmployerProfileController::class, 'update'])->name('employer.profile.update');

    Route::post('jobs', [JobPostingController::class, 'store'])->name('jobs.store');
    Route::get('jobs/my', [JobPostingController::class, 'my'])->name('jobs.my');
    Route::put('jobs/{jobPosting}', [JobPostingController::class, 'update'])->name('jobs.update');
    Route::delete('jobs/{jobPosting}', [JobPostingController::class, 'destroy'])->name('jobs.destroy');
    Route::post('jobs/{jobPosting}/skills', [JobPostingController::class, 'attachSkills'])->name('jobs.skills.store');
    Route::delete('jobs/{jobPosting}/skills/{skill}', [JobPostingController::class, 'detachSkill'])->name('jobs.skills.destroy');
    Route::post('jobs/{jobPosting}/publish', [JobPostingController::class, 'publish'])->name('jobs.publish');
    Route::post('jobs/{jobPosting}/close', [JobPostingController::class, 'close'])->name('jobs.close');

    Route::post('applications/{jobPosting}', [JobApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/my', [JobApplicationController::class, 'my'])->name('applications.my');
    Route::get('applications/{jobApplication}', [JobApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{jobApplication}/withdraw', [JobApplicationController::class, 'withdraw'])->name('applications.withdraw');
    Route::get('jobs/{jobPosting}/applications', [JobApplicationController::class, 'indexByJob'])->name('jobs.applications.index');
    Route::post('applications/{jobApplication}/status', [JobApplicationController::class, 'changeStatus'])->name('applications.status');
});

Route::get('jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');
