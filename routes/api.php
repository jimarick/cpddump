<?php

use App\Http\Controllers\Api\InboxItemApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('v1')->group(function () {
    Route::get('user', fn (Request $request) => $request->user()->only(['id', 'name', 'email']));

    Route::post('inbox-items', [InboxItemApiController::class, 'store'])->name('api.inbox-items.store');
});
