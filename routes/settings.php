<?php

use App\Http\Controllers\Settings\BusinessController as BusinessSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
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

    // Behind RequirePassword like the security page: this lists where the
    // account is signed in and can revoke access, so an unattended browser
    // shouldn't reach it on a stale session alone.
    Route::get('settings/sessions', [SessionController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('sessions.index');

    Route::delete('settings/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
    Route::delete('settings/sessions/devices', [SessionController::class, 'destroyDevices'])->name('sessions.destroy-devices');
    Route::delete('settings/sessions/devices/{tokenId}', [SessionController::class, 'destroyDevice'])->name('sessions.destroy-device');
    Route::delete('settings/sessions/{id}', [SessionController::class, 'destroySession'])->name('sessions.destroy');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Business-wide settings: values that apply across the whole business
    // rather than to one user, so admin-only — same gate as the agent form
    // that sets the per-agent overrides these are the default for.
    Route::middleware('can:manage-users')->group(function () {
        Route::get('settings/business', [BusinessSettingsController::class, 'edit'])->name('business.edit');
        Route::patch('settings/business', [BusinessSettingsController::class, 'update'])->name('business.update');
        Route::post('settings/business/profile', [BusinessSettingsController::class, 'updateProfile'])->name('business.profile.update');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
