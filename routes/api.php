<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

    Route::prefix('device-tokens')->name('api.device-tokens.')->group(function () {
        Route::post('/', [DeviceTokenController::class, 'store'])->name('store');
        Route::delete('/', [DeviceTokenController::class, 'destroy'])->name('destroy');
    });
});
