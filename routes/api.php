<?php

use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\Api\ActivityApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\InboxItemApiController;
use App\Http\Controllers\Api\PushTokenApiController;
use App\Http\Controllers\Api\ReferenceApiController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/token', [AuthApiController::class, 'token'])
        ->middleware('throttle:10,1')
        ->name('api.auth.token');

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
        Route::delete('auth/token', [AuthApiController::class, 'revoke'])->name('api.auth.revoke');
        Route::get('user', [AuthApiController::class, 'me'])->name('api.user');

        Route::post('push-tokens', [PushTokenApiController::class, 'store'])->name('api.push-tokens.store');

        Route::get('inbox-items', [InboxItemApiController::class, 'index'])->name('api.inbox-items.index');
        Route::post('inbox-items', [InboxItemApiController::class, 'store'])->name('api.inbox-items.store');
        Route::get('inbox-items/{item}', [InboxItemApiController::class, 'show'])->name('api.inbox-items.show');
        Route::post('inbox-items/{item}/approve', [InboxItemApiController::class, 'approve'])->name('api.inbox-items.approve');
        Route::post('inbox-items/{item}/retry', [InboxItemApiController::class, 'retry'])->name('api.inbox-items.retry');
        Route::post('inbox-items/{item}/remove-pii', [InboxItemApiController::class, 'removePii'])->name('api.inbox-items.remove-pii');
        Route::delete('inbox-items/{item}', [InboxItemApiController::class, 'dismiss'])->name('api.inbox-items.dismiss');

        Route::get('activities', [ActivityApiController::class, 'index'])->name('api.activities.index');
        Route::get('activities/{activity}', [ActivityApiController::class, 'show'])->name('api.activities.show');

        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('api.attachments.show');

        Route::get('reference', [ReferenceApiController::class, 'reference'])->name('api.reference');
        Route::get('stats', [ReferenceApiController::class, 'stats'])->name('api.stats');

        Route::post('ai/text-assist', [AiAssistController::class, 'textAssist'])
            ->middleware('throttle:30,1')
            ->name('api.ai.text-assist');
        Route::post('ai/transcribe', [AiAssistController::class, 'transcribe'])
            ->middleware('throttle:20,1')
            ->name('api.ai.transcribe');
    });
});
