<?php

use App\Http\Controllers\Api\V1\Application\ApplicationCVSummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'user.active'])->group(function (): void {
    Route::get('applications/{jobApplication}/cv-summary', [ApplicationCVSummaryController::class, 'show'])
        ->name('applications.cv-summary.show');
    Route::post('applications/{jobApplication}/cv-summary', [ApplicationCVSummaryController::class, 'generate'])
        ->name('applications.cv-summary.generate');
});
