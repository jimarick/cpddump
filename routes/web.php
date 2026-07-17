<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'marketing/home')->name('home');
Route::inertia('privacy', 'marketing/privacy')->name('privacy');
Route::inertia('terms', 'marketing/terms')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
