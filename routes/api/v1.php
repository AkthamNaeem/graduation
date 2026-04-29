<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\CV\CVController;
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
});
