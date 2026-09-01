<?php

use App\Http\Controllers\Api\Mobile\CommissionController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\FulfillmentController;
use App\Http\Controllers\Api\Mobile\LeadsController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\ProfileController;
use Illuminate\Support\Facades\Route;

/**
 * Mobile-only API surface (PRD section 9), kept in its own route file under
 * the /api/mobile prefix so the mobile app's token-based auth routes never
 * mix with the web app's session-based routes. Any future mobile-only
 * endpoint (order queue, scan lookup, etc.) belongs here.
 */
Route::prefix('mobile')->name('api.mobile.')->middleware('throttle:30,1')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::get('/passkey/login-options', [MobileAuthController::class, 'passkeyLoginOptions'])->name('passkey.login-options');
        Route::post('/passkey/login', [MobileAuthController::class, 'passkeyLogin'])->name('passkey.login');
        Route::post('/login', [MobileAuthController::class, 'login'])->name('login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
        });
    });

    Route::middleware('auth:sanctum')->patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Fulfillment routes
    Route::prefix('fulfillment')->middleware('auth:sanctum')->name('fulfillment.')->group(function () {
        Route::get('/summary', [FulfillmentController::class, 'summary'])->name('summary');
        Route::post('/scan', [FulfillmentController::class, 'scan'])->name('scan');
        Route::post('/confirm', [FulfillmentController::class, 'confirm'])->name('confirm');
        Route::get('/activity', [FulfillmentController::class, 'activity'])->name('activity');
        Route::post('/undo', [FulfillmentController::class, 'undo'])->name('undo');
    });

    // Confirmation agent Leads routes
    Route::middleware('auth:sanctum')->prefix('leads')->name('leads.')->group(function () {
        Route::get('/', [LeadsController::class, 'index'])->name('index');
        Route::get('/counts', [LeadsController::class, 'counts'])->name('counts');
        // Both shipment lookups sit above /{order} so their literal paths
        // aren't swallowed by the wildcard.
        Route::get('/products', [LeadsController::class, 'products'])->name('products');
        Route::get('/delivery-accounts', [LeadsController::class, 'deliveryAccounts'])->name('delivery-accounts');
        Route::get('/delivery-accounts/{deliveryAccount}/cities', [LeadsController::class, 'deliveryAccountCities'])->name('delivery-accounts.cities');
        Route::get('/{order}', [LeadsController::class, 'show'])->name('show');
        Route::patch('/{order}/status', [LeadsController::class, 'updateStatus'])->name('update-status');
        Route::patch('/{order}', [LeadsController::class, 'update'])->name('update');
        Route::post('/{order}/shipment', [LeadsController::class, 'createShipment'])->name('shipment');
    });

    // Confirmation agent Dashboard route
    Route::middleware('auth:sanctum')->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Confirmation agent Commission routes
    Route::middleware('auth:sanctum')->prefix('commission')->name('commission.')->group(function () {
        Route::get('/', [CommissionController::class, 'index'])->name('index');
        Route::get('/summary', [CommissionController::class, 'summary'])->name('summary');
    });
});
