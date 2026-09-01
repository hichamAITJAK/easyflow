<?php

use App\Enums\Courier;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeForceLogCities(): void
{
    // Mirrors the live response: the id-keyed map nested under "Cities",
    // alongside the AUTH envelope every ForceLog response carries.
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'Cities' => [
                '17' => ['CODE' => 'NDR', 'NAME' => 'Nador', 'D_FEES' => '45', 'D_FEES_SAME_CITY' => '45'],
                '34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30', 'D_FEES_SAME_CITY' => '20'],
            ],
        ], 200),
    ]);
}

test('the city sync uses the developer api key from config, with no connected account', function () {
    // The whole point of the config key: this must work before any tenant
    // has connected a ForceLog account.
    config(['services.forcelog.api_key' => 'developer-key']);
    fakeForceLogCities();

    $courier = DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value])
        ->assertSuccessful();

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(2);

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'developer-key'));
});

test('synced cities keep ForceLog\'s numeric id as the external id', function () {
    config(['services.forcelog.api_key' => 'developer-key']);
    fakeForceLogCities();

    $courier = DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value]);

    $casablanca = DeleveryCourrierCity::where('courrier_id', $courier->id)
        ->where('external_courrier_id', '34')
        ->firstOrFail();

    expect($casablanca->name)->toBe('Casablanca');
});

test('the sync is skipped, not failed, when the developer key is not configured', function () {
    // A missing optional key must not break `db:seed`, which runs this
    // command for every courier.
    config(['services.forcelog.api_key' => null]);

    DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value])
        ->expectsOutputToContain('FORCELOG_API_KEY')
        ->assertSuccessful();

    expect(DeleveryCourrierCity::count())->toBe(0);
});

test('the sync is skipped when the courier has not been seeded', function () {
    config(['services.forcelog.api_key' => 'developer-key']);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value])
        ->expectsOutputToContain('not seeded')
        ->assertSuccessful();
});

test('re-running the sync updates in place rather than duplicating', function () {
    config(['services.forcelog.api_key' => 'developer-key']);
    fakeForceLogCities();

    $courier = DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value]);
    $this->artisan('couriers:sync-cities', ['--courier' => Courier::FORCELOG->value]);

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(2);
});
