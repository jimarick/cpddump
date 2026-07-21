<?php

use App\Http\Controllers\Settings\AiController;
use App\Http\Controllers\Settings\CalendarController;
use App\Http\Controllers\Settings\EvidenceController;
use App\Http\Controllers\Settings\NotificationsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/evidence', [EvidenceController::class, 'edit'])->name('evidence.edit');
    Route::patch('settings/evidence', [EvidenceController::class, 'update'])->name('evidence.update');
    Route::post('settings/evidence/regenerate-address', [EvidenceController::class, 'regenerateAddress'])
        ->middleware('throttle:3,60')
        ->name('evidence.regenerate-address');
    Route::patch('settings/evidence/rules/{rule}', [EvidenceController::class, 'toggleRule'])->name('evidence.rules.toggle');
    Route::delete('settings/evidence/rules/{rule}', [EvidenceController::class, 'destroyRule'])->name('evidence.rules.destroy');

    Route::get('settings/calendars', [CalendarController::class, 'edit'])->name('calendars.edit');
    Route::post('settings/calendars', [CalendarController::class, 'store'])->name('calendars.store');
    Route::post('settings/calendars/import', [CalendarController::class, 'import'])->name('calendars.import');
    Route::post('settings/calendars/{feed}/sync', [CalendarController::class, 'sync'])->name('calendars.sync');
    Route::delete('settings/calendars/{feed}', [CalendarController::class, 'destroy'])->name('calendars.destroy');

    Route::get('settings/notifications', [NotificationsController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationsController::class, 'update'])->name('notifications.update');

    Route::get('settings/ai', [AiController::class, 'edit'])->name('ai.edit');
    Route::patch('settings/ai', [AiController::class, 'update'])->name('ai.update');
    Route::delete('settings/ai', [AiController::class, 'destroy'])->name('ai.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
