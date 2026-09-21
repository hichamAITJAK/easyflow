<?php

use App\Http\Controllers\Auth\AccountStatusController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Commissions\CommissionEntryController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Fulfillment\FulfillmentWebController;
use App\Http\Controllers\DeliveryCouriers\DeliveryAccountController;
use App\Http\Controllers\DeliveryCouriers\DeliveryCourrierConnectionController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\ParcelController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Settlements\CourierSettlementController;
use App\Http\Controllers\Stores\LightfunnelsConnectionController;
use App\Http\Controllers\Stores\ShopifyConnectionController;
use App\Http\Controllers\Stores\StoreConnectionController;
use App\Http\Controllers\Stores\StoreController;
use App\Http\Controllers\Stores\StoreepConnectionController;
use App\Http\Controllers\Stores\WooCommerceConnectionController;
use App\Http\Controllers\Stores\YouCanConnectionController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\SuperAdmin\BusinessController as SuperAdminBusinessController;
use App\Http\Controllers\SuperAdmin\CourierController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\PlatformController;
use App\Http\Controllers\SuperAdmin\QueueController;
use App\Http\Controllers\SuperAdmin\SubscriptionRequestController;
use App\Http\Controllers\Tools\ProfitCalculatorController;
use App\Http\Controllers\Users\UserController;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureActiveSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::get('account-status', [AccountStatusController::class, 'show'])->name('account-status');

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleLoginController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleLoginController::class, 'callback'])->name('auth.google.callback');
    Route::get('auth/google/complete', [GoogleLoginController::class, 'showComplete'])->name('auth.google.complete');
    Route::post('auth/google/complete', [GoogleLoginController::class, 'complete'])->name('auth.google.complete.store');
});

// Platform-level surface for onboarding and managing tenant businesses.
// Strictly separate from the tenant app: super_admin only, forbidden for
// every other role (owner/admin/agents), and never linked from tenant nav.
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'verified', 'can:manage-platform'])->group(function () {
    Route::get('/', function () {
        return to_route('super-admin.businesses.index');
    })->name('home');

    Route::prefix('businesses')->name('businesses.')->group(function () {
        Route::get('/', [SuperAdminBusinessController::class, 'index'])->name('index');
        Route::get('/create', [SuperAdminBusinessController::class, 'create'])->name('create');
        Route::post('/', [SuperAdminBusinessController::class, 'store'])->name('store');
        Route::get('/{business}', [SuperAdminBusinessController::class, 'show'])->name('show');
        Route::get('/{business}/edit', [SuperAdminBusinessController::class, 'edit'])->name('edit');
        Route::patch('/{business}', [SuperAdminBusinessController::class, 'update'])->name('update');
        Route::patch('/{business}/status', [SuperAdminBusinessController::class, 'updateStatus'])->name('status');
    });

    Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/', [SubscriptionRequestController::class, 'index'])->name('index');
        Route::patch('/{subscription}/approve', [SubscriptionRequestController::class, 'approve'])->name('approve');
        Route::patch('/{subscription}/reject', [SubscriptionRequestController::class, 'reject'])->name('reject');
    });

    // E-commerce platforms and delivery couriers are edit-only: their slugs
    // key the EcomPlatform/Courier enums that resolve each integration's
    // service class, so rows are created in code, not from the panel.
    Route::prefix('platforms')->name('platforms.')->group(function () {
        Route::get('/', [PlatformController::class, 'index'])->name('index');
        Route::get('/{platform}/edit', [PlatformController::class, 'edit'])->name('edit');
        Route::patch('/{platform}', [PlatformController::class, 'update'])->name('update');
    });

    Route::prefix('couriers')->name('couriers.')->group(function () {
        Route::get('/', [CourierController::class, 'index'])->name('index');
        Route::get('/{courier}/edit', [CourierController::class, 'edit'])->name('edit');
        Route::patch('/{courier}', [CourierController::class, 'update'])->name('update');
        Route::get('/{courier}/cities', [CourierController::class, 'cities'])->name('cities');
        // Flat name, not `cities.sync`: Wayfinder maps a nested route name
        // onto a property of the parent `cities` helper, which collides.
        Route::post('/{courier}/cities/sync', [CourierController::class, 'syncCities'])->name('sync-cities');
    });

    Route::prefix('plans')->name('plans.')->group(function () {
        Route::get('/', [PlanController::class, 'index'])->name('index');
        Route::get('/create', [PlanController::class, 'create'])->name('create');
        Route::post('/', [PlanController::class, 'store'])->name('store');
        Route::get('/{plan}/edit', [PlanController::class, 'edit'])->name('edit');
        Route::patch('/{plan}', [PlanController::class, 'update'])->name('update');
        Route::patch('/{plan}/toggle', [PlanController::class, 'toggle'])->name('toggle');
        Route::delete('/{plan}', [PlanController::class, 'destroy'])->name('destroy');
    });

    // Queue health, read from the database queue tables. Not Horizon: the
    // PRD keeps the durable queue off Redis, which is all Horizon monitors.
    Route::prefix('queue')->name('queue.')->group(function () {
        Route::get('/', [QueueController::class, 'index'])->name('index');
        Route::post('/failed/retry-all', [QueueController::class, 'retryAll'])->name('retry-all');
        Route::delete('/failed', [QueueController::class, 'flush'])->name('flush');
        Route::post('/failed/{uuid}/retry', [QueueController::class, 'retry'])->name('retry');
        Route::delete('/failed/{uuid}', [QueueController::class, 'forget'])->name('forget');
    });
});

