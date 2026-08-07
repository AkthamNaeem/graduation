<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->away('https://workey-frontend.vercel.app/');
});
