<?php

use App\Http\Controllers\Settings\CompanionController;
use App\Http\Controllers\Settings\CompanionPhotoController;
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

    Route::get('settings/companion', [CompanionController::class, 'edit'])->name('companion.edit');
    Route::patch('settings/companion', [CompanionController::class, 'update'])->name('companion.update');
    Route::post('settings/companion/avatar', [CompanionController::class, 'updateAvatar'])->name('companion.avatar.update');
    Route::delete('settings/companion/memories', [CompanionController::class, 'destroyMemories'])->name('companion.memories.destroy');
    Route::post('settings/companion/photos', [CompanionPhotoController::class, 'store'])->name('companion.photos.store');
    Route::delete('settings/companion/photos/{photo}', [CompanionPhotoController::class, 'destroy'])->name('companion.photos.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
