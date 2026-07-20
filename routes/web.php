<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\EmailUnsubscribeController;
use App\Http\Controllers\GeneratedReportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InboxItemController;
use App\Http\Controllers\MergeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RecurrenceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\Webhooks\ResendInboundController;
use App\Http\Controllers\Webhooks\SesEventsController;
use App\Http\Controllers\Webhooks\SesInboundController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureOnboarded;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/resend-inbound', ResendInboundController::class)
    ->name('webhooks.resend-inbound');
Route::post('webhooks/ses-inbound', SesInboundController::class)
    ->name('webhooks.ses-inbound');
Route::post('webhooks/ses-events', SesEventsController::class)
    ->name('webhooks.ses-events');

Route::match(['get', 'post'], 'email/unsubscribe/{user}', EmailUnsubscribeController::class)
    ->middleware('signed')
    ->name('email.unsubscribe');

Route::inertia('/', 'marketing/home')->name('home');
Route::inertia('privacy', 'marketing/privacy')->name('privacy');
Route::inertia('terms', 'marketing/terms')->name('terms');
Route::inertia('ai', 'marketing/ai')->name('ai');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::middleware(EnsureOnboarded::class)->group(function () {
        Route::redirect('dashboard', '/inbox')->name('dashboard');

        Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
        Route::post('inbox', [InboxItemController::class, 'store'])->middleware('throttle:30,1')->name('inbox.store');
        Route::post('inbox/{item}/approve', [InboxItemController::class, 'approve'])->name('inbox.approve');
        Route::post('inbox/{item}/retry', [InboxItemController::class, 'retry'])->name('inbox.retry');
        Route::post('inbox/{item}/remove-pii', [InboxItemController::class, 'removePii'])->name('inbox.remove-pii');
        Route::delete('inbox/{item}', [InboxItemController::class, 'dismiss'])->name('inbox.dismiss');

        Route::redirect('activities', '/timeline')->name('activities.index');
        Route::put('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
        Route::post('activities/{activity}/remove-pii', [ActivityController::class, 'removePii'])->name('activities.remove-pii');

        Route::get('merges/candidates', [MergeController::class, 'candidates'])->name('merges.candidates');
        Route::post('merges/preview', [MergeController::class, 'preview'])->name('merges.preview');
        Route::post('merges/draft', [MergeController::class, 'draft'])->middleware('throttle:20,1')->name('merges.draft');
        Route::post('merges', [MergeController::class, 'store'])->name('merges.store');
        Route::post('activities/{activity}/unmerge', [MergeController::class, 'unmerge'])->name('merges.unmerge');

        Route::get('timeline', [TimelineController::class, 'index'])->name('timeline');
        Route::post('timeline/reset', [TimelineController::class, 'reset'])->name('timeline.reset');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('reports', [GeneratedReportController::class, 'index'])->name('reports.index');
        Route::post('reports', [GeneratedReportController::class, 'store'])->middleware('throttle:6,1')->name('reports.store');
        Route::post('reports/evidence-export', [GeneratedReportController::class, 'exportEvidence'])->middleware('throttle:3,10')->name('reports.evidence-export');
        Route::get('reports/{report}/download', [GeneratedReportController::class, 'download'])->name('reports.download');
        Route::delete('reports/{report}', [GeneratedReportController::class, 'destroy'])->name('reports.destroy');

        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

        Route::post('recurrences', [RecurrenceController::class, 'store'])->name('recurrences.store');
        Route::post('recurrences/{recurrence}/occurrence', [RecurrenceController::class, 'occurrence'])->name('recurrences.occurrence');
        Route::patch('recurrences/{recurrence}', [RecurrenceController::class, 'update'])->name('recurrences.update');
        Route::delete('recurrences/{recurrence}', [RecurrenceController::class, 'destroy'])->name('recurrences.destroy');

        Route::get('search', SearchController::class)->name('search');
        Route::post('ai/text-assist', [AiAssistController::class, 'textAssist'])
            ->middleware('throttle:30,1')
            ->name('ai.text-assist');
        Route::post('ai/transcribe', [AiAssistController::class, 'transcribe'])
            ->middleware('throttle:20,1')
            ->name('ai.transcribe');
        Route::post('ai/reflection-draft', [AiAssistController::class, 'reflectionDraft'])
            ->middleware('throttle:20,1')
            ->name('ai.reflection-draft');
    });

    Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('usage', [AdminController::class, 'usage'])->name('admin.usage');
    });
});

require __DIR__.'/settings.php';