// The block screen and the payment claim sit outside EnsureActiveSubscription
// — a blocked business must still reach them to get unblocked.
Route::middleware(['auth', 'verified', 'can:access-tenant-app'])->group(function () {
    Route::get('subscription/blocked', [SubscriptionController::class, 'blocked'])->name('subscription.blocked');
    Route::post('subscription/payment-requests', [SubscriptionController::class, 'store'])->name('subscription.payment-requests.store');
});

Route::middleware(['auth', 'verified', 'can:access-tenant-app', EnsureActiveSubscription::class])->group(function () {
    // A fulfilment agent is sent to their workspace rather than refused.
    // The dashboard is the app's default landing spot, so it is what a
    // stale bookmark, a shared link or Fortify's own fallback redirect
    // lands on — a 403 there reads as a broken account, while a redirect
    // simply puts them where they were going.
    Route::get('dashboard', function (Request $request, DashboardController $controller) {
        return $request->user()->role === UserRole::FULFILMENT_AGENT
            ? redirect()->route('fulfillment.index')
            : $controller->index($request);
    })->name('dashboard');

    Route::resource('users', UserController::class)
        ->except(['show'])
        ->middleware('can:manage-users');

    Route::middleware('can:manage-users')->group(function () {
        Route::prefix('stores')->name('stores.')->group(function () {
            Route::get('/', [StoreController::class, 'index'])->name('index');
            Route::get('/create', [StoreController::class, 'create'])->name('create');

            Route::get('/connect/youcan/callback', [YouCanConnectionController::class, 'callback'])->name('connect.youcan.callback');
            Route::get('/connect/shopify/callback', [ShopifyConnectionController::class, 'callback'])->name('connect.shopify.callback');
            Route::get('/connect/lightfunnels/callback', [LightfunnelsConnectionController::class, 'callback'])->name('connect.lightfunnels.callback');

            // Storeep has no OAuth: the merchant pastes an access token, so
            // this POSTs the credentials straight in rather than redirecting
            // out to the platform and waiting for a callback.
            Route::post('/connect/storeep', [StoreepConnectionController::class, 'store'])->name('connect.storeep.store');

            // WooCommerce is self-hosted and has no third-party OAuth flow:
            // the merchant pastes their site URL and an API key pair, so
            // this POSTs straight in as well.
            Route::post('/connect/woocommerce', [WooCommerceConnectionController::class, 'store'])->name('connect.woocommerce.store');

            Route::get('/connect/{platform}', [StoreConnectionController::class, 'redirect'])->name('connect.redirect');

            Route::get('/{store}/connected', [StoreController::class, 'connected'])->name('connected');

            // Optional onboarding step after a store connects: pick a
            // delivery courier and the pickup city. Separate from the
            // account-wide delivery-couriers screen, which manages the same
            // accounts outside any one store's connect flow.
            Route::get('/{store}/courier', [DeliveryAccountController::class, 'create'])->name('courier');
            Route::post('/{store}/courier', [DeliveryAccountController::class, 'store'])->name('courier.store');

            // Declared before the {store} delete so the literal segment is
            // matched first. Re-enters the platform's own auth flow for a
            // store whose credentials stopped working.
            Route::get('/{store}/reconnect', [StoreController::class, 'reconnect'])->name('reconnect');

            Route::delete('/{store}', [StoreController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('delivery-couriers')->name('delivery-couriers.')->group(function () {
            Route::get('/', [DeliveryCourrierConnectionController::class, 'index'])->name('index');
            Route::get('/create', [DeliveryCourrierConnectionController::class, 'create'])->name('create');
            Route::post('/', [DeliveryCourrierConnectionController::class, 'store'])->name('store');
            Route::get('/{deliveryAccount}/connected', [DeliveryCourrierConnectionController::class, 'connected'])->name('connected');
            Route::delete('/{deliveryAccount}', [DeliveryCourrierConnectionController::class, 'destroy'])->name('destroy');
        });
    });

    // The scan-driven warehouse workspace (UC-16/UC-17). Sits outside the
    // 'can:manage-users' admin block above because a fulfilment agent has
    // no admin rights; 'handle-fulfilment' is its own gate.
    //
    // The data actions are XHR, not Inertia visits: a warehouse agent scans
    // a parcel every few seconds, and a full page reload per scan would
    // tear down the live camera stream between each one.
    Route::prefix('fulfillment')->name('fulfillment.')->middleware('can:handle-fulfilment')->group(function () {
        Route::get('/', [FulfillmentWebController::class, 'index'])->name('index');
        Route::get('/summary', [FulfillmentWebController::class, 'summary'])->name('summary');
        Route::post('/scan', [FulfillmentWebController::class, 'scan'])->name('scan');
        Route::post('/confirm', [FulfillmentWebController::class, 'confirm'])->name('confirm');
        Route::get('/activity', [FulfillmentWebController::class, 'activity'])->name('activity');
        Route::post('/undo', [FulfillmentWebController::class, 'undo'])->name('undo');
    });

    Route::prefix('orders')->name('orders.')->middleware('can:use-operations-app')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/sync', [OrderController::class, 'sync'])->name('sync');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/delivery-accounts/{deliveryAccount}/cities', [OrderController::class, 'citiesForDeliveryAccount'])->name('delivery-accounts.cities');
        Route::get('/products', [OrderController::class, 'products'])->name('products');
        // Bulk routes come before the /{order} ones: matching follows
        // registration order, so /orders/bulk/status would otherwise hit
        // /orders/{order}/status with $order bound to the literal "bulk"
        // and 404 on model binding.
        Route::middleware('can:manage-users')->group(function () {
            Route::patch('/bulk/assign', [OrderController::class, 'bulkAssign'])->name('bulk-assign');
            Route::patch('/bulk/status', [OrderController::class, 'bulkStatus'])->name('bulk-status');
            Route::delete('/bulk', [OrderController::class, 'bulkDestroy'])->name('bulk-destroy');
        });

        Route::get('/{order}', [OrderController::class, 'show'])->name('show');
        Route::patch('/{order}', [OrderController::class, 'update'])->name('update');
        Route::patch('/{order}/status', [OrderController::class, 'updateStatus'])->name('status');
        Route::post('/{order}/shipment', [OrderController::class, 'createShipment'])->name('shipment');

        // Confirmation agents blacklist a caller mid-call, so this sits on
        // its own gate rather than in the admin block below.
        Route::post('/{order}/blacklist', [OrderController::class, 'blacklist'])
            ->middleware('can:blacklist-customers')
            ->name('blacklist');

        Route::middleware('can:manage-users')->group(function () {
            Route::patch('/{order}/assign', [OrderController::class, 'assign'])->name('assign');
            Route::delete('/{order}', [OrderController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('parcels')->name('parcels.')->middleware('can:use-operations-app')->group(function () {
        Route::get('/', [ParcelController::class, 'index'])->name('index');
    });

    Route::prefix('products')->name('products.')->middleware('can:use-operations-app')->group(function () {
        // Read-only: agents see the catalogue, scoped to their AgentScope
        // grants by the controller. Everything that writes sits behind
        // `manage-products` below.
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/{product}/variants', [ProductController::class, 'variants'])->name('variants');

        Route::middleware('can:manage-products')->group(function () {
            Route::get('/create', [ProductController::class, 'create'])->name('create');
            Route::get('/load-products', [ProductController::class, 'loadProducts'])->name('load-products');
            Route::post('/sync', [ProductController::class, 'sync'])->name('sync');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('/{product}/edit', [ProductController::class, 'edit'])->name('edit');
            Route::patch('/{product}', [ProductController::class, 'update'])->name('update');
            Route::patch('/{product}/test', [ProductController::class, 'markTest'])->name('test');
            Route::post('/variant-image', [ProductController::class, 'uploadVariantImage'])->name('variant-image');
            Route::post('/image', [ProductController::class, 'uploadImage'])->name('image');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('customers')->name('customers.')->middleware('can:use-operations-app')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('/blacklist', [CustomerController::class, 'blacklist'])->name('blacklist');

        // Bulk-writing client PII across the whole business is an owner's
        // action, not an agent's — same bar as the other admin-only writes.
        // The template carries no customer data, but sits behind the same
        // gate so the feature is visible only to those who can use it.
        Route::middleware('can:manage-users')->group(function () {
            Route::get('/import/template', [CustomerController::class, 'importTemplate'])->name('import.template');
            Route::post('/import', [CustomerController::class, 'import'])->name('import');
        });
    });

    Route::prefix('commission-entries')->name('commission-entries.')->group(function () {
        Route::get('/', [CommissionEntryController::class, 'index'])->name('index');
        Route::post('/invoices', [CommissionEntryController::class, 'generateInvoice'])->name('invoices.store');
        Route::post('/invoices/from-filters', [CommissionEntryController::class, 'generateInvoicesFromFilters'])->name('invoices.from-filters');
        Route::patch('/invoices/{invoice}/paid', [CommissionEntryController::class, 'markInvoicePaid'])->name('invoices.paid');
        Route::get('/invoices/{invoice}/download', [CommissionEntryController::class, 'downloadInvoice'])->name('invoices.download');
        Route::get('/invoices/{invoice}', [CommissionEntryController::class, 'showInvoice'])->name('invoices.show');
    });

    Route::prefix('settlements')->name('settlements.')->middleware('can:manage-users')->group(function () {
        Route::get('/', [CourierSettlementController::class, 'index'])->name('index');
        Route::get('/expected', [CourierSettlementController::class, 'expected'])->name('expected');
        Route::post('/', [CourierSettlementController::class, 'reconcile'])->name('reconcile');
        Route::patch('/{settlement}/dispute', [CourierSettlementController::class, 'dispute'])->name('dispute');
    });

    Route::middleware('can:manage-users')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::post('/generate', [ReportController::class, 'generate'])->name('generate');
    });

    Route::middleware('can:manage-users')->get('profit-calculator', [ProfitCalculatorController::class, 'index'])->name('profit-calculator.index');
});

require __DIR__.'/settings.php';

// routes/webhooks.php is deliberately NOT required here — it is registered
// in bootstrap/app.php with no middleware, so inbound platform webhooks skip
// the session/cookie/Inertia stack entirely.
