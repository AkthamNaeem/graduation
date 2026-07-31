<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('v1.')
    ->group(function (): void {
        require base_path('routes/api/v1.php');
        require base_path('routes/api/cv-summary.php');
    });
