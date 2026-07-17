<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\GeneratedReportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InboxItemController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\Webhooks\ResendInboundController;
use App\Http\Middleware\EnsureOnboarded;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/resend-inbound', ResendInboundController::class)
    ->name('webhooks.resend-inbound');

Route::inertia('/', 'marketing/home')->name('home');
Route::inertia('privacy', 'marketing/privacy')->name('privacy');
Route::inertia('terms', 'marketing/terms')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::middleware(EnsureOnboarded::class)->group(function () {
        Route::redirect('dashboard', '/inbox')->name('dashboard');

        Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
        Route::post('inbox', [InboxItemController::class, 'store'])->name('inbox.store');
        Route::post('inbox/{item}/approve', [InboxItemController::class, 'approve'])->name('inbox.approve');
        Route::post('inbox/{item}/retry', [InboxItemController::class, 'retry'])->name('inbox.retry');
        Route::delete('inbox/{item}', [InboxItemController::class, 'dismiss'])->name('inbox.dismiss');

        Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
        Route::put('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');

        Route::get('timeline', [TimelineController::class, 'index'])->name('timeline');
        Route::post('timeline/reset', [TimelineController::class, 'reset'])->name('timeline.reset');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('reports', [GeneratedReportController::class, 'index'])->name('reports.index');
        Route::post('reports', [GeneratedReportController::class, 'store'])->name('reports.store');
        Route::delete('reports/{report}', [GeneratedReportController::class, 'destroy'])->name('reports.destroy');

        Route::get('search', SearchController::class)->name('search');
        Route::post('ai/text-assist', [AiAssistController::class, 'textAssist'])
            ->middleware('throttle:30,1')
            ->name('ai.text-assist');
    });
});

require __DIR__.'/settings.php';
