<?php

use App\Http\Controllers\CallController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('chat', [ChatController::class, 'show'])->name('chat');
    Route::post('chat/messages', [MessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('chat.messages.store');
    Route::delete('chat/messages', [MessageController::class, 'destroy'])->name('chat.messages.destroy');
    Route::post('chat/calls', [CallController::class, 'start'])
        ->middleware('throttle:30,1')
        ->name('chat.calls.start');
    Route::post('chat/calls/end', [CallController::class, 'end'])->name('chat.calls.end');
});

require __DIR__.'/settings.php';
